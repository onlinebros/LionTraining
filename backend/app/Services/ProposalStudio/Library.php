<?php

namespace App\Services\ProposalStudio;

use App\Models\KnowledgeClaim;
use App\Models\LibraryQuestion;
use App\Models\Offering;
use App\Models\OfferingDocument;
use App\Models\StudioJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The knowledge library: adding documents (from an upload, pasted text or
 * the import command), the claims drawn from them, and admins' questions.
 */
class Library
{
    public function __construct(
        private DocumentText $extractor,
        private Knowledge $knowledge,
        private JobQueue $queue,
    ) {}

    /**
     * @param  array{title: string, kind: string, audience: string, source_url?: ?string, read_on?: ?string}  $attributes
     * @param  ?string  $text  used instead of the file's own text (a transcription of a scan, say)
     */
    public function addFile(Offering $offering, string $path, string $originalName, array $attributes, ?User $by = null, ?string $text = null): OfferingDocument
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stored = "offerings/{$offering->id}/".Str::uuid().'.'.$extension;
        Storage::disk('local')->put($stored, (string) file_get_contents($path));

        $document = new OfferingDocument($attributes + [
            'offering_id' => $offering->id,
            'use_for_ai' => true,
            'uploaded_by' => $by?->id,
            'file_path' => $stored,
            'original_name' => mb_substr($originalName, 0, 255),
            'mime' => mime_content_type($path) ?: null,
            'size_bytes' => filesize($path) ?: null,
        ]);

        if ($text === null) {
            try {
                $text = $this->extractor->extract(Storage::disk('local')->path($stored), $extension);
            } catch (Throwable $e) {
                $text = '';
                $document->extraction_error = mb_substr($e->getMessage(), 0, 500);
            }
        } else {
            $text = DocumentText::clean($text);
        }

        return $this->save($document, $text);
    }

    public function addUpload(Offering $offering, UploadedFile $file, array $attributes, ?User $by = null): OfferingDocument
    {
        return $this->addFile($offering, $file->getRealPath(), $file->getClientOriginalName(), $attributes, $by);
    }

    /** @param  array{title: string, kind: string, audience: string, source_url?: ?string, read_on?: ?string}  $attributes */
    public function addText(Offering $offering, string $text, array $attributes, ?User $by = null): OfferingDocument
    {
        return $this->save(new OfferingDocument($attributes + [
            'offering_id' => $offering->id,
            'use_for_ai' => true,
            'uploaded_by' => $by?->id,
        ]), DocumentText::clean($text));
    }

    private function save(OfferingDocument $document, string $text): OfferingDocument
    {
        $document->fill(['extracted_text' => $text, 'text_chars' => mb_strlen($text)])->save();
        $this->knowledge->index($document);

        return $document;
    }

    public function remove(OfferingDocument $document): void
    {
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->delete();
    }

    /** Ask the assistant for the claims in a document. */
    public function extractClaims(OfferingDocument $document, User $by): StudioJob
    {
        if ($this->openJobFor($document) !== null) {
            throw new StudioRefused('The assistant is already reading this document for claims.');
        }
        if ($document->text_chars === 0) {
            throw new StudioRefused('This document has no text to read claims from.');
        }

        return $this->queue->dispatch(JobQueue::CLAIM_EXTRACT, $document, $by);
    }

    public function openJobFor(OfferingDocument $document): ?StudioJob
    {
        return StudioJob::where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)
            ->whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])->get()->first(fn ($j) => $j->isOpen());
    }

    /** A claim typed in by an admin, held to the same rule: word for word from the document. */
    public function addClaim(OfferingDocument $document, string $quote, ?string $footnote, ?string $topic, User $by): KnowledgeClaim
    {
        $source = (string) $document->extracted_text;
        if (! KnowledgeClaim::isQuotedFrom($quote, $source)) {
            throw new StudioRefused('That wording is not in the document. Claims are quoted word for word: copy it from the document text.');
        }
        if (filled($footnote) && ! KnowledgeClaim::isQuotedFrom((string) $footnote, $source)) {
            throw new StudioRefused('That footnote is not in the document. Copy it from the document text, or leave it empty.');
        }

        return KnowledgeClaim::create([
            'offering_id' => $document->offering_id,
            'offering_document_id' => $document->id,
            'topic' => $topic,
            'quote' => trim($quote),
            'footnote' => filled($footnote) ? trim((string) $footnote) : null,
            'status' => 'approved',
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
        ]);
    }

    public function review(KnowledgeClaim $claim, string $status, User $by): void
    {
        $claim->forceFill(['status' => $status, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
    }

    public function ask(Offering $offering, string $question, User $by): LibraryQuestion
    {
        $recent = LibraryQuestion::where('user_id', $by->id)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= (int) config('proposal-studio.hourly_limit')) {
            throw new StudioRefused('You have asked the library a lot in the last hour. Please try again a little later.');
        }

        $entry = LibraryQuestion::create(['offering_id' => $offering->id, 'user_id' => $by->id, 'question' => $question]);
        $this->queue->dispatch(JobQueue::LIBRARY_ANSWER, $entry, $by);

        return $entry;
    }
}
