<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Alerts to one user: a guest arrived, asked something, or made a choice.
 *
 * This project has no in-app notification inbox yet, so an alert is only
 * logged. Hosts see the same events live in Your Rooms
 * (/member/presentations/live), which polls every few seconds. When an inbox
 * or push channel exists, deliver from here and every caller picks it up.
 *
 * @see \App\Services\Presentations\Conversation
 */
class NotificationService
{
    /**
     * @param  array{type: string, title: string, body?: string, url?: string, data?: array}  $notification
     */
    public function sendToUser(User $user, array $notification): void
    {
        Log::info('notification', ['user_id' => $user->id] + $notification);
    }
}
