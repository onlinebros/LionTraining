<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes pre-launch preview access by email.
 *
 * Exists so nobody edits the database by hand to let the founding team through
 * the guard — a hand-edited flag is one nobody remembers to clear.
 */
class PrelaunchPreview extends Command
{
    protected $signature = 'prelaunch:preview
                            {email : The user to grant or revoke access for}
                            {--enable : Grant preview access}
                            {--disable : Revoke preview access}';

    protected $description = 'Grant or revoke pre-launch preview access for a user';

    public function handle(): int
    {
        if ($this->option('enable') === $this->option('disable')) {
            $this->error('Pass exactly one of --enable or --disable.');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $enable = (bool) $this->option('enable');
        $user->prelaunch_preview = $enable;
        $user->save();

        $this->info(sprintf(
            'Pre-launch preview %s for %s.',
            $enable ? 'ENABLED' : 'DISABLED',
            $user->email,
        ));

        if ($enable && $user->isAdmin()) {
            $this->comment('Note: this user is an admin and already passed the guard regardless.');
        }

        return self::SUCCESS;
    }
}
