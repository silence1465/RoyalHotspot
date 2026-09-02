<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Complaint;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $query = Complaint::with('customer:id,full_name,phone');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(
            $query->latest()->paginate($request->integer('per_page', 10))
        );
    }

    public function show(Complaint $complaint)
    {
        return response()->json($complaint->load('customer', 'respondedBy:id,name'));
    }

    /**
     * Covers both "just acknowledge, mark in progress" (no response
     * text) and "respond and resolve" (with response text) — one
     * endpoint, since the status transition logic is the same either
     * way and a separate endpoint just to change status without a
     * response would duplicate most of this.
     */
    public function respond(Request $request, Complaint $complaint)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved'],
            'admin_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $complaint->update([
            'status' => $validated['status'],
            'admin_response' => $validated['admin_response'] ?? $complaint->admin_response,
            'responded_by' => $request->user()->id,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : $complaint->resolved_at,
        ]);

        ActivityLog::record(
            'complaint.updated',
            "Complaint #{$complaint->id} marked '{$validated['status']}'.",
            ['user_id' => $request->user()->id]
        );

        return response()->json($complaint->fresh(['customer', 'respondedBy:id,name']));
    }
}
