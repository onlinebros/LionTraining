<?php

namespace App\Services\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * A change request as the live site sends it to dev's review screens, and
 * back into unsaved models on dev so the same views render it.
 */
class Payload
{
    private const DATES = ['created_at', 'updated_at', 'plan_drafted_at', 'assistant_busy_since', 'submitted_at', 'reviewed_at'];

    /** @return array<string, mixed> */
    public static function summary(ChangeRequest $request): array
    {
        return [
            'id' => $request->id,
            'number' => $request->number,
            'title' => $request->title,
            'status' => $request->status,
            'has_plan' => filled($request->plan),
            'requester_name' => $request->user?->name,
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function full(ChangeRequest $request): array
    {
        $data = self::summary($request) + [
            'requester_email' => $request->user?->email,
            'plan' => $request->plan,
            'source_url' => $request->source_url,
            'source_title' => $request->source_title,
            'snapshot_commit' => $request->snapshot_commit,
            'review_notes' => $request->review_notes,
            'reviewer_name' => $request->reviewerLabel(),
            'messages' => $request->messages->map(fn (ChangeRequestMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'author_name' => $m->author_name ?: $m->user?->name,
                'body' => $m->body,
                'meta' => $m->meta,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ];
        foreach (self::DATES as $key) {
            $data[$key] = $request->{$key}?->toIso8601String();
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function hydrate(array $data): ChangeRequest
    {
        $request = new ChangeRequest;
        $request->forceFill(array_intersect_key($data, array_flip([
            'number', 'title', 'status', 'plan', 'source_url', 'source_title',
            'snapshot_commit', 'review_notes', 'reviewer_name',
        ])) + ['id' => (int) $data['id']]);
        foreach (self::DATES as $key) {
            $request->{$key} = isset($data[$key]) ? Carbon::parse($data[$key]) : null;
        }
        if (! isset($data['plan']) && ! empty($data['has_plan'])) {
            $request->plan = '(drafted)';
        }
        $request->exists = true;

        $request->setRelation('user', (new User)->forceFill([
            'name' => $data['requester_name'] ?? null,
            'email' => $data['requester_email'] ?? null,
        ]));
        $request->setRelation('reviewer', null);

        $request->setRelation('messages', new Collection(array_map(function (array $m) {
            $message = new ChangeRequestMessage;
            $message->forceFill([
                'id' => (int) $m['id'],
                'role' => (string) $m['role'],
                'body' => (string) $m['body'],
                'author_name' => $m['author_name'] ?? null,
                'meta' => $m['meta'] ?? null,
            ]);
            $message->created_at = isset($m['created_at']) ? Carbon::parse($m['created_at']) : null;
            $message->setRelation('user', null);

            return $message;
        }, $data['messages'] ?? [])));

        return $request;
    }
}
