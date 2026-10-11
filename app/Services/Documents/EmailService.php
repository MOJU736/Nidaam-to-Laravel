<?php

namespace App\Services\Documents;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Sends a document (a PDF) by email through the company's own SMTP account, or the shared default set in the main settings.
 * Ported from the legacy FunctionsCore::sendDocumentEmail(). Nothing is ever sent on its own: a person clicks Send.
 */
class EmailService
{
    /** "a@x.com, b@y.com; c@z.com" -> valid addresses; the bad ones in $bad. */
    public function addresses(?string $raw, ?array &$bad = null): array
    {
        $bad = [];
        $out = [];
        foreach (preg_split('/[,;\s]+/', (string) $raw) as $e) {
            $e = trim($e, " \t\n\r\0\x0B<>\"'");
            if ($e === '') {
                continue;
            }
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && preg_match('/@[^@\s]+\.[a-z]{2,}$/i', $e)) {
                if (! in_array(strtolower($e), array_map('strtolower', $out), true)) {
                    $out[] = $e;
                }
            } else {
                $bad[] = $e;
            }
        }

        return $out;
    }

    /** Plain text from the message box as the email's HTML body. */
    public function textToHtml(string $text): string
    {
        $html = '';
        foreach (preg_split("~\n{2,}~", trim(str_replace("\r\n", "\n", $text))) as $para) {
            if (trim($para) !== '') {
                $html .= '<p>'.nl2br(e(trim($para))).'</p>';
            }
        }

        return $html;
    }

    /** The SMTP account to send with: the company's own, or the shared default. Throws a plain explanation when there isn't one. */
    public function sender(): array
    {
        $s = Setting::first();
        $useDefault = ! empty($s->email_use_default) || empty($s->email_host);
        if ($useDefault) {
            $core = DB::connection('master')->table('settings')->first();
            if (! $core || empty($core->smtp_host)) {
                $this->fail('No email sender is set up yet. Add your SMTP details under Settings → Email, or ask an admin to set the default.');
            }
            $c = ['protocol' => $core->email_protocol === 'mail' ? 'mail' : 'smtp', 'host' => $core->smtp_host, 'port' => (int) ($core->smtp_port ?: 587), 'username' => $core->smtp_username,
                'password' => $core->smtp_password, 'tls' => ! empty($core->smtp_tls), 'from' => $core->email_from ?: $core->smtp_username];
        } else {
            $c = ['protocol' => $s->email_protocol === 'mail' ? 'mail' : 'smtp', 'host' => $s->email_host, 'port' => (int) ($s->email_port ?: 587), 'username' => $s->email_username,
                'password' => $s->email_password, 'tls' => ! empty($s->email_tls), 'from' => $s->email_from ?: $s->email_username];
            if ($c['protocol'] === 'smtp' && (empty($c['host']) || empty($c['username']) || empty($c['password']) || empty($c['from']))) {
                $this->fail('Email sending is not fully set up for this company yet. Fill in your SMTP details under Settings → Email (or tick "Use the default").');
            }
        }
        if ($c['protocol'] === 'mail') {
            $this->fail('This company is set to PHP mail(), which the new system does not use. Choose SMTP under Settings → Email and fill in the details.');
        }

        return $c;
    }

    /**
     * Send one email with a PDF attached. $to / $cc / $bcc are address lists. Returns a short summary of who it went to.
     * Port 465 is implicit SSL (the encrypted connection starts at once); other ports use STARTTLS when "TLS" is ticked.
     */
    public function send(array $to, array $cc, array $bcc, string $subject, string $bodyHtml, string $pdfBytes, string $filename): string
    {
        $c = $this->sender();
        $company = Setting::first();
        $mailer = Mail::build([
            'transport' => 'smtp', 'host' => $c['host'], 'port' => $c['port'], 'username' => $c['username'], 'password' => $c['password'],
            'scheme' => $c['port'] === 465 ? 'smtps' : null, 'timeout' => 20,
        ]);
        $replyTo = trim((string) ($company->email ?? ''));
        try {
            $mailer->html($bodyHtml, function ($m) use ($c, $company, $to, $cc, $bcc, $subject, $pdfBytes, $filename, $replyTo) {
                $m->from($c['from'], (string) ($company->name ?? ''))->to($to)->subject($subject)->attachData($pdfBytes, $filename, ['mime' => 'application/pdf']);
                if ($cc) {
                    $m->cc($cc);
                }
                if ($bcc) {
                    $m->bcc($bcc);
                }
                // replies go to the company, not to a shared sending mailbox
                if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && strcasecmp($replyTo, $c['from']) !== 0) {
                    $m->replyTo($replyTo, (string) ($company->name ?? ''));
                }
            });
        } catch (\Throwable $e) {
            $this->fail('The email could not be sent: '.substr($e->getMessage(), 0, 300));
        }
        $summary = implode(', ', $to).($cc ? ' (cc '.implode(', ', $cc).')' : '').($bcc ? ' (bcc '.implode(', ', $bcc).')' : '');
        ActivityLog::add("Emailed $filename to $summary");

        return $summary;
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
