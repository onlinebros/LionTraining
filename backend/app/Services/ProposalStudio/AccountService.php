<?php

namespace App\Services\ProposalStudio;

use App\Models\BusinessAccount;
use App\Models\BusinessActivity;
use App\Models\BusinessContact;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Business accounts: the company, its people, its timeline and its
 * opportunities. See memory-bank/b2b-accounts.md.
 */
class AccountService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  ?array<string, mixed>  $contact  the first contact, if known
     */
    public function create(User $by, array $attributes, ?array $contact = null): BusinessAccount
    {
        return DB::transaction(function () use ($by, $attributes, $contact) {
            $account = BusinessAccount::create($attributes + [
                'created_by' => $by->id,
                'owner_user_id' => $by->id,
                'stage' => 'target',
            ]);
            if ($contact && filled($contact['name'] ?? null)) {
                $account->contacts()->create($contact + ['is_primary' => true]);
            }
            $account->log('system', 'Account created.', $by);

            return $account;
        });
    }

    /**
     * An account for a prospect started without one, from its company details.
     *
     * @param  array<string, mixed>  $p
     */
    public function createFromProspect(array $p, User $by): BusinessAccount
    {
        $name = ($p['contact_name'] ?? null) ?: (isset($p['contact_email']) ? Str::before((string) $p['contact_email'], '@') : null);

        return $this->create($by, [
            'name' => $p['company_name'],
            'website' => $p['website'] ?? null,
            'industry' => $p['industry'] ?? null,
            'stage' => 'engaged',
        ], $name ? [
            'name' => $name,
            'email' => $p['contact_email'] ?? null,
            'phone' => $p['contact_phone'] ?? null,
        ] : null);
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(BusinessAccount $account, array $attributes, User $by): void
    {
        $before = $account->stage;
        $account->update($attributes);
        if ($account->stage !== $before) {
            $account->log('system', "Stage changed from {$this->stageName($before)} to {$account->stageLabel()}.", $by);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public function saveContact(BusinessAccount $account, array $attributes, ?BusinessContact $contact = null): BusinessContact
    {
        return DB::transaction(function () use ($account, $attributes, $contact) {
            if (! empty($attributes['is_primary'])) {
                $account->contacts()->where('is_primary', true)->update(['is_primary' => false]);
            }
            $contact ??= new BusinessContact(['business_account_id' => $account->id]);
            $contact->fill($attributes)->save();

            return $contact;
        });
    }

    public function addActivity(BusinessAccount $account, User $by, string $type, string $body, ?string $dueOn = null): BusinessActivity
    {
        return $account->activities()->create([
            'type' => $type,
            'body' => $body,
            'user_id' => $by->id,
            'due_on' => $type === 'task' ? $dueOn : null,
        ]);
    }

    public function completeTask(BusinessActivity $task, User $by): void
    {
        if ($task->isOpenTask()) {
            $task->forceFill(['done_at' => now(), 'meta' => ($task->meta ?? []) + ['done_by' => $by->id]])->save();
        }
    }

    /** A won opportunity makes the account a customer. */
    public function opportunityClosed(Prospect $prospect, User $by): void
    {
        $account = $prospect->account;
        if ($account === null || ! in_array($prospect->status, ['won', 'lost'], true)) {
            return;
        }
        $account->log('system', "Opportunity for {$prospect->offering->name} marked {$prospect->statusLabel()}.", $by, ['prospect' => $prospect->id]);
        if ($prospect->status === 'won' && $account->stage !== 'customer') {
            $this->update($account, ['stage' => 'customer'], $by);
        }
    }

    public function storeLogo(BusinessAccount $account, UploadedFile $file): void
    {
        $old = $account->logo_path;
        $path = $file->storeAs("business-accounts/{$account->id}", 'logo-'.Str::lower(Str::random(8)).'.'.$file->extension(), 'local');
        $account->forceFill(['logo_path' => $path])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    public function removeLogo(BusinessAccount $account): void
    {
        if ($account->logo_path) {
            Storage::disk('local')->delete($account->logo_path);
            $account->forceFill(['logo_path' => null])->save();
        }
    }

    private function stageName(string $stage): string
    {
        return BusinessAccount::STAGES[$stage] ?? ucfirst($stage);
    }
}
