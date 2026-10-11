<?php

namespace App\Services\Sales;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Documents\DocumentService;
use App\Services\Documents\EmailService;
use App\Services\Parties\PartyService;
use App\Support\Money;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Payment reminders: an email to a customer who owes money, with their statement of account attached. Ported from the legacy
 * Sales_model / Customers reminder code. Nothing goes out on its own: a person ticks customers and presses Send.
 *
 * What is overdue comes from the real per-invoice balances (payments applied to invoices), and is never more than the customer
 * owes overall: a payment received but not yet applied to an invoice does not make them look late. Invoices with no due date
 * never count as overdue; a balance with no invoice behind it (an opening balance) is included in the amount owed but not counted overdue.
 */
class RemindersService
{
    /** At most this many reminders in one send (each builds a PDF and talks to the mail server). */
    public const BATCH_LIMIT = 25;

    /** A customer reminded this recently starts unticked. */
    public const RECENT_DAYS = 7;

    public function __construct(protected Allocations $allocs, protected PartyService $parties, protected DocumentService $docs, protected EmailService $mail, protected SalesService $sales) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** The log table is missing in some older company databases; made the first time (outside any transaction). */
    public function ensureTable(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('payment_reminders')) {
            $schema->create('payment_reminders', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('customer_id');
                $t->dateTime('sent_at');
                $t->string('to_email', 255)->nullable();
                $t->decimal('balance', 25, 2)->default(0);
                $t->decimal('overdue_amount', 25, 2)->default(0);
                $t->integer('days_overdue')->default(0);
                $t->string('status', 20)->default('sent');
                $t->string('error', 500)->nullable();
                $t->bigInteger('user_id')->nullable();
                $t->index(['customer_id'], 'customer_id');
                $t->index(['sent_at'], 'sent_at');
            });
        }
    }

    // ---- who owes what, and how late ------------------------------------------------------------

    /** Overdue figures for one customer from their open invoices, capped at what they owe overall ($balance). */
    public function overdue(int $customerId, string $balance, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $amount = '0.00';
        $oldestDue = null;
        $oldestOpen = null;
        $list = [];
        foreach ($this->allocs->openDocs('ar', $customerId) as $d) {
            $date = substr((string) $d->date, 0, 10);
            if ($oldestOpen === null || $date < $oldestOpen) {
                $oldestOpen = $date;
            }
            $due = $d->due_date ? substr((string) $d->due_date, 0, 10) : null;
            if ($due && $due < $asOf) {
                $amount = Money::add($amount, $d->balance_due);
                $oldestDue = $oldestDue === null || $due < $oldestDue ? $due : $oldestDue;
                $list[] = ['id' => $d->id, 'label' => $d->label, 'due_date' => $due, 'unpaid' => $d->balance_due];
            }
        }
        $days = $oldestDue ? (int) floor((strtotime($asOf.' 00:00:00') - strtotime($oldestDue.' 00:00:00')) / 86400) : 0;
        $info = ['overdue_amount' => $amount, 'days_overdue' => max(0, $days), 'oldest_due_date' => $oldestDue, 'oldest_unpaid_date' => $oldestOpen, 'overdue_invoices' => $list];
        if (Money::cmp($amount, $balance) > 0) {
            if (Money::positive($balance)) {
                $info['overdue_amount'] = $balance;
            } else {
                $info = ['overdue_amount' => '0.00', 'days_overdue' => 0, 'oldest_due_date' => null, 'overdue_invoices' => []] + $info;
            }
        }

        return $info;
    }

    /** The most recent SUCCESSFUL reminder per customer: customer id => 'Y-m-d H:i:s'. A failed attempt doesn't count. */
    public function lastDates(?int $customerId = null): array
    {
        return $this->db()->table('payment_reminders')->where('status', 'sent')->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->groupBy('customer_id')->selectRaw('customer_id, MAX(sent_at) as last_sent')->pluck('last_sent', 'customer_id')->all();
    }

    protected function row(Customer $c, array $bal, array $last): array
    {
        $balance = $bal['dc'] === 'D' ? $bal['amount'] : '0.00';
        $info = $this->overdue((int) $c->id, $balance);

        return $info + ['customer_id' => (int) $c->id, 'customer_name' => $c->name, 'email' => $c->email, 'balance' => $balance, 'is_overdue' => Money::positive($info['overdue_amount']),
            'last_reminded_at' => $last[(int) $c->id] ?? null];
    }

    /** One customer's reminder figures (null if there is no such customer). */
    public function info(int $customerId): ?array
    {
        $c = Customer::find($customerId);
        if (! $c) {
            return null;
        }
        $bal = app(\App\Services\Accounting\LedgerBalances::class)->closingBalance((int) $c->ledger_id);

        return $this->row($c, $bal, $this->lastDates($customerId));
    }

    /** Every customer who owes money: overdue first (most days first), then the rest (largest balance first). */
    public function candidates(): array
    {
        $last = $this->lastDates();
        $rows = [];
        foreach ($this->parties->all('ar') as $c) {
            $b = $c->balance;
            if ($b['dc'] === 'D' && Money::positive($b['amount'])) {
                $rows[] = $this->row($c, $b, $last);
            }
        }
        usort($rows, fn ($a, $b) => $a['is_overdue'] !== $b['is_overdue'] ? ($a['is_overdue'] ? -1 : 1) : ($a['is_overdue'] ? $b['days_overdue'] <=> $a['days_overdue'] : Money::cmp($b['balance'], $a['balance'])));

        return $rows;
    }

    // ---- the message ----------------------------------------------------------------------------------

    public const DEFAULT_SUBJECT = 'Payment reminder from {company}';

    public const DEFAULT_BODY = "Hello {customer},\n\nThis is a friendly reminder that your account with {company} has an outstanding balance of {balance}.{overdue_line}\n\n{invoices_line}\n\nYour statement is attached. If you have already made this payment, please ignore this message.\n\nThank you,\n{company}";

    /** This company's own wording (or the defaults for whichever part is blank). */
    public function template(): array
    {
        $s = Setting::first();

        return ['subject' => trim((string) ($s->reminder_subject ?? '')) !== '' ? $s->reminder_subject : self::DEFAULT_SUBJECT, 'body' => trim((string) ($s->reminder_body ?? '')) !== '' ? $s->reminder_body : self::DEFAULT_BODY];
    }

    public function saveTemplate(?string $subject, ?string $body): void
    {
        $this->db()->table('settings')->update(['reminder_subject' => trim((string) $subject) ?: null, 'reminder_body' => trim((string) $body) ?: null]);
        ActivityLog::add('Updated the payment reminder message');
    }

    /** Fill the template for one customer: ['subject' => text, 'message' => plain text for the compose box]. */
    public function render(array $info): array
    {
        $t = $this->template();
        $parts = array_map(fn ($i) => $i['label'].' (due '.Money::date($i['due_date']).')', $info['overdue_invoices']);
        $overdueLine = $invoicesLine = '';
        if (Money::positive($info['overdue_amount'])) {
            $overdueLine = ' Of this, '.Money::format($info['overdue_amount']).' is past due - the oldest unpaid invoice was due on '.Money::date($info['oldest_due_date']).' ('.(int) $info['days_overdue'].' days ago).';
            $invoicesLine = 'Overdue invoice(s): '.implode(', ', $parts);
        }
        $vars = ['{overdue_line}' => $overdueLine, '{invoices_line}' => $invoicesLine, '{customer}' => $info['customer_name'], '{company}' => (string) Setting::first()->name, '{balance}' => Money::format($info['balance']),
            '{overdue_amount}' => Money::format($info['overdue_amount']), '{days_overdue}' => (string) (int) $info['days_overdue'], '{oldest_due_date}' => $info['oldest_due_date'] ? Money::date($info['oldest_due_date']) : '', '{invoices}' => implode(', ', $parts)];
        $message = preg_replace("/\n[ \t]*\n([ \t]*\n)+/", "\n\n", strtr(str_replace("\r\n", "\n", $t['body']), $vars)); // an empty {invoices_line} would leave a double gap

        return ['subject' => trim(str_replace(["\r", "\n"], ' ', strtr($t['subject'], $vars))), 'message' => trim($message)];
    }

    // ---- sending ----------------------------------------------------------------------------------------

    protected function log(array $info, ?string $to, string $status, ?string $error = null): void
    {
        $this->db()->table('payment_reminders')->insert(['customer_id' => $info['customer_id'], 'sent_at' => now(), 'to_email' => $to, 'balance' => $info['balance'], 'overdue_amount' => $info['overdue_amount'],
            'days_overdue' => $info['days_overdue'], 'status' => $status, 'error' => $error ? mb_substr($error, 0, 500) : null, 'user_id' => auth()->id()]);
    }

    /**
     * Email the reminder with the statement attached and log it. The statement starts at the oldest invoice still unpaid, so it shows
     * what is owed plus a brought-forward line instead of years of settled history. Returns the "who it went to" summary; on a
     * failure the attempt is logged and the reason thrown.
     *
     * @param  array|null  $message  ['subject' =>, 'message' =>] to send instead of the template (the check-before-send window)
     */
    public function deliver(array $info, array $to, array $cc = [], array $bcc = [], ?array $message = null): string
    {
        $m = $message ?? $this->render($info);
        $built = $this->docs->statement('ar', $info['customer_id'], ['start' => $info['oldest_unpaid_date'], 'end' => null]);
        try {
            $summary = $this->mail->send($to, $cc, $bcc, $m['subject'], $this->mail->textToHtml($m['message']), $this->docs->pdfBytes($built), $built['file']);
        } catch (ValidationException $e) {
            $this->log($info, implode(', ', $to), 'failed', (string) collect($e->errors())->flatten()->first());
            throw $e;
        }
        $this->log($info, implode(', ', $to), 'sent');
        ActivityLog::add('Sent payment reminder to '.$info['customer_name'].' ('.$summary.')');

        return $summary;
    }
}
