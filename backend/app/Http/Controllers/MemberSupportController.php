<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MemberSupportController extends Controller
{
    public function index()
    {
        $tickets = SupportTicket::where('user_id', Auth::id())
            ->latest()
            ->paginate(15);

        return view('member.support.index', compact('tickets'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject'       => 'required|string|max:255',
            'category'      => 'required|in:' . implode(',', array_keys(SupportTicket::CATEGORIES)),
            'priority'      => 'required|in:' . implode(',', array_keys(SupportTicket::PRIORITIES)),
            'body'          => 'required|string|max:10000',
            'source_url'    => 'nullable|url|max:2048',
            '_ticket_modal' => 'nullable',
        ]);

        $ticket = SupportTicket::create([
            'user_id'    => Auth::id(),
            'subject'    => $data['subject'],
            'category'   => $data['category'],
            'priority'   => $data['priority'],
            'source_url' => $data['source_url'] ?? null,
        ]);

        SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'body'      => $data['body'],
        ]);

        return redirect()->route('member.support.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_number} submitted. We'll get back to you soon.");
    }

    public function show(SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === Auth::id(), 403);

        $ticket->load('replies.user');

        return view('member.support.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === Auth::id(), 403);
        abort_if($ticket->isClosed(), 403, 'This ticket is closed.');

        $data = $request->validate(['body' => 'required|string|max:10000']);

        SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id'   => Auth::id(),
            'body'      => $data['body'],
        ]);

        if ($ticket->status === 'closed') {
            $ticket->update(['status' => 'open']);
        }

        return back()->with('success', 'Reply sent.');
    }
}
