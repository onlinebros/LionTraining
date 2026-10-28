<?php

namespace App\Services\ProposalStudio;

use App\Models\LibraryQuestion;
use App\Models\MicrositeSection;
use App\Models\OfferingDocument;
use App\Models\Proposal;
use App\Models\Prospect;
use App\Models\StudioJob;
use App\Models\User;
use App\Services\ProposalStudio\Jobs\ClaimExtract;
use App\Services\ProposalStudio\Jobs\Handler;
use App\Services\ProposalStudio\Jobs\LibraryAnswer;
use App\Services\ProposalStudio\Jobs\MicrositeSectionDraft;
use App\Services\ProposalStudio\Jobs\ProposalDraft;
use App\Services\ProposalStudio\Jobs\ProspectTurn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The Proposal Studio's queue of AI work, on the server that keeps the data.
 * Jobs are taken by proposal-studio:work: on dev from its own database, and
 * from the live site through the relay API. Whoever takes a job gets only a
 * prompt; the answer is applied here, by the job's handler.
 */
class JobQueue
{
    public const PROSPECT_REPLY = 'prospect_reply';

    public const PROSPECT_DRAFT = 'prospect_draft';

    public const PROPOSAL_DRAFT = 'proposal_draft';

    public const CLAIM_EXTRACT = 'claim_extract';

    public const LIBRARY_ANSWER = 'library_answer';

    public const MICROSITE_SECTION = 'microsite_section';

    // The subject each kind is about, which is also the morph type stored.
    private const SUBJECTS = [
        self::PROSPECT_REPLY => Prospect::class,
        self::PROSPECT_DRAFT => Prospect::class,
        self::PROPOSAL_DRAFT => Proposal::class,
        self::CLAIM_EXTRACT => OfferingDocument::class,
        self::LIBRARY_ANSWER => LibraryQuestion::class,
        self::MICROSITE_SECTION => MicrositeSection::class,
    ];

    // A job is tried this many times before it is given up as failed.
    public const MAX_ATTEMPTS = 3;

    public function dispatch(string $kind, Model $subject, ?User $by = null): StudioJob
    {
        if ((self::SUBJECTS[$kind] ?? null) !== $subject::class) {
            throw new InvalidArgumentException("A {$kind} job is not about a ".$subject::class.'.');
        }

        return StudioJob::create([
            'kind' => $kind,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'status' => StudioJob::PENDING,
            'created_by' => $by?->id,
        ]);
    }

    public function handler(string $kind): Handler
    {
        return match ($kind) {
            self::PROSPECT_REPLY => new ProspectTurn(false),
            self::PROSPECT_DRAFT => new ProspectTurn(true),
            self::PROPOSAL_DRAFT => new ProposalDraft,
            self::CLAIM_EXTRACT => new ClaimExtract,
            self::LIBRARY_ANSWER => new LibraryAnswer,
            self::MICROSITE_SECTION => new MicrositeSectionDraft,
            default => throw new InvalidArgumentException("Unknown job kind {$kind}."),
        };
    }

    /**
     * The oldest waiting job, handed out once, with its prompt. A job whose
     * subject has gone is closed rather than handed out.
     *
     * @return ?array{job: StudioJob, system: string, prompt: string}
     */
    public function claim(): ?array
    {
        $this->giveUpOnDeadJobs();

        while (true) {
            $job = DB::transaction(function () {
                $job = StudioJob::where(fn ($q) => $q->where('status', StudioJob::PENDING)
                    ->orWhere(fn ($q) => $q->where('status', StudioJob::CLAIMED)
                        ->where('claimed_at', '<', now()->subMinutes(StudioJob::STALE_MINUTES))))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $job?->forceFill(['status' => StudioJob::CLAIMED, 'claimed_at' => now(), 'attempts' => $job->attempts + 1])->save();

                return $job;
            });

            if ($job === null) {
                return null;
            }

            try {
                return ['job' => $job, 'system' => Prompts::system(), 'prompt' => $this->handler($job->kind)->prompt($job)];
            } catch (Throwable $e) {
                $this->close($job, StudioJob::FAILED, 'The prompt could not be built: '.$e->getMessage());
            }
        }
    }

    /** @param  array<string, mixed>  $meta */
    public function complete(StudioJob $job, string $text, array $meta = []): void
    {
        try {
            DB::transaction(function () use ($job, $text, $meta) {
                $this->handler($job->kind)->complete($job, $text, $meta);
                $this->close($job, StudioJob::DONE);
            });
        } catch (Throwable $e) {
            $this->fail($job, $e->getMessage());
        }
    }

    public function fail(StudioJob $job, string $error): void
    {
        Log::warning('Proposal Studio job failed', ['job' => $job->id, 'kind' => $job->kind, 'error' => $error]);

        try {
            $this->handler($job->kind)->fail($job, $error);
        } catch (Throwable) {
            // The subject has gone; there is nobody to tell.
        }
        $this->close($job, StudioJob::FAILED, $error);
    }

    // Claimed and never answered, again and again: tell the subject and stop.
    private function giveUpOnDeadJobs(): void
    {
        StudioJob::where('status', StudioJob::CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(StudioJob::STALE_MINUTES))
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->get()
            ->each(fn (StudioJob $job) => $this->fail($job, 'No answer after '.self::MAX_ATTEMPTS.' attempts.'));
    }

    private function close(StudioJob $job, string $status, ?string $error = null): void
    {
        $job->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
        ])->save();
    }
}
