<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'open');

        $tickets = SupportTicket::with('user', 'assignee')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'open'        => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'closed'      => SupportTicket::where('status', 'closed')->count(),
            'all'         => SupportTicket::count(),
        ];

        return view('admin.support.index', compact('tickets', 'status', 'counts'));
    }

    public function show(SupportTicket $ticket)
    {
        $ticket->load('replies.user', 'user', 'assignee');

        if ($ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        }

        return view('admin.support.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'body'        => 'required|string|max:10000',
            'is_internal' => 'boolean',
            'status'      => 'required|in:open,in_progress,closed',
        ]);

        SupportTicketReply::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => Auth::id(),
            'body'        => $data['body'],
            'is_internal' => $request->boolean('is_internal'),
        ]);

        $updateData = ['status' => $data['status']];
        if ($data['status'] === 'closed' && !$ticket->isClosed()) {
            $updateData['closed_at'] = now();
        } elseif ($data['status'] !== 'closed') {
            $updateData['closed_at'] = null;
        }

        $ticket->update($updateData);

        return back()->with('success', 'Reply sent and ticket updated.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['status' => 'required|in:open,in_progress,closed']);

        $update = ['status' => $data['status']];
        if ($data['status'] === 'closed' && !$ticket->isClosed()) {
            $update['closed_at'] = now();
        } elseif ($data['status'] !== 'closed') {
            $update['closed_at'] = null;
        }

        $ticket->update($update);

        return back()->with('success', 'Ticket status updated.');
    }

    public function assign(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate(['assigned_to' => 'nullable|exists:users,id']);

        $ticket->update(['assigned_to' => $data['assigned_to'] ?? null]);

        return back()->with('success', 'Ticket assignment updated.');
    }
}
