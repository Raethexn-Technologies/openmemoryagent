<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds access tracking columns to memory_nodes and memory_edges.
 *
 * These columns drive the Physarum/Hebbian weight update model described in DEVLOG Entry 003.
 * Edge weights are no longer static; they grow when the edge is traversed and decay over time.
 *
 * The governing equation (discrete form of Tero et al. 2010):
 *   w(t+1) = clamp(w(t) + α,  floor, 1.0)   on access
 *   w(t+1) = clamp(w(t) * ρ,  floor, 1.0)   on scheduled decay
 *
 * Where α = 0.10 (reinforcement rate) and ρ = 0.97 (daily retention, i.e. 3 % decay per day).
 * The floor of 0.05 ensures edges never fully vanish but become thin enough to be visually
 * de-emphasised in the Three.js visualization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memory_nodes', function (Blueprint $table) {
            $table->unsignedInteger('access_count')->default(0)->after('confidence');
            $table->timestamp('last_accessed_at')->nullable()->after('access_count');
        });

        Schema::table('memory_edges', function (Blueprint $table) {
            $table->unsignedInteger('access_count')->default(0)->after('weight');
            $table->timestamp('last_accessed_at')->nullable()->after('access_count');
        });
    }

    public function down(): void
    {
        Schema::table('memory_nodes', function (Blueprint $table) {
            $table->dropColumn(['access_count', 'last_accessed_at']);
        });

        Schema::table('memory_edges', function (Blueprint $table) {
            $table->dropColumn(['access_count', 'last_accessed_at']);
        });
    }
};
