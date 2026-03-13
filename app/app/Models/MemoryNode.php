<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemoryNode extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'session_id', 'type', 'sensitivity', 'label',
        'content', 'tags', 'confidence', 'access_count', 'last_accessed_at', 'source', 'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'confidence' => 'float',
        'access_count' => 'integer',
        'last_accessed_at' => 'datetime',
    ];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(MemoryEdge::class, 'from_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(MemoryEdge::class, 'to_node_id');
    }
}
