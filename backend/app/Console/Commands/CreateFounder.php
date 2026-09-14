<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Genealogy\EnrollmentService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;

/**
 * Creates a founding partner — an account with no sponsor, at the top of a tree.
 *
 * Registration is invitation only (config/registration.php), and an invitation
 * has to come from somebody. This is that somebody: the first account, created
 * from the command line, whose referral link then enrols the founding team.
 * After that the normal /join/{code} flow carries everyone else.
 *
 * Why a command rather than the admin "Add User" form:
 *
 *   Add User calls User::create() and nothing else. The account lands with
 *   `placement_status = queued` and `enrollment_path = null`, so
 *   `network:place-queued` will position it but nothing ever fills in the
 *   enrollment path — and the enrollment tree is what the genealogy queries
 *   read. For a leaf account that is survivable. For the root of a tree it is
 *   not: everyone enrolled underneath hangs off that path.
 *
 * This goes through EnrollmentService, the one place an enrollment is written,
 * which sets both representations in a single transaction.
 */
class CreateFounder extends Command
{
    use ConfirmableTrait;

    protected $signature = 'founder:create
                            {email : Email address for the founding partner}
                            {--name= : Display name (defaults to the email local part)}
                            {--password= : Password (a strong one is generated and printed if omitted)}
                            {--role=paid_member : Role name, e.g. paid_member or super_admin}
                            {--sponsor= : Email of an existing partner to enrol under (default: none — a tree root)}
                            {--force : Skip the production confirmation prompt}';

    protected $description = 'Create a founding partner at the top of a tree, so invitations have a source';

    public function handle(EnrollmentService $enrollment): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email address: {$email}");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");
            $this->line('Founders are created once. To hand out a link for an existing');
            $this->line('partner, read their referral_code instead of re-creating them.');

            return self::FAILURE;
        }

        $roleName = $this->option('role');
        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            $this->error("No role named '{$roleName}'.");
            $this->line('Available: '.Role::query()->orderBy('level')->pluck('name')->implode(', '));

            return self::FAILURE;
        }

        // A founder is normally a root. --sponsor exists for the second and
        // third founders, when you would rather they sat under the first than
        // start unconnected trees.
        $sponsor = null;
        if ($this->option('sponsor') !== null) {
            $sponsor = User::where('email', strtolower(trim($this->option('sponsor'))))->first();

            if ($sponsor === null) {
                $this->error("No user with email {$this->option('sponsor')} to sponsor them.");

                return self::FAILURE;
            }
        }

        // Generated rather than prompted-for by default: a password typed at a
        // shell prompt ends up in the history file.
        $password = $this->option('password');
        $generated = $password === null;
        $password ??= Str::password(20);

        $user = User::create([
            'name'      => $this->option('name') ?: Str::before($email, '@'),
            'email'     => $email,
            // Plain — User casts `password` as 'hashed', same as the signup
            // controller. Hashing here too would rely on the cast noticing.
            'password'  => $password,
            'role_id'   => $role->id,
            'is_active' => true,
        ]);

        // Enrolled and placed in one transaction, exactly as the signup form
        // does it, so the founder is a real position in the structure rather
        // than a row that happens to exist.
        $user = $enrollment->enroll($user, $sponsor);
        $user->refresh();

        $this->newLine();
        $this->info('Founding partner created.');
        $this->table(['Field', 'Value'], [
            ['Name',            $user->name],
            ['Email',           $user->email],
            ['Role',            $role->display_name ?? $role->name],
            ['Sponsor',         $sponsor?->email ?? '— (tree root)'],
            ['Referral code',   $user->referral_code],
            ['Placement',       $user->placement_status],
            ['Enrollment path', $user->enrollment_path ?? '—'],
        ]);

        $this->newLine();
        $this->line('Invitation link — this is how the founding team signs up:');
        $this->info('  '.url('/join/'.$user->referral_code));

        if ($generated) {
            $this->newLine();
            $this->line('Generated password (shown once — store it in your password manager):');
            $this->info('  '.$password);
        }

        if ($user->enrollment_path === null) {
            $this->newLine();
            $this->warn('Enrollment path is empty. Anyone enrolled under this account will');
            $this->warn('not appear in genealogy queries. Do not hand out the link yet.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
