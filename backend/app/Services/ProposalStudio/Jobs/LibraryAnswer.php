<?php

namespace App\Services\ProposalStudio\Jobs;

use App\Models\LibraryQuestion;
use App\Models\StudioJob;
use App\Services\ProposalStudio\Prompts;

/** An admin's question to the knowledge library. */
class LibraryAnswer implements Handler
{
    public function prompt(StudioJob $job): string
    {
        return Prompts::libraryAnswer(LibraryQuestion::with('offering')->findOrFail($job->subject_id));
    }

    public function complete(StudioJob $job, string $text, array $meta): void
    {
        LibraryQuestion::findOrFail($job->subject_id)->forceFill(['answer' => trim($text)])->save();
    }

    public function fail(StudioJob $job, string $error): void {}
}
