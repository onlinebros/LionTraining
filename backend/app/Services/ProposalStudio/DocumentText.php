<?php

namespace App\Services\ProposalStudio;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Plain text out of an uploaded document, once, at upload. The text is what
 * the assistant reads, so it is kept as close to the source wording as the
 * format allows: claims are quoted from it word for word.
 */
class DocumentText
{
    // A 300-page manual is about a million characters. Past this it is not
    // going into a prompt whole anyway.
    public const MAX_CHARS = 2_000_000;

    public function extract(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => $this->pdf($path),
            'docx' => $this->docx($path),
            'pptx' => $this->pptx($path),
            'html', 'htm' => $this->html((string) file_get_contents($path)),
            'txt', 'md', 'csv' => (string) file_get_contents($path),
            default => throw new RuntimeException("Text cannot be read from .{$extension} files."),
        };

        return self::clean($text);
    }

    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_CHARS);
    }

    private function pdf(string $path): string
    {
        $result = Process::timeout(120)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        if (! $result->successful()) {
            throw new RuntimeException('The PDF could not be read: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }
        if (trim($result->output()) === '') {
            throw new RuntimeException('The PDF has no text layer. It is probably a scan; paste its text in as a note instead.');
        }

        return $result->output();
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Word file could not be opened.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('The Word file has no document body.');
        }

        $xml = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // Each slide's text runs, in slide order, under a "Slide N" line.
    private function pptx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The PowerPoint file could not be opened.');
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/(slides/slide|notesSlides/notesSlide)(\d+)\.xml$#', $name, $m)) {
                $xml = (string) $zip->getFromIndex($i);
                $xml = preg_replace(['#</a:p>#', '#<a:br/>#'], ["\n", "\n"], $xml) ?? $xml;
                $text = trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($text !== '') {
                    $slides[(int) $m[2]][$m[1] === 'slides/slide' ? 0 : 1] = $text;
                }
            }
        }
        $zip->close();
        ksort($slides);

        $out = [];
        foreach ($slides as $n => $parts) {
            $out[] = "Slide {$n}\n".($parts[0] ?? '').(isset($parts[1]) ? "\n\nSpeaker notes:\n{$parts[1]}" : '');
        }

        return implode("\n\n", $out);
    }

    private function html(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
