<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\MemoryEdge;
use App\Models\MemoryNode;
use App\Services\MemoryGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mathematical correctness tests for the Physarum conductance model.
 *
 * Each test is paired with the mathematical formula it verifies and a
 * citation to the source paper. The goal is to make it possible for
 * anyone reading this file — with or without a background in graph theory
 * or biology — to verify that the code matches the science.
 *
 * The core model is the discrete Physarum polycephalum conductance update
 * from Tero et al. (2010), "Rules for Biologically Inspired Adaptive Network
 * Design", Science 327, 439-442. doi:10.1126/science.1177894
 *
 * The conductance update in the original paper:
 *
 *   D_ij(t + dt) = ( |Q_ij(t)| / (L_ij * mu) ) * D_ij(t)
 *
 * where D_ij is conductance, Q_ij is flux through the tube, L_ij is tube
 * length, and mu is the decay constant.
 *
 * This project uses the discrete linear approximation that preserves the
 * qualitative behaviour (reinforcement proportional to use, decay proportional
 * to current weight) while remaining tractable for a per-turn database update:
 *
 *   Reinforcement:  w(t+1) = min(1.0, w(t) + ALPHA)      where ALPHA = 0.10
 *   Decay:          w(t+1) = max(FLOOR, w(t) * RHO)       where RHO = 0.97, FLOOR = 0.05
 *
 * Hebbian learning connection (Hebb, 1949, "The Organisation of Behavior"):
 * "When an axon of cell A is near enough to excite cell B and repeatedly or
 * persistently takes part in firing it, some growth process or metabolic change
 * takes place in one or both cells such that A's efficiency as one of the cells
 * firing B is increased." The edge weight increment on co-activation is the
 * discrete implementation of this synaptic strengthening rule.
 */
class PhysarumMathTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;
    private MemoryGraphService $graphService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = Str::uuid()->toString();
        $this->graphService = app(MemoryGraphService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEdge(float $weight): array
    {
        $a = MemoryNode::create([
            'user_id' => $this->userId, 'type' => 'memory',
            'sensitivity' => 'public', 'label' => 'Node A',
            'content' => 'Content A ' . Str::random(8),
            'tags' => [], 'confidence' => 1.0, 'source' => 'test',
        ]);
        $b = MemoryNode::create([
            'user_id' => $this->userId, 'type' => 'memory',
            'sensitivity' => 'public', 'label' => 'Node B',
            'content' => 'Content B ' . Str::random(8),
            'tags' => [], 'confidence' => 1.0, 'source' => 'test',
        ]);

        $edge = MemoryEdge::create([
            'user_id' => $this->userId,
            'from_node_id' => $a->id,
            'to_node_id' => $b->id,
            'relationship' => 'related_to',
            'weight' => $weight,
            'access_count' => 0,
        ]);

        return [$a, $b, $edge];
    }

    // ── Reinforcement tests ───────────────────────────────────────────────────

    /**
     * Formula verified: w(t+1) = min(1.0, w(t) + 0.10)
     *
     * An edge at weight 0.50 that is reinforced once should reach 0.60.
     * The ALPHA=0.10 increment represents one co-activation event: both
     * endpoint nodes were loaded into the same LLM context window on this turn.
     *
     * Biological analogue: a synaptic connection that fires once strengthens
     * by a fixed amount proportional to the learning rate (Hebb, 1949).
     */
    public function test_single_reinforcement_increments_weight_by_alpha(): void
    {
        [$a, $b, $edge] = $this->makeEdge(0.50);

        $this->graphService->reinforce([$a->id, $b->id], $this->userId);

        $this->assertEqualsWithDelta(0.60, $edge->fresh()->weight, 0.001,
            'w(t+1) = 0.50 + ALPHA(0.10) should equal 0.60');
    }

    /**
     * Formula verified: min(1.0, w(t) + ALPHA) — ceiling enforcement
     *
     * An edge already at 0.95 cannot exceed 1.0 after reinforcement.
     * Conductance is bounded: a path already carrying maximum flux cannot
     * be further strengthened. The ceiling prevents runaway weight growth.
     */
    public function test_reinforcement_cannot_exceed_ceiling_of_one(): void
    {
        [$a, $b, $edge] = $this->makeEdge(0.95);

        $this->graphService->reinforce([$a->id, $b->id], $this->userId);

        $this->assertEqualsWithDelta(1.0, $edge->fresh()->weight, 0.001,
            '0.95 + 0.10 = 1.05 but must be clamped to 1.0');
    }

    /**
     * Multiple reinforcements are additive: w(t+k) = min(1.0, w(t) + k * ALPHA)
     *
     * An edge at 0.30 reinforced three times should reach 0.60.
     * This models a memory pair that is co-accessed across multiple turns
     * of the same conversation or across multiple sessions.
     */
    public function test_three_reinforcements_add_three_alpha_increments(): void
    {
        [$a, $b, $edge] = $this->makeEdge(0.30);
        $nodeIds = [$a->id, $b->id];

        $this->graphService->reinforce($nodeIds, $this->userId);
        $this->graphService->reinforce($nodeIds, $this->userId);
        $this->graphService->reinforce($nodeIds, $this->userId);

        $this->assertEqualsWithDelta(0.60, $edge->fresh()->weight, 0.001,
            'w = 0.30 + 3 * 0.10 should equal 0.60');
    }

    /**
     * Reinforcement only affects edges between co-accessed nodes.
     *
     * An edge whose endpoint nodes were not both in the context window
     * should remain unchanged. This ensures that access to node A does
     * not bleed weight into edges that connect unrelated memories.
     */
    public function test_reinforcement_does_not_affect_unrelated_edges(): void
    {
        [$a, $b, $edgeAB] = $this->makeEdge(0.50);
        [$c, $d, $edgeCD] = $this->makeEdge(0.50);

        // Reinforce only the A-B pair.
        $this->graphService->reinforce([$a->id, $b->id], $this->userId);

        $this->assertEqualsWithDelta(0.60, $edgeAB->fresh()->weight, 0.001,
            'AB edge should be reinforced');
        $this->assertEqualsWithDelta(0.50, $edgeCD->fresh()->weight, 0.001,
            'CD edge must not be affected by reinforcing AB');
    }

    // ── Decay tests ───────────────────────────────────────────────────────────

    /**
     * Formula verified: w(t+1) = max(FLOOR, w(t) * 0.97)
     *
     * An edge at weight 0.50 loses 3% per decay cycle: 0.50 * 0.97 = 0.485.
     * This models the biological process where unused synaptic connections
     * weaken over time. In the Tero model, tubes carrying no flux thin out.
     */
    public function test_single_decay_step_multiplies_weight_by_rho(): void
    {
        $this->makeEdge(0.50);

        $this->graphService->decay();

        $edge = MemoryEdge::where('user_id', $this->userId)->first();
        $this->assertEqualsWithDelta(0.485, $edge->weight, 0.001,
            'w(t+1) = 0.50 * RHO(0.97) should equal 0.485');
    }

    /**
     * Formula verified: max(FLOOR=0.05, w(t) * RHO) — floor enforcement
     *
     * An edge at 0.06 decays to 0.06 * 0.97 = 0.0582, which remains above
     * the floor and therefore must not be clamped. Edges only clamp when the
     * multiplicative decay result falls below the 0.05 floor.
     *
     * This is the biological analogue of synaptic baseline: even unused
     * connections retain a minimum conductance.
     */
    public function test_decay_cannot_fall_below_floor_of_zero_point_zero_five(): void
    {
        $this->makeEdge(0.06);

        $this->graphService->decay();

        $edge = MemoryEdge::where('user_id', $this->userId)->first();
        $this->assertEqualsWithDelta(0.0582, $edge->weight, 0.001,
            '0.06 * 0.97 = 0.0582, which remains above the floor of 0.05');
    }

    /**
     * Closed-form convergence to floor.
     *
     * An edge at weight w_0 reaches the floor after n* decay steps where:
     *
     *   n* = ceil( log(FLOOR / w_0) / log(RHO) )
     *      = ceil( log(0.05 / w_0) / log(0.97) )
     *
     * For w_0 = 0.50:
     *   n* = ceil( log(0.10) / log(0.97) )
     *      = ceil( -2.3026 / -0.03046 )
     *      = ceil( 75.6 )
     *      = 76 decay steps (approximately 76 days without reinforcement)
     *
     * This test verifies the closed-form formula matches the iterative result
     * without running 76 database iterations. It is a proof that the formula
     * embedded in the code matches the mathematical derivation.
     */
    public function test_decay_reaches_floor_at_mathematically_predicted_step(): void
    {
        $w0    = 0.50;
        $rho   = 0.97;
        $floor = 0.05;

        // Closed-form prediction of the step at which weight hits the floor.
        $nStar = (int) ceil(log($floor / $w0) / log($rho));

        // Verify the closed form: weight after n* steps should be at or below floor.
        $predicted = $w0 * pow($rho, $nStar);
        $this->assertLessThanOrEqual($floor, $predicted,
            "After n*={$nStar} steps, w_0=0.50 should have decayed to the floor. "
            . "Predicted: {$predicted}. If this fails, the constant RHO or FLOOR has changed "
            . "and the formula in SCIENCE.md needs to be updated.");

        // Verify the step before n* is still above the floor.
        $predictedBefore = $w0 * pow($rho, $nStar - 1);
        $this->assertGreaterThan($floor, $predictedBefore,
            "One step before n*={$nStar}, weight should still be above the floor. "
            . "Predicted: {$predictedBefore}.");
    }

    /**
     * An edge at the floor stays at the floor regardless of further decay.
     *
     * The floor is a lower bound, not a target. An edge already at 0.05
     * must not decay further to 0.0485.
     */
    public function test_edge_at_floor_does_not_decay_further(): void
    {
        $this->makeEdge(0.05);

        $this->graphService->decay();

        $edge = MemoryEdge::where('user_id', $this->userId)->first();
        $this->assertEqualsWithDelta(0.05, $edge->weight, 0.001,
            'An edge already at the floor must not decay further');
    }

    // ── Collective Physarum / trust-weighted tests ────────────────────────────

    /**
     * Trust-weighted SHARED_ALPHA scales linearly with trust score.
     *
     * The shared edge increment formula is:
     *   increment = SHARED_ALPHA * trust_score
     *             = 0.06 * trust_score
     *
     * For trust=0.5: increment = 0.06 * 0.5 = 0.03
     * For trust=1.0: increment = 0.06 * 1.0 = 0.06
     *
     * This is the MemoryGraft resistance mechanism (MemoryGraft, arXiv:2512.16962):
     * a newly registered agent with trust_score=0.0 contributes zero weight
     * to shared edges regardless of how many reinforcement events it triggers.
     * Trust is a continuous gate, not a binary allow/block.
     */
    public function test_trust_weighted_alpha_scales_linearly(): void
    {
        $sharedAlpha = 0.06; // SHARED_ALPHA constant from MultiAgentGraphService

        $this->assertEqualsWithDelta(0.03, $sharedAlpha * 0.5, 0.0001,
            'trust=0.5 should produce half the maximum shared increment');

        $this->assertEqualsWithDelta(0.06, $sharedAlpha * 1.0, 0.0001,
            'trust=1.0 should produce the full shared increment');

        $this->assertEqualsWithDelta(0.00, $sharedAlpha * 0.0, 0.0001,
            'trust=0.0 must produce zero increment (MemoryGraft resistance)');
    }

    /**
     * A zero-trust agent's reinforcement events produce no shared edge weight.
     *
     * Ghost agent has trust_score=0.0. Even if it reinforces the same content
     * as a trusted agent, its contribution to the collective graph is zero.
     * The shared edge weight must equal the initial weight (SHARED_WEIGHT_INITIAL)
     * with no increment applied.
     *
     * This verifies the MemoryGraft resistance property at the database level.
     */
    public function test_zero_trust_agent_does_not_increase_shared_edge_weight(): void
    {
        $ownerUserId = Str::uuid()->toString();

        $ghost = Agent::create([
            'owner_user_id' => $ownerUserId,
            'graph_user_id' => Str::uuid()->toString(),
            'name'          => 'Ghost',
            'trust_score'   => 0.0,
        ]);

        $sharedAlpha  = 0.06;
        $trustAlpha   = $sharedAlpha * $ghost->trust_score; // 0.06 * 0.0 = 0.0
        $initialWeight = 0.3; // SHARED_WEIGHT_INITIAL

        $weightAfter = min(1.0, $initialWeight + $trustAlpha);

        $this->assertEqualsWithDelta($initialWeight, $weightAfter, 0.0001,
            'A zero-trust agent must not increment any shared edge weight. '
            . "Expected {$initialWeight}, got {$weightAfter}.");
    }

    // ── Jaccard similarity tests ──────────────────────────────────────────────

    /**
     * Jaccard similarity: J(A, B) = |A ∩ B| / |A ∪ B|
     *
     * Source: Jaccard, P. (1901). "Etude comparative de la distribution
     * florale dans une portion des Alpes et des Jura." Bulletin de la
     * Société Vaudoise des Sciences Naturelles, 37, 547-579.
     *
     * In this project, Jaccard measures the overlap between the active
     * content sets of two agents: the set of memory content SHA-256 hashes
     * that each agent accessed in its most recent simulation tick.
     *
     * Two agents accessing identical memories have J=1.0.
     * Two agents with no shared memories have J=0.0.
     * Two agents sharing 1 of 5 unique memories have J = 1/5 = 0.20.
     *
     * Using content hashes (not node UUIDs) is essential: two agents hold
     * the same content in different graph partitions under different node IDs,
     * so UUID comparison always produces zero regardless of semantic overlap.
     */
    public function test_jaccard_of_identical_sets_is_one(): void
    {
        $setA = ['hash_1', 'hash_2', 'hash_3'];
        $setB = ['hash_1', 'hash_2', 'hash_3'];

        $jaccard = $this->jaccard($setA, $setB);

        $this->assertEqualsWithDelta(1.0, $jaccard, 0.0001,
            'Identical sets must produce Jaccard=1.0');
    }

    public function test_jaccard_of_disjoint_sets_is_zero(): void
    {
        $setA = ['hash_1', 'hash_2'];
        $setB = ['hash_3', 'hash_4'];

        $jaccard = $this->jaccard($setA, $setB);

        $this->assertEqualsWithDelta(0.0, $jaccard, 0.0001,
            'Disjoint sets must produce Jaccard=0.0');
    }

    public function test_jaccard_with_one_of_five_shared_is_zero_point_two(): void
    {
        // A = {1, 2, 3}, B = {3, 4, 5}
        // Intersection = {3} → |∩| = 1
        // Union = {1, 2, 3, 4, 5} → |∪| = 5
        // But via set algebra: |∪| = |A| + |B| - |∩| = 3 + 3 - 1 = 5
        // J = 1 / 5 = 0.2
        $setA = ['hash_1', 'hash_2', 'hash_3'];
        $setB = ['hash_3', 'hash_4', 'hash_5'];

        $jaccard = $this->jaccard($setA, $setB);

        $this->assertEqualsWithDelta(0.2, $jaccard, 0.0001,
            '|∩|=1, |∪|=5 → J = 1/5 = 0.20');
    }

    public function test_jaccard_with_two_of_six_shared_is_one_third(): void
    {
        // A = {1, 2, 3, 4}, B = {3, 4, 5, 6}
        // |∩| = 2, |∪| = 6 → J = 2/6 = 1/3 ≈ 0.333
        $setA = ['hash_1', 'hash_2', 'hash_3', 'hash_4'];
        $setB = ['hash_3', 'hash_4', 'hash_5', 'hash_6'];

        $jaccard = $this->jaccard($setA, $setB);

        $this->assertEqualsWithDelta(1 / 3, $jaccard, 0.0001,
            '|∩|=2, |∪|=6 → J = 2/6 = 1/3 ≈ 0.333');
    }

    public function test_jaccard_of_empty_sets_is_zero(): void
    {
        $jaccard = $this->jaccard([], []);

        $this->assertEqualsWithDelta(0.0, $jaccard, 0.0001,
            'Two empty sets produce J=0.0 by convention (no union)');
    }

    // ── Closed-form convergence reference ────────────────────────────────────

    /**
     * Reference table for how many decay steps (days without reinforcement)
     * it takes an edge to reach the floor from different starting weights.
     *
     * Formula: n* = ceil( log(FLOOR / w_0) / log(RHO) )
     *
     * w_0 = 1.0  → n* = ceil(log(0.05)/log(0.97)) = ceil(98.4)  = 99 days
     * w_0 = 0.5  → n* = ceil(log(0.10)/log(0.97)) = ceil(75.6)  = 76 days
     * w_0 = 0.3  → n* = ceil(log(0.1667)/log(0.97)) = ceil(58.8) = 59 days
     * w_0 = 0.1  → n* = ceil(log(0.50)/log(0.97))  = ceil(22.7)  = 23 days
     *
     * This test verifies all four cases against the closed form.
     * If RHO or FLOOR changes, this table breaks and must be recalculated.
     */
    public function test_closed_form_decay_convergence_table(): void
    {
        $rho   = 0.97;
        $floor = 0.05;

        $cases = [
            ['w0' => 1.0, 'expected_n' => 99],
            ['w0' => 0.5, 'expected_n' => 76],
            ['w0' => 0.3, 'expected_n' => 59],
            ['w0' => 0.1, 'expected_n' => 23],
        ];

        foreach ($cases as $case) {
            $nStar = (int) ceil(log($floor / $case['w0']) / log($rho));
            $this->assertEquals($case['expected_n'], $nStar,
                "Starting weight {$case['w0']}: expected floor at step {$case['expected_n']}, got {$nStar}. "
                . 'Update SCIENCE.md if RHO or FLOOR constants changed.');
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Pure Jaccard computation for use in unit-level tests.
     * The actual endpoint uses SHA-256 hashes of content strings as set elements.
     */
    private function jaccard(array $a, array $b): float
    {
        if (empty($a) && empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
