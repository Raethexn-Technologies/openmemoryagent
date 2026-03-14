# OpenMemoryAgent — Research Agenda

This document is the active research agenda for OpenMemoryAgent. It sits between VISION.md and DEVLOG.md in purpose: VISION.md holds stable design positions, DEVLOG.md holds timestamped discovery records, and this file holds the open questions and the work required to answer them.

The agenda is not a product backlog. Each track corresponds to a scientific claim that is either unproven, partially proven, or disproven by the current implementation. When a track closes, the finding graduates into VISION.md and a DEVLOG entry records what was learned. New tracks open when the work reveals questions that were not visible before it started.

---

## How the project builds itself

The project is discovery-driven. Each visualization gap reveals a research question. Each research question requires a measurement. Each measurement requires an endpoint or command. Each endpoint enables a new visualization that exposes the next gap. The cycle is self-generating, and the to-do list is a research agenda that rewrites itself as the work progresses.

This is not an accident of process. It is a consequence of building observability infrastructure for a system whose behavior is not fully understood. You cannot specify in advance what you need to see, because you do not yet know what the system is doing. You build the first instrument, look at what it shows, and the next question becomes visible.

---

## Track 1: Prove the topological claim

### What opened this track

The observation that cosmic structure, biological neural networks, and Physarum polycephalum networks all belong to the same mathematical class: scale-free networks with small-world topology. The scientific question is whether a memory graph organized by Physarum dynamics converges on the same topological class as the biological systems that inspired the model.

If yes, this is a three-way convergence across physics, biology, and AI memory: cosmic structure, neural connectivity, and AI memory graphs all exhibiting the same degree distribution, clustering coefficient, and path length properties. That convergence is a publishable finding, not just a metaphor.

### What needs to be built

**Degree distribution endpoint.** `GET /api/graph/topology` computes the degree distribution of the current user's memory graph and fits a power law. The fit quality (R-squared against a log-log plot) determines whether the graph is scale-free. Run this against individual agent partitions and the collective graph to compare.

**Small-world metrics endpoint.** The same endpoint or a companion computes mean clustering coefficient and mean shortest path length, then compares both against a random Erdos-Renyi graph of the same node count and edge density. A graph is small-world if clustering is significantly higher and path length is comparable to the random baseline.

**Multi-scale comparison.** Run both metrics against individual agent graphs and the collective graph separately. The question is whether the collective graph belongs to the same topological class as individual graphs at a larger scale, or whether the shared edge layer produces a structurally different object.

**Topology panel in Three.js.** A metrics overlay in the mission control surface showing degree distribution, clustering coefficient, path length, and power law fit quality as live readouts, updated on each simulation tick and scrubbable through the snapshot history.

### What this track proves when closed

That AI memory organized by Physarum dynamics self-organizes into the same topological class as neural networks and cosmic structure, or that it does not. Either result is a finding. If it does, the galaxy visualization is scientifically accurate, not decorative. If it does not, the divergence tells you something about how Physarum dynamics on discrete memory graphs differs from the continuous physical systems they are modeled on.

**Status: open**

---

## Track 2: Galaxy-accurate visualization

### What opened this track

The current Three.js surface renders clusters as heat spheres and shared edges as violet lines between partitions. It does not render the internal structure of a single agent's memory partition in a way that makes the hub-and-filament topology visible. The galaxy metaphor is scientifically precise enough that the visualization should reflect the actual structure rather than a simplified proxy for it.

### What needs to be built

**Hub node rendering.** Within a single agent partition, node radius and brightness should scale with total connected edge weight, not just degree count. High-weight hub nodes are the stars. Low-weight peripheral nodes are the dust.

**Filament rendering.** High-weight Physarum paths between hubs should be rendered as visible, thick connections. Low-weight connections should be rendered as faint, near-invisible threads. The visual contrast between a well-traveled path and a decaying one should be immediately legible without reading a weight value.

**Interstellar medium.** Connections at or near the weight floor (0.05) should render differently from connections that have accumulated meaningful weight. One approach: floor-weight edges rendered as a diffuse haze around hub nodes rather than as explicit lines, reflecting that they exist but carry no useful flux.

**Galaxy cluster rendering.** At the multi-agent level, each agent partition should read as a distinct galaxy: a self-contained structure with internal hub-and-filament layout, separated from other partitions by visible space. Shared edges are the intracluster medium connecting galaxies, rendered with thickness proportional to accumulated shared weight.

**LLM harness layer.** A separate visual layer above the graph layer representing the context assembly, retrieval, and prompt engineering that shapes which memory paths get reinforced. This is the cosmic web within which the galaxy clusters exist. It does not need to show internal LLM mechanics; it needs to show which retrieval parameters are active, which model is in use, and what the current context window limit is. These are the gravitational parameters of the harness.

### What this track proves when closed

That the mission control surface renders the actual topological structure of the memory graph accurately enough that an operator can read cluster density, hub centrality, and filament strength from the visual without consulting a data panel. The test is whether a researcher unfamiliar with the system can correctly identify the highest-weight hub nodes and the strongest inter-hub paths from the visualization alone.

**Status: open**

---

## Track 3: Society emergence and specialization

### What opened this track

The observation that agents with different conversation histories develop divergent Physarum weight distributions through differential reinforcement alone, without any assignment or coordination. This is specialization in the complexity science sense. The current alignment panel shows a point-in-time Jaccard similarity score. It does not show the trend, so you cannot see specialization happening.

### What needs to be built

**Alignment trend view.** Plot Jaccard similarity per agent pair across the snapshot history as a time series. A falling trend means the pair is specializing away from each other. A stable high score means they are working the same knowledge domain. A sudden drop after stable alignment is an anomaly worth investigating.

**Specialization detection.** A background process that flags agent pairs whose alignment trend has been falling for more than a configurable number of consecutive snapshots. Surface this as a notification or visual indicator in the Three.js scene.

**Domain ownership panel.** For each agent, show which cluster regions it primarily activates versus which it shares with other agents. Agents with high concentration in one cluster are specialists. Agents spread across many clusters are generalists. Agents with high betweenness centrality in the shared edge layer are connectors, the agents whose memory overlaps most with the largest number of peers.

**Role emergence in the scene.** Render specialists, generalists, and connectors with distinct visual identifiers in the galaxy cluster view. The topology should be self-evident from the scene: a specialist looks like a galaxy with one bright core; a generalist looks like a spiral with many arms; a connector's galaxy is positioned at the convergence of multiple filaments.

### What this track proves when closed

That specialization emerges from differential reinforcement without any assigned roles, and that it is detectable and visually legible from the mission control surface. The scientific claim is that the society structure (who knows what, who connects whom) is a property of the collective Physarum dynamics, not of any design decision made when creating the agents.

**Status: open**

---

## Track 4: Emergent collective memory experiment

### What opened this track

The core unproven claim of the multi-agent layer: that the collective Physarum graph encodes knowledge at the group level that no individual agent holds. This is emergent collective memory in the strict sense. It is currently a hypothesis supported by the architecture but not yet tested by a controlled experiment.

### What needs to be built

**Domain seeding tool.** A seeding interface that seeds agents with genuinely non-overlapping subsets of a structured knowledge domain, rather than random samples of the owner's public nodes. The controlled starting condition is essential: if agents begin with overlapping content, the experiment cannot isolate emergent paths from individually-established ones.

**Emergence detection.** After a run of simulation ticks, compare the collective cluster topology against the union of individual cluster topologies weighted by each agent's trust score. Identify clusters in the collective graph that are absent from every individual graph at the start of the run and appear during it.

**Emergence timeline.** For each emergent cluster, trace which agents contributed the cross-reinforcement events that first established it, and at which simulation tick. This is the moment of emergence: a path that neither agent had established alone but that the collective reinforcement created. The temporal axis scrubber makes this traceable in the Three.js surface.

**Experiment report endpoint.** `GET /api/agents/emergence-report` returns the list of emergent clusters, the contributing agents, the emergence timestamps, and the current weight of each emergent cluster. This is the primary scientific output of the experiment.

### What this track proves when closed

That the collective Physarum graph either does or does not produce memory structures that exist only at the group level. If emergent clusters appear, the claim is proven: the society knows something that no individual in it knows, encoded as high-weight paths through content that no agent accessed frequently enough alone to establish. If no emergent clusters appear, collective Physarum is individual Physarum with shared bookkeeping, which is still a useful operational architecture but makes a weaker scientific claim.

**Status: open**

---

## Track 5: Closing the observability gaps

### What opened this track

The five-layer analysis in DEVLOG Entry 012. Layer 1 (what entered context) is solved by `active_node_ids`. The remaining four layers are open gaps between what the system records and what an operator needs to know.

### Layer 2: Was the memory actually used?

The `active_node_ids` field identifies what entered the context window. It does not prove the model attended to those records. The zkTAM framework (Kinic, 2025) applies zero-knowledge proofs to prove a response was conditioned on specific verified records. The precondition for zkTAM is exactly what `active_node_ids` provides.

What needs to be built first: a controlled experiment using the existing infrastructure. Same conversation, same model, same turn, memory context present versus absent. Compare response quality on a held-out evaluation set. If responses with memory present score meaningfully higher, the retrieval system is doing useful work and the zkTAM investment is justified. If scores are indistinguishable, the memory graph may be adding latency without improving outputs, and the correct next step is improving retrieval relevance before adding proof infrastructure.

**Status: experiment design needed**

### Layer 3: What caused the reinforcement?

When a cluster is reinforced, the edge weight records that those nodes were co-accessed. It does not record whether they were causally relevant to the response. Physarum weights track access frequency, not influence.

This gap is not solvable with the current LLM API surface. Attention distributions over the context window are not exposed by any major provider. Logging `active_node_ids` and comparing response quality with and without those nodes (Layer 2 experiment) is the closest available proxy. Document this as a hard limit of the current observability surface.

**Status: blocked on LLM API exposure, documented as hard limit**

### Layer 4: Iteration depth

No visibility into how many internal steps, tool calls, or retries an agent took before producing a response. This requires structured execution tracing at the orchestration layer, not at the memory graph layer. It is a separate instrument that logs agent execution traces and connects them to the memory graph via the turn ID.

What needs to be built: a trace log model that records turn ID, agent ID, step count, and tool calls per turn. Connect trace records to the `active_node_ids` field so the operator can see both what was accessed and how many steps it took to arrive at the response.

**Status: open, separate from memory graph**

### Layer 5: Cross-canister memory coherence

Memories written to different ICP canisters at different times have no global index. The `supersedes` and `contradicts` edge types exist in the graph schema for within-partition contradiction tracking. Cross-canister coherence requires a different approach: an ICP canister that maintains a signed index of graph fingerprints per principal, allowing contradiction detection across partitions.

This is the longest time-scale problem on the list and the one that requires ICP infrastructure beyond what is currently deployed.

**Status: open, ICP infrastructure required**

---

## Visualization as the primary research instrument

Every track above has a visualization component. This is deliberate. The scientific claims this project is making are about emergent structure in a dynamic system. Emergent structure is not legible from a table of numbers. It requires a rendering that makes the topology visible at the right scale, with the right level of detail, at the right moment in time.

The Three.js mission control surface is not a dashboard added on top of the research. It is the primary instrument through which the research findings become observable. The galaxy visualization, the filament rendering, the alignment trend, the emergence timeline, and the topology metrics overlay are all parts of the same instrument. Building them in isolation from the scientific questions they answer produces a demo. Building them in response to specific observability gaps produces a research instrument.

The order of work follows from this: build the measurement first, then build the visualization that makes the measurement legible, then read what the visualization reveals, then open the next track.

---

## Closed tracks

None yet. This agenda opened on 2026-03-13.

---

*This document is updated when tracks open, when their scope changes, and when they close. Closed track findings move into VISION.md. The discovery record for each track lives in the corresponding DEVLOG entry.*
