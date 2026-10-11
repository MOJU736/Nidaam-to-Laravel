<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Customer;
use App\Services\Documents\EmailService;
use App\Services\Sales\RemindersService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Payment reminders: every customer who owes money, with a tick box each. Nothing is sent until someone ticks and presses Send. */
class RemindersController extends Controller
{
    public function __construct(protected RemindersService $reminders, protected EmailService $mail)
    {
        $this->reminders->ensureTable();
    }

    public function index(Request $request)
    {
        $rows = $this->reminders->candidates();

        return view('sales.reminders', ['rows' => $rows, 'preview' => $rows ? $this->reminders->render($rows[0]) : null, 'previewFor' => $rows[0]['customer_name'] ?? null, 'template' => $this->reminders->template(),
            'results' => session('reminder_results'), 'recentDays' => RemindersService::RECENT_DAYS, 'limit' => RemindersService::BATCH_LIMIT, 'isAdmin' => $request->user()->isAdmin()]);
    }

    /** The saved wording (administrators). Blank boxes go back to the standard text. */
    public function message(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['reminder_subject' => ['nullable', 'string', 'max:255'], 'reminder_body' => ['nullable', 'string', 'max:3000']]);
        $this->reminders->saveTemplate($d['reminder_subject'] ?? null, $d['reminder_body'] ?? null);

        return redirect()->route('reminders.index')->with('message', 'Reminder message saved.');
    }

    /** The batch Send: every ticked customer is re-checked against fresh figures first (a payment may have come in since the page loaded). */
    public function send(Request $request)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('customer_ids')))));
        if (! $ids) {
            return back()->with('warning', 'Tick at least one customer to remind.');
        }
        if (count($ids) > RemindersService::BATCH_LIMIT) {
            return back()->with('warning', 'You can send up to '.RemindersService::BATCH_LIMIT.' reminders at a time. Untick some and send the rest afterwards.');
        }
        $bad = [];
        $cc = $this->mail->addresses($request->input('email_cc'), $b1);
        $bcc = $this->mail->addresses($request->input('email_bcc'), $b2);
        if ($b1 || $b2) {
            return back()->with('error', 'Not a valid email address: '.implode(', ', array_slice(array_merge($b1, $b2), 0, 5)).'. Nothing was sent.');
        }
        @set_time_limit(300);

        $current = collect($this->reminders->candidates())->keyBy('customer_id');
        $sent = 0;
        $failed = $skipped = [];
        foreach ($ids as $id) {
            $info = $current->get($id);
            if (! $info) {
                $skipped[] = (Customer::find($id)->name ?? "Customer #$id").' (no longer owes anything - a payment may have come in)';

                continue;
            }
            $to = $this->mail->addresses($info['email']);
            if (! $info['email']) {
                $skipped[] = $info['customer_name'].' (no email on file)';

                continue;
            }
            if (! $to) {
                $skipped[] = $info['customer_name'].' (the email on file is not valid)';

                continue;
            }
            try {
                $this->reminders->deliver($info, $to, $cc, $bcc);
                $sent++;
            } catch (ValidationException $e) {
                $err = (string) collect($e->errors())->flatten()->first();
                $failed[] = $info['customer_name'].': '.(strlen($err) > 160 ? substr($err, 0, 160).'...' : $err);
            }
        }
        $back = redirect()->route('reminders.index')->with('reminder_results', ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);

        return $sent > 0 ? $back->with('message', "Sent $sent payment reminder".($sent === 1 ? '' : 's').'.') : $back;
    }

    /** One customer, from their own page: the person has seen and may have edited the message. */
    public function one(Request $request, int $id)
    {
        $info = $this->reminders->info($id) ?? abort(404);
        if (! Money::positive($info['balance'])) {
            return back()->with('warning', $info['customer_name'].' has no balance to pay, so no reminder was sent.');
        }
        $bad = [];
        $to = $this->mail->addresses($request->input('email_to'), $b0);
        $cc = $this->mail->addresses($request->input('email_cc'), $b1);
        $bcc = $this->mail->addresses($request->input('email_bcc'), $b2);
        if ($b0 || $b1 || $b2) {
            return back()->withInput()->with('error', 'Not a valid email address: '.implode(', ', array_slice(array_merge($b0, $b1, $b2), 0, 5)).'. Nothing was sent.');
        }
        if (! $to) {
            return back()->withInput()->with('error', 'Enter at least one email address in To. Nothing was sent.');
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) $request->input('email_subject')));
        $message = trim((string) $request->input('email_message'));
        if ($subject === '' || mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
            return back()->withInput()->with('error', 'Enter a subject (up to 200 characters) and a message (up to 5000). Nothing was sent.');
        }
        $summary = $this->reminders->deliver($info, $to, $cc, $bcc, ['subject' => $subject, 'message' => $message]);
        if ($request->boolean('save_email') && empty($info['email'])) {
            Customer::whereKey($id)->update(['email' => $to[0]]);
        }

        return back()->with('message', 'Payment reminder emailed to '.$summary.'.');
    }
}
