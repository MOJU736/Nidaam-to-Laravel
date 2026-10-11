<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentService;
use App\Services\Documents\EmailService;
use App\Services\Documents\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The PDF of a document, and sending it by email. The document's own rules and look live in DocumentService. */
class DocumentController extends Controller
{
    public function __construct(protected DocumentService $docs, protected EmailService $mail, protected PdfService $pdf) {}

    /** Statement dates come from the address as Y-m-d. */
    protected function opts(Request $request): array
    {
        $d = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : null;

        return ['start' => $d($request->query('startdate', $request->input('startdate'))), 'end' => $d($request->query('enddate', $request->input('enddate')))];
    }

    protected function built(string $type, int $id, Request $request): array
    {
        abort_unless(in_array($type, DocumentService::TYPES, true), 404);

        return $this->docs->build($type, $id, $this->opts($request)) ?? abort(404);
    }

    /** Shown in the browser (?download=1 saves it instead). */
    public function pdf(Request $request, string $type, int $id)
    {
        $b = $this->built($type, $id, $request);

        return $this->pdf->response($this->docs->pdfBytes($b), $b['file'], (bool) $request->query('download'));
    }

    public function email(Request $request, string $type, int $id)
    {
        abort_unless(in_array($type, DocumentService::EMAILABLE, true), 404);
        $b = $this->built($type, $id, $request);

        $badTo = $badCc = $badBcc = [];
        $to = $this->mail->addresses($request->input('email_to'), $badTo);
        $cc = $this->mail->addresses($request->input('email_cc'), $badCc);
        $bcc = $this->mail->addresses($request->input('email_bcc'), $badBcc);
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) $request->input('email_subject')));
        $message = trim((string) $request->input('email_message'));
        $bad = array_merge($badTo, $badCc, $badBcc);
        if ($bad) {
            $this->fail('Not a valid email address: '.implode(', ', array_slice($bad, 0, 5)).'. Nothing was sent.');
        }
        if (! $to) {
            $this->fail('Enter at least one email address in To. Nothing was sent.');
        }
        if (count($to) + count($cc) + count($bcc) > 20) {
            $this->fail('Too many addresses - up to 20 in To, CC and BCC together. Nothing was sent.');
        }
        if ($subject === '' || mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
            $this->fail('Enter a subject (up to 200 characters) and a message (up to 5000). Nothing was sent.');
        }

        $summary = $this->mail->send($to, $cc, $bcc, $subject, $this->mail->textToHtml($message), $this->docs->pdfBytes($b), $b['file']);

        // an address typed for a party with none on file can be kept for next time
        $mail = $b['mail'];
        if ($request->boolean('save_email') && empty($mail['has_email']) && ! empty($mail['party_table'])) {
            DB::connection('tenant')->table($mail['party_table'])->where('id', $mail['party_id'])->update(['email' => $to[0]]);
        }

        return back()->with('message', 'Emailed to '.$summary.'.');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
