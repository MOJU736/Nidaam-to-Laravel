<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Documents\DocumentService;
use App\Services\Documents\EmailService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings → Email: the SMTP account this company sends documents with (or the shared default). Administrators only. */
class EmailSettingsController extends Controller
{
    public function __construct(protected EmailService $mail, protected DocumentService $docs) {}

    public function edit(Request $request)
    {
        $core = DB::connection('master')->table('settings')->first();

        return view('settings.email', ['s' => Setting::first(), 'defaultReady' => (bool) ($core->smtp_host ?? null)]);
    }

    /** "Always copy emails to": up to 5 addresses put in the CC box of every Send-by-email window (still removable there). */
    public function updateCc(Request $request)
    {
        $list = $this->mail->addresses($request->input('email_cc_default'), $bad);
        if ($bad) {
            return redirect()->to(route('settings.email').'#email-cc')->withInput()->with('error', 'Not a valid email address: '.implode(', ', array_slice($bad, 0, 5)).'. Nothing was saved.');
        }
        if (count($list) > 5) {
            return redirect()->to(route('settings.email').'#email-cc')->withInput()->with('error', 'Up to 5 addresses. Nothing was saved.');
        }
        if (! Schema::connection('tenant')->hasColumn('settings', 'email_cc_default')) {
            if (! $list) {
                return redirect()->to(route('settings.email').'#email-cc')->with('message', 'Emails are not copied to anyone by default.');
            }
            DB::connection('tenant')->statement('ALTER TABLE `settings` ADD COLUMN `email_cc_default` VARCHAR(500) NULL DEFAULT NULL'); // made the first time an address is saved
        }
        DB::connection('tenant')->table('settings')->update(['email_cc_default' => $list ? implode(', ', $list) : null]);
        ActivityLog::add('Changed "Always copy emails to" to '.($list ? implode(', ', $list) : 'nobody'));

        return redirect()->to(route('settings.email').'#email-cc')->with('message', $list ? 'Every email box will copy '.implode(', ', $list).'.' : 'Emails are no longer copied to anyone by default.');
    }

    public function update(Request $request)
    {
        $d = $request->validate(['email_host' => ['nullable', 'string', 'max:255'], 'email_port' => ['nullable', 'integer', 'min:1', 'max:65535'], 'email_username' => ['nullable', 'string', 'max:255'],
            'email_password' => ['nullable', 'string', 'max:255'], 'email_from' => ['nullable', 'email', 'max:255']]);
        $row = ['email_use_default' => $request->boolean('email_use_default') ? 1 : 0, 'email_protocol' => 'smtp', 'email_host' => $d['email_host'] ?? '', 'email_port' => $d['email_port'] ?? 587,
            'email_tls' => $request->boolean('email_tls') ? 1 : 0, 'email_username' => $d['email_username'] ?? '', 'email_from' => $d['email_from'] ?? ''];
        // a blank password box keeps the saved one
        if (($d['email_password'] ?? '') !== '') {
            $row['email_password'] = $d['email_password'];
        }
        DB::connection('tenant')->table('settings')->update($row);
        ActivityLog::add('Updated email settings');

        if ($request->input('then') === 'test') {
            $to = $this->mail->addresses($request->input('test_to'));
            if (! $to) {
                return redirect()->route('settings.email')->with('error', 'Settings saved, but enter an address to send the test to.');
            }
            $pdf = $this->docs->tablePdf([[Setting::first()->name ?? ''], ['Test email'], ['If you can read this, email sending works.']]);
            $summary = $this->mail->send($to, [], [], 'Test email from '.(Setting::first()->name ?? 'your company'), '<p>This is a test email. If you can read it, sending documents by email works.</p>', $pdf, 'Test.pdf');

            return redirect()->route('settings.email')->with('message', 'Settings saved. Test email sent to '.$summary.'.');
        }

        return redirect()->route('settings.email')->with('message', 'Email settings saved.');
    }
}
