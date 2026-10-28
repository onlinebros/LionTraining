<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeClaim;
use App\Models\LibraryQuestion;
use App\Models\Offering;
use App\Models\OfferingDocument;
use App\Models\PortalSubmission;
use App\Models\StudioJob;
use App\Services\ProposalStudio\DocumentText;
use App\Services\ProposalStudio\Library;
use App\Services\ProposalStudio\StudioRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proposal Studio → Offerings: the product lines we present, and the
 * knowledge library the assistant learns each one from: documents, the
 * claims approved from them, and questions to the library. Super admins only.
 */
class OfferingController extends Controller
{
    public function __construct(private Library $library) {}

    public function index()
    {
        $offerings = Offering::withCount(['documents', 'prospects', 'claims as draft_claims_count' => fn ($q) => $q->where('status', 'draft')])
            ->orderBy('name')->get();

        return view('admin.proposal-studio.offerings.index', compact('offerings'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'vendor_name' => 'required|string|max:255',
            'key' => 'required|string|max:40|alpha_dash|unique:offerings,key',
        ]);

        $offering = Offering::create($data + ['is_active' => true]);

        return redirect()->route('admin.proposal-studio.offerings.show', $offering)
            ->with('success', 'Offering added. Describe it under Settings, then add its documents.');
    }

    public function show(Request $request, Offering $offering)
    {
        $offering->load('documents.uploader');
        $docIds = $offering->documents->pluck('id');
        $openJobs = StudioJob::whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])
            ->where('subject_type', (new OfferingDocument)->getMorphClass())->whereIn('subject_id', $docIds)
            ->get()->filter->isOpen()->pluck('subject_id')->all();

        return view('admin.proposal-studio.offerings.show', [
            'offering' => $offering,
            'tab' => (string) $request->query('tab', 'library'),
            'aiChars' => $offering->documents->where('use_for_ai', true)->sum('text_chars'),
            'contextChars' => (int) config('proposal-studio.context_chars'),
            'readingDocs' => $openJobs,
            'claims' => $offering->claims()->with('document', 'reviewer')->get()->groupBy('status'),
            'questions' => $offering->libraryQuestions()->with('user')->limit(30)->get(),
            'gaps' => PortalSubmission::with('prospect')->where('kind', 'question')
                ->whereHas('prospect', fn ($q) => $q->where('offering_id', $offering->id))
                ->where(fn ($q) => $q->where('answered_by', 'pending')->orWhere('answered_by', 'admin'))
                ->latest('id')->limit(30)->get(),
            'busy' => $openJobs || $this->openQuestion($offering),
        ]);
    }

    // Polled while the assistant is reading a document or answering a question.
    public function status(Offering $offering): JsonResponse
    {
        $docs = $offering->documents()->pluck('id');
        $open = StudioJob::whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])
            ->where('subject_type', (new OfferingDocument)->getMorphClass())->whereIn('subject_id', $docs)
            ->get()->filter->isOpen()->count();

        return response()->json(['busy' => $open > 0 || $this->openQuestion($offering)]);
    }

    public function update(Request $request, Offering $offering): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'vendor_name' => 'required|string|max:255',
            'vendor_key' => ['nullable', Rule::in(array_keys((array) config('vendors.vendors')))],
            'vendor_url' => 'nullable|url|max:255',
            'opportunity_key' => ['nullable', Rule::in(array_keys((array) config('opportunities.opportunities')))],
            'summary' => 'nullable|string|max:5000',
            'ai_guidance' => 'nullable|string|max:20000',
            'prohibited_claims' => 'nullable|string|max:20000',
            'discovery_checklist' => 'nullable|string|max:10000',
            'is_active' => 'boolean',
        ]);

        $offering->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Saved.');
    }

    public function storeDocument(Request $request, Offering $offering): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'kind' => ['required', Rule::in(array_keys(OfferingDocument::KINDS))],
            'audience' => ['required', Rule::in(array_keys(OfferingDocument::AUDIENCES))],
            'source_url' => 'nullable|url|max:2048',
            'read_on' => 'nullable|date',
            'file' => 'required_without:text|nullable|file|max:'.config('proposal-studio.upload_max_kb').'|mimes:'.implode(',', config('proposal-studio.upload_mimes')),
            'text' => 'required_without:file|nullable|string|max:'.DocumentText::MAX_CHARS,
        ]);
        $attributes = array_intersect_key($data, array_flip(['title', 'kind', 'audience', 'source_url', 'read_on']));

        $document = ($file = $request->file('file'))
            ? $this->library->addUpload($offering, $file, $attributes, $request->user())
            : $this->library->addText($offering, (string) $data['text'], $attributes, $request->user());

        return back()->with(
            $document->extraction_error ? 'error' : 'success',
            $document->extraction_error
                ? "Saved \"{$document->title}\", but no text could be read from it: {$document->extraction_error}"
                : "Added \"{$document->title}\" (".number_format($document->text_chars).' characters of text).',
        );
    }

    public function showDocument(Offering $offering, OfferingDocument $document)
    {
        abort_unless($document->offering_id === $offering->id, 404);
        $document->load('claims');

        return view('admin.proposal-studio.offerings.document', compact('offering', 'document'));
    }

    public function updateDocument(Request $request, Offering $offering, OfferingDocument $document): RedirectResponse
    {
        abort_unless($document->offering_id === $offering->id, 404);

        $data = $request->validate([
            'use_for_ai' => 'sometimes|boolean',
            'audience' => ['sometimes', Rule::in(array_keys(OfferingDocument::AUDIENCES))],
            'kind' => ['sometimes', Rule::in(array_keys(OfferingDocument::KINDS))],
        ]);
        $document->update($data);

        return back()->with('success', "Updated \"{$document->title}\".");
    }

    public function downloadDocument(Offering $offering, OfferingDocument $document): StreamedResponse
    {
        abort_unless($document->offering_id === $offering->id && $document->file_path, 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name ?: basename($document->file_path));
    }

    public function destroyDocument(Offering $offering, OfferingDocument $document): RedirectResponse
    {
        abort_unless($document->offering_id === $offering->id, 404);
        $this->library->remove($document);

        return redirect()->route('admin.proposal-studio.offerings.show', $offering)
            ->with('success', "Removed \"{$document->title}\" and any claims drawn from it.");
    }

    public function extractClaims(Request $request, Offering $offering, OfferingDocument $document): RedirectResponse
    {
        abort_unless($document->offering_id === $offering->id, 404);

        try {
            $this->library->extractClaims($document, $request->user());
        } catch (StudioRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "The assistant is reading \"{$document->title}\" for claims. They appear under Claims for you to review.");
    }

    public function storeClaim(Request $request, Offering $offering, OfferingDocument $document): RedirectResponse
    {
        abort_unless($document->offering_id === $offering->id, 404);
        $data = $request->validate([
            'quote' => 'required|string|max:3000',
            'footnote' => 'nullable|string|max:3000',
            'topic' => 'nullable|string|max:120',
        ]);

        try {
            $this->library->addClaim($document, $data['quote'], $data['footnote'] ?? null, $data['topic'] ?? null, $request->user());
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Claim added and approved.');
    }

    public function reviewClaim(Request $request, Offering $offering, KnowledgeClaim $claim): RedirectResponse
    {
        abort_unless($claim->offering_id === $offering->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected', 'draft'])]]);
        $this->library->review($claim, $data['status'], $request->user());

        return back();
    }

    public function reviewClaims(Request $request, Offering $offering): RedirectResponse
    {
        $data = $request->validate([
            'claim_ids' => 'required|array',
            'claim_ids.*' => 'integer',
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);
        $claims = $offering->claims()->whereIn('id', $data['claim_ids'])->get();
        $claims->each(fn ($claim) => $this->library->review($claim, $data['status'], $request->user()));

        return back()->with('success', $claims->count().' claim(s) '.$data['status'].'.');
    }

    public function ask(Request $request, Offering $offering): RedirectResponse
    {
        $data = $request->validate(['question' => 'required|string|max:3000']);

        try {
            $this->library->ask($offering, $data['question'], $request->user());
        } catch (StudioRefused $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.proposal-studio.offerings.show', [$offering, 'tab' => 'ask']);
    }

    private function openQuestion(Offering $offering): bool
    {
        $ids = LibraryQuestion::where('offering_id', $offering->id)->whereNull('answer')->pluck('id');

        return StudioJob::whereIn('status', [StudioJob::PENDING, StudioJob::CLAIMED])
            ->where('subject_type', (new LibraryQuestion)->getMorphClass())->whereIn('subject_id', $ids)
            ->get()->contains(fn ($job) => $job->isOpen());
    }
}
