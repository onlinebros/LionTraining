<?php

namespace Tests\Feature\RequestAssistant;

use App\Http\Middleware\RequireRequestAssistantToken;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class RequestAssistantTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token-0123456789';

    protected const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected User $michael;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->michael = $this->user(Role::SUPER_ADMIN, 'Michael Stern');
    }

    protected function user(string $role, string $name = ''): User
    {
        $user = User::create([
            'name' => $name ?: 'Test '.$role,
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $user->forceFill([
            'role_id' => Role::findByName($role)->id,
            'is_active' => true,
            'billing_exempt' => true,
        ])->save();

        return $user->refresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function openRequest(array $attributes = [], ?User $by = null): ChangeRequest
    {
        $by ??= $this->michael;
        $request = ChangeRequest::create($attributes + ['user_id' => $by->id, 'title' => 'Team export', 'status' => 'drafting']);
        $request->messages()->create([
            'role' => ChangeRequestMessage::ROLE_USER,
            'user_id' => $by->id,
            'body' => 'Partners want to download their team list.',
        ]);

        return $request;
    }

    protected function issueToken(): void
    {
        SiteSetting::set(RequireRequestAssistantToken::SETTING, hash('sha256', self::TOKEN));
    }
}
