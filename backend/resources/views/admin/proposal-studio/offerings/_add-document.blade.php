{{-- Add a document to the library: a file, or pasted text. --}}
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Add a document</h6></div>
            <div class="card-body">
                <p class="small f-light">Upload a file (PDF, Word, PowerPoint, text, HTML) or paste text: call notes, an email from {{ $offering->vendor_name }}, a page from their site. The assistant quotes claims from these word for word, so add the vendor's own wording, not a summary of it.</p>
                <form method="POST" action="{{ route('admin.proposal-studio.offerings.documents.store', $offering) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="doc-title">Title</label>
                            <input id="doc-title" name="title" class="form-control @error('title') is-invalid @enderror" maxlength="255" required value="{{ old('title') }}" placeholder="e.g. PRO In-Duct spec sheet, 2026">
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="doc-kind">Kind</label>
                            <select id="doc-kind" name="kind" class="form-select">
                                @foreach(\App\Models\OfferingDocument::KINDS as $key => $label)
                                    <option value="{{ $key }}" @selected(old('kind') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="doc-audience">Audience</label>
                            <select id="doc-audience" name="audience" class="form-select">
                                @foreach(\App\Models\OfferingDocument::AUDIENCES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('audience', 'internal') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="doc-url">Source link</label>
                            <input id="doc-url" type="url" name="source_url" class="form-control @error('source_url') is-invalid @enderror" maxlength="2048" value="{{ old('source_url') }}" placeholder="Where it came from, if online">
                            @error('source_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="doc-read">Read on</label>
                            <input id="doc-read" type="date" name="read_on" class="form-control" value="{{ old('read_on', now()->toDateString()) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="doc-file">File</label>
                            <input id="doc-file" type="file" name="file" class="form-control @error('file') is-invalid @enderror" accept=".{{ implode(',.', config('proposal-studio.upload_mimes')) }}">
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="doc-text">Or paste text</label>
                            <textarea id="doc-text" name="text" rows="5" class="form-control @error('text') is-invalid @enderror">{{ old('text') }}</textarea>
                            @error('text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm mt-3">Add to library</button>
                </form>
            </div>
        </div>
