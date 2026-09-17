<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerCompany;
use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use App\Services\Partner\SpotImportCommitter;
use App\Services\Partner\SpotImportParser;
use App\Services\Partner\SpotImportTemplate;
use App\Services\Partner\SpotImportValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload → review → connect the legs → commit.
 *
 * The review step is the point of the whole screen. Placement is permanent by
 * design, so this is the last moment anything can be corrected cheaply, and the
 * page is built to make the two mistakes that cannot be undone visible: a leg
 * hung under the wrong partner, and a row whose parent is not what the partner
 * company meant.
 */
class SpotImportController extends Controller
{
    public function __construct(
        private SpotImportParser $parser,
        private SpotImportValidator $validator,
        private SpotImportCommitter $committer,
    ) {}

    public function index()
    {
        return view('admin.partners.imports', [
            'imports'   => PartnerImport::with('company', 'uploader')->latest()->paginate(20),
            'companies' => PartnerCompany::orderBy('name')->get(),
        ]);
    }

    /** The CSV we hand to a partner company. */
    public function template(Request $request): StreamedResponse
    {
        $csv = SpotImportTemplate::csv(withSamples: ! $request->boolean('blank'));

        return response()->streamDownload(
            fn () => print($csv),
            'partner-spot-import-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'partner_company_id' => 'required|exists:partner_companies,id',
            // Deliberately not `mimes:csv` — browsers and Excel between them
            // label the same file text/csv, application/vnd.ms-excel and
            // text/plain depending on the machine, and rejecting an otherwise
            // perfect list on a MIME string is a support call every time.
            'file'               => 'required|file|max:20480',
            'notes'              => 'nullable|string|max:2000',
        ]);

        $file    = $request->file('file');
        $company = PartnerCompany::findOrFail($data['partner_company_id']);

        $import = PartnerImport::create([
            'partner_company_id' => $company->id,
            'original_filename'  => $file->getClientOriginalName(),
            // Kept on the private disk. The file contains a column of live
            // activation codes; it must not be reachable over the web.
            'stored_path'        => $file->store("partner-imports/{$company->slug}"),
            'uploaded_by'        => auth()->id(),
            'notes'              => $data['notes'] ?? null,
        ]);

        $this->parser->parse($import, Storage::path($import->stored_path));

        if ($import->refresh()->status !== PartnerImport::STATUS_FAILED) {
            $this->autoLinkTopRows($import);
            $this->validator->validate($import);
        }

        return redirect()->route('admin.partners.imports.show', $import)
            ->with('success', "Staged {$import->total_rows} row(s) from {$import->original_filename}.");
    }

    public function show(PartnerImport $import, Request $request)
    {
        $import->load('company', 'uploader');

        $rows = $import->rows()
            ->with('parentUser:id,name,email', 'createdUser:id,name,account_status')
            ->when($request->get('filter') === 'errors',
                fn ($q) => $q->where('status', PartnerImportRow::STATUS_INVALID))
            ->when($request->get('filter') === 'top',
                fn ($q) => $q->whereNull('external_parent_id'))
            ->when($request->get('filter') === 'warnings',
                fn ($q) => $q->whereNotNull('warnings'))
            ->paginate(50)
            ->withQueryString();

        return view('admin.partners.import-show', [
            'import'   => $import,
            'rows'     => $rows,
            'topRows'  => $import->topRows()->with('parentUser:id,name,email')->get(),
            'filter'   => $request->get('filter'),
            'unlinked' => $import->unlinkedTopRows(),
        ]);
    }

    /** Connect one leg to a partner already in our system. */
    public function link(Request $request, PartnerImport $import, PartnerImportRow $row)
    {
        abort_unless($row->partner_import_id === $import->id, 404);
        abort_if($import->isCommitted(), 403, 'This import has already been committed.');

        $data = $request->validate([
            'existing_user' => 'nullable|string|max:255',
        ]);

        $user = $this->validator->resolveExistingUser($data['existing_user'] ?? null);

        if (filled($data['existing_user'] ?? null) && $user === null) {
            return back()->withErrors([
                'existing_user' => "No active Quantum account matches '{$data['existing_user']}'. "
                    . 'Use the account email address or its numeric user ID.',
            ]);
        }

        $row->update([
            'parent_user_id'   => $user?->id,
            'link_to_existing' => $data['existing_user'] ?: null,
        ]);

        $this->validator->validate($import);

        return back()->with('success', $user === null
            ? "Leg {$row->external_user_id} disconnected."
            : "Leg {$row->external_user_id} will sit beneath {$user->name}.");
    }

    public function revalidate(PartnerImport $import)
    {
        abort_if($import->isCommitted(), 403, 'This import has already been committed.');

        $this->validator->validate($import);

        return back()->with('success', 'Re-checked.');
    }

    /**
     * Turn the batch into positions.
     *
     * Requires the filename typed back, which is the same guard a destructive
     * action deserves anywhere — except that here the action is not destructive
     * so much as permanent, which is worse. There is no undo for a committed
     * genealogy.
     */
    public function commit(Request $request, PartnerImport $import)
    {
        $request->validate(['confirm' => 'required|string']);

        if (trim($request->input('confirm')) !== $import->original_filename) {
            return back()->withErrors([
                'confirm' => 'Type the file name exactly to confirm: ' . $import->original_filename,
            ]);
        }

        try {
            $created = $this->committer->commit($import);
        } catch (RuntimeException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('admin.partners.imports.show', $import)
            ->with('success', "{$created} holding spot(s) created. They are in the tree now and "
                . 'hidden from team views until their owners claim them.');
    }

    /**
     * Resolve whatever the CSV proposed for each leg, before an admin looks.
     *
     * Only a starting point — every link is shown on the review page with the
     * name it resolved to, and an admin confirms or changes it. Doing it here
     * rather than making them type 40 email addresses is the difference between
     * a review they actually read and one they click through.
     */
    private function autoLinkTopRows(PartnerImport $import): void
    {
        foreach ($import->topRows()->whereNotNull('link_to_existing')->get() as $row) {
            $user = $this->validator->resolveExistingUser($row->link_to_existing);

            if ($user !== null) {
                $row->update(['parent_user_id' => $user->id]);
            }
        }
    }
}
