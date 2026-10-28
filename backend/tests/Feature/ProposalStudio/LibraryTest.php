<?php

namespace Tests\Feature\ProposalStudio;

use App\Models\KnowledgeClaim;
use App\Models\LibraryQuestion;
use App\Models\Offering;
use App\Models\StudioJob;
use App\Services\ProposalStudio\DocumentText;
use App\Services\ProposalStudio\Knowledge;
use App\Services\ProposalStudio\Prompts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** Phase 1: documents, passages, claims checked word for word, and questions to the library. */
class LibraryTest extends StudioTestCase
{
    public function test_documents_are_uploaded_with_their_text_or_pasted_and_cut_into_passages(): void
    {
        Storage::fake('local');
        $base = "/admin/proposal-studio/offerings/{$this->plasmaguard->id}";

        $this->actingAs($this->super)->post("{$base}/documents", [
            'title' => 'FAQ', 'kind' => 'faq', 'audience' => 'shareable',
            'file' => UploadedFile::fake()->createWithContent('faq.txt', "Q: Does it make ozone?\r\nA: See the UL report."),
        ])->assertSessionHas('success');
        $this->post("{$base}/documents", ['title' => 'Call notes', 'kind' => 'notes', 'audience' => 'internal', 'text' => 'Lead time is 2 weeks.'])->assertSessionHas('success');
        $this->post("{$base}/documents", ['title' => 'Nothing', 'kind' => 'notes', 'audience' => 'internal'])->assertSessionHasErrors(['file', 'text']);

        [$faq, $notes] = $this->plasmaguard->documents()->get()->all();
        $this->assertSame("Q: Does it make ozone?\nA: See the UL report.", $faq->extracted_text);
        Storage::disk('local')->assertExists($faq->file_path);
        $this->assertSame(1, $faq->passages()->count());
        $this->assertNull($notes->file_path);

        $this->patch("{$base}/documents/{$faq->id}", ['use_for_ai' => 0])->assertRedirect();
        $this->assertFalse($faq->fresh()->use_for_ai);

        $other = Offering::create(['key' => 'other', 'name' => 'Other', 'vendor_name' => 'Other Co']);
        $this->get("/admin/proposal-studio/offerings/{$other->id}/documents/{$faq->id}/download")->assertNotFound();

        $this->delete("{$base}/documents/{$faq->id}")->assertRedirect();
        Storage::disk('local')->assertMissing($faq->file_path);
    }

    public function test_slide_decks_are_read_slide_by_slide(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('ppt/slides/slide2.xml', '<p:sld><a:p><a:t>Second</a:t></a:p></p:sld>');
        $zip->addFromString('ppt/slides/slide1.xml', '<p:sld><a:p><a:t>Kills 99.9%</a:t></a:p><a:p><a:t>*Lab tested</a:t></a:p></p:sld>');
        $zip->close();

        $this->assertSame("Slide 1\nKills 99.9%\n*Lab tested\n\nSlide 2\nSecond", app(DocumentText::class)->extract($path, 'pptx'));
        unlink($path);
    }

    public function test_extracted_claims_are_kept_only_when_quoted_word_for_word(): void
    {
        $doc = $this->document("PlasmaGuard PRO reduces airborne mold by 99.4% in 60 minutes.*\n\n*Tested by an independent lab, 2024.");
        $this->actingAs($this->super)->post("/admin/proposal-studio/offerings/{$this->plasmaguard->id}/documents/{$doc->id}/extract")->assertSessionHas('success');

        $this->runnerAnswering(<<<'TXT'
```claims
[{"topic": "Mold", "quote": "PlasmaGuard PRO reduces airborne mold by 99.4% in 60 minutes.*", "footnote": "*Tested by an independent lab, 2024."},
 {"topic": "Invented", "quote": "PlasmaGuard PRO eliminates all viruses instantly.", "footnote": ""},
 {"topic": "Bad footnote", "quote": "reduces airborne mold by 99.4%", "footnote": "Certified by NASA"}]
```
TXT);
        $this->work();

        $claims = KnowledgeClaim::orderBy('id')->get();
        $this->assertCount(2, $claims);
        $this->assertSame('draft', $claims[0]->status);
        $this->assertSame('*Tested by an independent lab, 2024.', $claims[0]->footnote);
        $this->assertNull($claims[1]->footnote);
        $this->assertSame(['added' => 2, 'dropped' => 1], array_intersect_key(StudioJob::first()->meta, ['added' => 0, 'dropped' => 0]));
    }

    public function test_a_typed_claim_must_be_in_the_document_and_only_approved_claims_reach_the_prompt(): void
    {
        $doc = $this->document("It \u{2014} quietly \u{2014} removes odors in kitchens.");
        $this->actingAs($this->super)->post("/admin/proposal-studio/offerings/{$this->plasmaguard->id}/documents/{$doc->id}/claims", ['quote' => 'It removes all odors forever.'])
            ->assertSessionHas('error');
        // Dashes and spacing that extraction mangles still match.
        $this->post("/admin/proposal-studio/offerings/{$this->plasmaguard->id}/documents/{$doc->id}/claims", ['quote' => 'It - quietly -  removes odors in kitchens.'])
            ->assertSessionHas('success');

        KnowledgeClaim::create(['offering_id' => $this->plasmaguard->id, 'offering_document_id' => $doc->id, 'quote' => 'DRAFT CLAIM TEXT', 'status' => 'draft']);
        $block = app(Knowledge::class)->claimsBlock($this->plasmaguard);

        $this->assertStringContainsString('It - quietly -  removes odors in kitchens.', $block);
        $this->assertStringNotContainsString('DRAFT CLAIM TEXT', $block);
    }

    public function test_a_library_too_big_for_one_prompt_sends_the_passages_that_match(): void
    {
        $this->document(str_repeat("General filler about offices and staff.\n\n", 40), ['title' => 'Filler']);
        $this->document("Ozone: the unit produces no measurable ozone under UL 2998.\n\n".str_repeat("More unrelated text about shipping.\n\n", 40), ['title' => 'Safety']);
        $block = app(Knowledge::class)->documentsBlock($this->plasmaguard, 'Does it make ozone?', budget: 2500);

        $this->assertStringContainsString('too large to send whole', $block);
        $this->assertStringContainsString('no measurable ozone', $block);
        $this->assertStringNotContainsString('General filler', $block);
    }

    public function test_only_shareable_documents_are_used_for_prospects(): void
    {
        $this->document('INTERNAL MARGIN NOTES', ['title' => 'Margins']);
        $this->document('Public spec text.', ['title' => 'Spec', 'audience' => 'shareable']);
        $block = app(Knowledge::class)->documentsBlock($this->plasmaguard, 'spec', true);

        $this->assertStringContainsString('Public spec text.', $block);
        $this->assertStringNotContainsString('INTERNAL MARGIN NOTES', $block);
    }

    public function test_asking_the_library_queues_a_question_and_records_the_answer(): void
    {
        $this->document('Coverage is 3,000 sq ft per unit.');
        $this->actingAs($this->super)->post("/admin/proposal-studio/offerings/{$this->plasmaguard->id}/ask", ['question' => 'What does one unit cover?'])->assertRedirect();
        $question = LibraryQuestion::firstOrFail();
        $this->assertStringContainsString('Coverage is 3,000 sq ft per unit.', Prompts::libraryAnswer($question));

        $this->runnerAnswering('One unit covers 3,000 sq ft [Doc 1].');
        $this->work();

        $this->assertSame('One unit covers 3,000 sq ft [Doc 1].', $question->fresh()->answer);
        $this->get("/admin/proposal-studio/offerings/{$this->plasmaguard->id}?tab=ask")->assertOk()->assertSee('One unit covers 3,000 sq ft');
    }

    public function test_the_import_command_skips_files_already_in_the_library(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/ps-import-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/Zoom with Dean Rose.txt", str_repeat('Training talk about the product. ', 20));
        file_put_contents("{$dir}/video.mp4", 'not a document');

        $this->artisan('proposal-studio:import', ['path' => $dir])->assertSuccessful();
        $this->artisan('proposal-studio:import', ['path' => $dir])->expectsOutputToContain('already in the library')->assertSuccessful();

        $docs = $this->plasmaguard->documents()->get();
        $this->assertCount(1, $docs);
        $this->assertSame('training', $docs[0]->kind);
        $this->assertSame('Zoom with Dean Rose', $docs[0]->title);
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
}
