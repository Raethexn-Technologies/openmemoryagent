<?php

namespace App\Http\Controllers;

use App\Services\IcpMemoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemoryController extends Controller
{
    public function __construct(
        private readonly IcpMemoryService $icp,
    ) {}

    /**
     * Memory inspector dashboard.
     */
    public function index(): Response
    {
        $recent = $this->icp->listRecentMemories(50);

        return Inertia::render('Memory/Index', [
            'memories' => $recent,
        ]);
    }

    /**
     * Get memories for the current user (AJAX).
     */
    public function forUser(Request $request)
    {
        $userId = session()->get('chat_user_id');

        if (! $userId) {
            return response()->json(['memories' => []]);
        }

        $memories = $this->icp->getMemories($userId);

        return response()->json([
            'user_id'  => $userId,
            'memories' => $memories,
        ]);
    }
}
