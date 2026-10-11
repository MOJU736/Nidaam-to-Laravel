<?php

namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/** HTML to PDF with dompdf (pure PHP, nothing to install on the server). */
class PdfService
{
    /** The PDF file's bytes for an HTML document. */
    public function fromHtml(string $html, string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // covers accented and non-Latin text
        $options->set('tempDir', storage_path('framework/cache'));
        $options->set('fontCache', storage_path('framework/cache'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($paper, $orientation);
        $pdf->render();

        return $pdf->output();
    }

    /** A browser response: shown inline (or downloaded). */
    public function response(string $bytes, string $filename, bool $download = false): Response
    {
        $safe = preg_replace('/[^\w\-. ()]+/u', '-', $filename);

        return new Response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$safe.'"', 'Content-Length' => (string) strlen($bytes)]);
    }
}
