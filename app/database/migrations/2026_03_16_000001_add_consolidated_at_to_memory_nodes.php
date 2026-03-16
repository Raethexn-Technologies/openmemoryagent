<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memory_nodes', function (Blueprint $table) {
            // Timestamp set when a node is absorbed into a semantic concept node
            // by ConsolidationService. Null means the node is still episodic.
            $table->timestamp('consolidated_at')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('memory_nodes', function (Blueprint $table) {
            $table->dropColumn('consolidated_at');
        });
    }
};
