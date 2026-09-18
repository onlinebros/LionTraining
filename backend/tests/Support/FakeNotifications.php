<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\NotificationService;

/**
 * Records alerts instead of delivering them.
 *
 * Bind with `$this->app->instance(NotificationService::class, new FakeNotifications)`
 * and read back what a user would have been told.
 */
class FakeNotifications extends NotificationService
{
    /** @var list<array{user_id: int, type: string, title: string, body?: string, url?: string, data?: array}> */
    public array $sent = [];

    public function sendToUser(User $user, array $notification): void
    {
        $this->sent[] = ['user_id' => $user->id] + $notification;
    }

    /** The most recent alert to $user, or null. */
    public function lastFor(User $user): ?array
    {
        $mine = array_values(array_filter($this->sent, fn (array $n) => $n['user_id'] === $user->id));

        return $mine === [] ? null : end($mine);
    }
}
