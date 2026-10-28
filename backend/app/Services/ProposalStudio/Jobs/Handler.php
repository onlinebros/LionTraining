<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\StudioJob;

/**
 * One kind of AI work: what to ask, and what to do with the answer. Both run
 * on the server that keeps the data; only the prompt and the answer travel.
 */
interface Handler
{
    public function prompt(StudioJob $job): string;

    /** @param  array<string, mixed>  $meta */
    public function complete(StudioJob $job, string $text, array $meta): void;

    public function fail(StudioJob $job, string $error): void;
}
