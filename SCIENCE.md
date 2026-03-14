# The Science Behind OpenMemoryAgent

This document explains the mathematical models and biological principles that
the memory graph implements, in plain terms. No prior background in biology,
graph theory, or information theory is assumed. Each section states what the
idea is, where it comes from, what the formula does, and how it shows up in
the code. The test file `tests/Feature/PhysarumMathTest.php` contains
executable proofs of each formula described here.

---

## 1. Physarum polycephalum: the slime mold that builds train networks

**The source:** Tero, A. et al. (2010). "Rules for Biologically Inspired
Adaptive Network Design." *Science* 327, 439-442.
doi:10.1126/science.1177894

**What it is.** Physarum polycephalum is a single-celled organism — a slime
mold — that can spread across surfaces in search of food. When researchers
placed oat flakes at positions matching Tokyo's train stations and let the
organism grow, it produced a network of tubes connecting the food sources. The
resulting network closely matched the actual Tokyo rail system in both
efficiency and fault tolerance. The organism found a near-optimal transport
network without any central planning or global information.

**Why this is remarkable.** The slime mold uses only local information: each
tube thickens when it carries more flux (food flow) and thins when it carries
less. This local rule, applied repeatedly across all tubes simultaneously,
produces globally efficient structure. The algorithm is:

```
If a tube carries high flux → make it thicker (more conductance)
If a tube carries low flux  → make it thinner (less conductance)
```

**The mathematical formula.** The continuous conductance update is:

```
D_ij(t + dt) = ( |Q_ij(t)| / (L_ij * mu) ) * D_ij(t)
```

Where:
- `D_ij` is the conductance of the tube connecting nodes i and j
- `Q_ij` is the flux (flow rate) through that tube
- `L_ij` is the tube length
- `mu` is the decay constant

In plain terms: conductance grows when flux is high and shrinks when flux is
low. Tubes the organism uses heavily become highways; tubes it ignores become
capillaries.

**Our discrete approximation.** The original formula is continuous and
requires solving a system of equations at each time step. This project uses a
simpler discrete form that preserves the qualitative behaviour:

```
Reinforcement: w(t+1) = min(1.0, w(t) + ALPHA)    where ALPHA = 0.10
Decay:         w(t+1) = max(FLOOR, w(t) * RHO)     where RHO = 0.97, FLOOR = 0.05
```

- **Reinforcement** happens when two memory nodes are loaded into the same LLM
  context window on the same turn. This is the "flux" signal: those two nodes
  were used together, so the edge between them grows.
- **Decay** happens once per day via `php artisan memory:decay`. Every edge
  loses 3% of its weight each day it goes unreinforced. This is the "thin out"
  mechanism: paths the agent stops using fade toward the floor.
- **The floor** (0.05) means edges never fully disappear. A dormant connection
  retains a minimum conductance, the same way synaptic connections are not
  deleted but merely weakened.

**Where it appears in the code.** `MemoryGraphService::reinforce()` applies
the reinforcement formula. `MemoryGraphService::decay()` applies the decay
formula. The constants ALPHA, RHO, and WEIGHT_FLOOR are defined in
`MemoryGraphService`.

**Tests that verify this.** `PhysarumMathTest::test_single_reinforcement_increments_weight_by_alpha`,
`test_reinforcement_cannot_exceed_ceiling_of_one`,
`test_single_decay_step_multiplies_weight_by_rho`,
`test_decay_cannot_fall_below_floor_of_zero_point_zero_five`.

---

## 2. Hebbian learning: neurons that fire together wire together

**The source:** Hebb, D.O. (1949). *The Organisation of Behavior: A
Neuropsychological Theory.* Wiley. Chapter 4.

**What it is.** Donald Hebb proposed in 1949 that synaptic connections
strengthen when two neurons activate at the same time. The informal summary —
"neurons that fire together wire together" — captures the mechanism: repeated
co-activation produces a structural change in the connection between cells.
This is now understood as a mechanism for memory formation in the brain.

**How this applies here.** Each pair of memory nodes is connected by an edge.
When both nodes are loaded into the LLM context window on the same turn, they
are "co-activated": the LLM processes them simultaneously. The edge weight
increment (ALPHA = 0.10) is the discrete implementation of Hebbian
strengthening. Memory pairs the LLM finds relevant together repeatedly
accumulate high edge weight. Memory pairs it never retrieves together stay at
or near the floor.

**What this means for retrieval.** After many turns, the edge weights encode
which memory pairs the LLM has found useful together, not just which memories
exist. Graph-guided retrieval uses these weights to select which memories to
inject into the next context window, so the highest-weight neighbourhood is
retrieved first. The graph becomes a relevance index built from usage.

---

## 3. How many days until an edge reaches the floor?

**The formula.** Starting from initial weight w_0, an edge with no
reinforcement reaches the floor after n* decay steps:

```
n* = ceil( log(FLOOR / w_0) / log(RHO) )
   = ceil( log(0.05 / w_0) / log(0.97) )
```

**Reference table.** These values assume RHO=0.97 and FLOOR=0.05:

| Starting weight | Days to reach floor |
|---|---|
| 1.0 (fully reinforced) | 99 days |
| 0.5 (moderately used) | 76 days |
| 0.3 (occasionally used) | 59 days |
| 0.1 (rarely used) | 23 days |

A memory pair that the LLM accessed intensively six months ago and has not
accessed since will have decayed to the floor by now. A pair accessed last week
will still have meaningful weight. The graph is a recency-weighted relevance
index, not a flat archive.

**The test that verifies this.** `PhysarumMathTest::test_decay_reaches_floor_at_mathematically_predicted_step`
and `test_closed_form_decay_convergence_table` both verify the closed-form
formula against the four reference values above. If RHO or FLOOR changes, both
tests will fail with a message asking you to update this table.

---

## 4. Jaccard similarity: measuring overlap between two sets

**The source:** Jaccard, P. (1901). "Etude comparative de la distribution
florale dans une portion des Alpes et des Jura." *Bulletin de la Societe
Vaudoise des Sciences Naturelles*, 37, 547-579.

**What it is.** Jaccard similarity measures how much two sets overlap. The
formula is:

```
J(A, B) = |A ∩ B| / |A ∪ B|
```

Where:
- `|A ∩ B|` is the number of elements in both sets (intersection)
- `|A ∪ B|` is the number of elements in either set (union)

The result is always between 0.0 (no overlap) and 1.0 (identical sets).

**Example.** If agent A's active memories are {topic_1, topic_2, topic_3} and
agent B's active memories are {topic_2, topic_3, topic_4}:

```
Intersection = {topic_2, topic_3}         |∩| = 2
Union        = {topic_1, topic_2, topic_3, topic_4}  |∪| = 4
J(A, B)      = 2 / 4 = 0.50
```

**How this applies here.** The intent alignment panel shows Jaccard similarity
between the active content sets of each pair of agents. "Active content" means
the SHA-256 hashes of memory content strings that each agent retrieved in its
most recent simulation tick. Using content hashes rather than node UUIDs is
essential: two agents hold the same memory content in different graph
partitions under different node IDs, so UUID comparison always produces zero
even when agents have retrieved identical memories.

A falling Jaccard trend over time means two agents are specialising into
different knowledge domains. A rising trend means they are converging on shared
knowledge. Stable high Jaccard means they are working the same territory.

**The tests that verify this.** `PhysarumMathTest` includes five Jaccard tests
covering identical sets (J=1.0), disjoint sets (J=0.0), partial overlaps, and
empty sets. Each test includes the intersection and union arithmetic in the
docblock so the result can be checked by hand.

---

## 5. Community detection: label propagation

**The source:** Raghavan, U., Albert, R., Kumara, S. (2007). "Near linear time
algorithm to detect community structures in large-scale networks." *Physical
Review E*, 76, 036106. doi:10.1103/PhysRevE.76.036106

**What it is.** A community in a graph is a group of nodes that are more
strongly connected to each other than to the rest of the graph. Detecting
communities reveals the natural clusters of a memory graph: groups of memory
nodes that are frequently co-activated and therefore have high edge weight
between them.

**How label propagation works.**

```
Step 1: Assign each node its own unique label.
Step 2: For each node (in random order), update its label to the label
        most common among its neighbours, weighted by edge weight.
        On ties, choose the lexicographically smallest label.
Step 3: If any node changed label, go to Step 2.
Step 4: Nodes sharing the same label form one community.
```

The algorithm is fast (near-linear time in the number of edges) and requires
no prior knowledge of how many communities exist. It typically converges in
5-20 iterations.

**Determinism.** The original Raghavan et al. algorithm uses random node
ordering, which can produce different community assignments on the same graph
across different runs. This project replaces the random shuffle with a
deterministic sort by node UUID at each iteration, so the same graph always
produces the same clusters. This is important for the temporal axis scrubber:
cluster IDs must be stable across snapshots to track a cluster over time.

**Where it appears in the code.** `ClusterDetectionService::detect()` runs
weighted label propagation. `TakeGraphSnapshot` runs it every 15 minutes and
stores the result in `graph_snapshots`. The Three.js surface reads the snapshot
history to render the temporal axis.

**The test that verifies this.** `ClusterDetectionServiceTest::test_detect_is_deterministic_for_the_same_graph_state`
calls `detect()` twice on the same graph and asserts identical output.

---

## 6. MemoryGraft resistance: trust-weighted collective reinforcement

**The source.** MemoryGraft attack vector described in: (2024).
"MemoryGraft: Poisoning Long-Term Memory in Autonomous AI Agents."
arXiv:2512.16962.

**What the attack is.** When multiple agents share a memory graph, a
malicious agent can shift the collective graph toward content it wants the
group to prefer. It does this by repeatedly reinforcing edges connected to the
poisoned content, inflating their weight until retrieval systems preferentially
return those nodes. This is memory poisoning at the graph level.

**How the trust mechanism resists it.** The shared edge increment is:

```
increment = SHARED_ALPHA * trust_score
          = 0.06 * trust_score
```

Where `trust_score` is a value between 0.0 and 1.0 assigned to each agent by
the owner. A new or untrusted agent has trust_score=0.0, so its reinforcement
events contribute zero weight to shared edges regardless of how many times it
fires them. As the agent demonstrates reliable behaviour over time, the owner
raises its trust score and its contributions scale up proportionally.

This is a continuous gate, not a binary block. An agent with trust_score=0.5
contributes exactly half the reinforcement of a fully trusted agent per
co-access event. The resistance is proportional to distrust.

**The test that verifies this.** `PhysarumMathTest::test_zero_trust_agent_does_not_increase_shared_edge_weight`
and `test_trust_weighted_alpha_scales_linearly` both verify this property.

---

## 7. Scale-free networks and the topological claim

**The source:** Barabasi, A.L., Albert, R. (1999). "Emergence of Scaling in
Random Networks." *Science* 286, 509-512. doi:10.1126/science.286.5439.509

**What a scale-free network is.** In most random graphs, nodes have roughly
similar numbers of connections (a bell-curve degree distribution). In a
scale-free network, a few nodes have very many connections (hubs) and most
nodes have very few. The degree distribution follows a power law:

```
P(k) ~ k^(-gamma)
```

Where P(k) is the probability that a randomly chosen node has degree k, and
gamma is typically between 2 and 3. This pattern appears in the World Wide Web,
citation networks, protein interaction networks, metabolic networks, and the
human connectome.

**The topological claim.** Physarum polycephalum networks, biological neural
networks, and cosmic large-scale structure all belong to this class. If the
memory graph organised by Physarum dynamics also produces a power-law degree
distribution, it belongs to the same topological class as the systems that
inspired it. RESEARCH.md Track 1 describes the experiment that will test this.

**Small-world networks.** A related property defined by Watts and Strogatz
(1998): high clustering coefficient (nodes in the same community connect to
each other densely) combined with short average path length between any two
nodes. The human brain exhibits small-world structure. So does the Tokyo rail
network that Physarum reconstructed. If the memory graph does too, the analogy
between cosmic structure, neural structure, and AI memory structure is
mathematically grounded, not decorative.

---

## 8. Running the simulation

The command `php artisan simulate:day` seeds a realistic 8-hour workday of
memory activity without requiring an OpenRouter API key or a live ICP canister.
It creates memory nodes across four topic clusters, wires edges based on tag
overlap, runs six Physarum reinforcement turns simulating different work
contexts, creates three agents (Nexus at trust=0.90, Beacon at trust=0.75,
Ghost at trust=0.25), seeds each agent partition, and runs collective
reinforcement to establish shared edges. The result is a graph dense enough to
show meaningful cluster structure in the Three.js surface.

```bash
php artisan simulate:day           # create 40 memories (default)
php artisan simulate:day --fresh   # wipe existing demo data first
php artisan simulate:day --memories=60  # denser graph
```

After the command completes, all five surfaces have data to render:
`/chat`, `/memory`, `/graph`, `/agents`, and `/3d`.

---

## 9. Running the math tests

```bash
cd app
php artisan test --filter PhysarumMathTest
```

The test file is `tests/Feature/PhysarumMathTest.php`. Each test method
includes the mathematical formula it verifies, the source citation, and enough
arithmetic in the docblock to check the expected value by hand. If any test
fails after changing a constant (ALPHA, RHO, FLOOR, or SHARED_ALPHA), the
failure message will tell you which table or formula in this document needs to
be updated.

---

*The mathematics here is not decorative. Each formula is the reason the code
behaves the way it does. If you change a constant and the tests still pass, the
constants are self-consistent. If the tests fail, the constant change broke a
mathematical property that the rest of the system depends on.*
