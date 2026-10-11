<?php

namespace App\Services\Construction;

use App\Services\Accounting\ActivityLog;
use App\Services\Settings\SettingsService;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construction - the schedule (stages with planned dates, weights and % done: planned % against actual %) and the weekly site reports
 * (legacy Construction_model). A report fills in progress, manpower (wage sheets), materials used (site stock) and the week's costs by
 * itself; work done, equipment, quality, safety, challenges, actions, next week and photos are typed. The engineer prepares and submits;
 * a manager approves or returns it. An approved report is locked.
 */
class SiteService
{
    /** The standard 15 stages with default weights adding up to 100. */
    public const STANDARD_STAGES = [
        ['Planning, design & permits', 3], ['Site mobilisation & setting out', 2], ['Excavation & earthworks', 4], ['Foundations', 10], ['Ground floor slab', 6],
        ['Structure: columns, beams & slabs', 18], ['Blockwork & walls', 9], ['MEP rough-in (electrical, plumbing)', 8], ['Roofing', 6], ['Plastering & screeding', 7],
        ['Flooring & tiling', 7], ['Doors, windows & joinery', 5], ['Painting & finishes', 7], ['External works', 4], ['Testing, snagging & handover', 4],
    ];

    public const REPORT_STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'approved' => 'Approved'];

    public const REPORT_TEXT_FIELDS = ['weather' => 'Weather', 'work_done' => 'Work done this week', 'equipment' => 'Equipment on site', 'quality' => 'Quality', 'safety' => 'Health & safety',
        'challenges' => 'Challenges / delays', 'actions' => 'Actions needed', 'next_week' => 'Plan for next week'];

    public function __construct(protected EstimateService $estimates) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['projects' => $message]);
    }

    // ---- schedule -------------------------------------------------------------------------------------------------------

    public function stages(int $projectId)
    {
        return $this->db()->table('project_stages')->where('project_id', $projectId)->orderBy('sort')->orderBy('id')->get();
    }

    /** Fill a project's schedule with the 15 standard stages spread over its dates. */
    public function loadStandardStages(object $project): void
    {
        if ($this->db()->table('project_stages')->where('project_id', $project->id)->exists()) {
            $this->fail('The schedule already has stages.');
        }
        $start = ! empty($project->start_date) ? Carbon::parse($project->start_date)->startOfDay() : now()->startOfDay();
        $end = ! empty($project->end_date) ? Carbon::parse($project->end_date)->startOfDay() : $start->copy()->addMonths(12);
        if ($end->lte($start)) {
            $end = $start->copy()->addMonths(12);
        }
        $span = $end->diffInSeconds($start, true);
        $at = 0.0;
        foreach (self::STANDARD_STAGES as $i => [$name, $w]) {
            // each stage takes a share of the time like its weight, overlapping its neighbours a little
            $from = $start->copy()->addSeconds((int) ($span * max(0, $at - 2) / 100));
            $at += $w;
            $to = $start->copy()->addSeconds((int) ($span * min(100, $at + 1) / 100));
            $this->db()->table('project_stages')->insert(['project_id' => $project->id, 'sort' => $i, 'name' => $name, 'start_date' => $from->toDateString(), 'end_date' => $to->toDateString(), 'weight' => $w, 'percent_done' => 0]);
        }
        ActivityLog::add("Loaded the standard schedule into project #{$project->number}");
    }

    /** @param array $rows [[name, start_date, end_date, weight, percent_done, notes]] */
    public function saveStages(int $projectId, array $rows): void
    {
        $clean = [];
        foreach ($rows as $r) {
            $name = mb_substr(trim((string) ($r['name'] ?? '')), 0, 255);
            if ($name === '') {
                continue;
            }
            $s = trim((string) ($r['start_date'] ?? ''));
            $e = trim((string) ($r['end_date'] ?? ''));
            foreach ([$s, $e] as $dt) {
                if ($dt !== '' && ! SettingsService::isDate($dt)) {
                    $this->fail("\"$name\": enter real dates.");
                }
            }
            if ($s !== '' && $e !== '' && $e < $s) {
                $this->fail("\"$name\" ends before it starts.");
            }
            $w = trim((string) ($r['weight'] ?? '')) ?: '0';
            $pd = trim((string) ($r['percent_done'] ?? '')) ?: '0';
            if (! is_numeric($w) || (float) $w < 0) {
                $this->fail("\"$name\": the weight must be a number, 0 or more.");
            }
            if (! is_numeric($pd) || (float) $pd < 0 || (float) $pd > 100) {
                $this->fail("\"$name\": % done must be from 0 to 100.");
            }
            $clean[] = ['name' => $name, 'start_date' => $s ?: null, 'end_date' => $e ?: null, 'weight' => number_format((float) $w, 2, '.', ''), 'percent_done' => number_format((float) $pd, 2, '.', ''),
                'notes' => mb_substr(trim((string) ($r['notes'] ?? '')), 0, 500) ?: null];
        }
        $this->db()->transaction(function () use ($projectId, $clean) {
            $this->db()->table('project_stages')->where('project_id', $projectId)->delete();
            foreach ($clean as $i => $c) {
                $this->db()->table('project_stages')->insert(['project_id' => $projectId, 'sort' => $i] + $c);
            }
        });
    }

    /** Planned % and actual % of the whole job on $date (weights need not add to 100). planned/actual are null with no weights. */
    public function progress(int $projectId, ?string $date = null, $stages = null): array
    {
        $date ??= now()->toDateString();
        $stages ??= $this->stages($projectId);
        $tw = $planned = $actual = 0.0;
        $t = strtotime($date);
        foreach ($stages as $s) {
            $w = (float) $s->weight;
            $tw += $w;
            $actual += $w * (float) $s->percent_done / 100;
            if (! empty($s->start_date) && ! empty($s->end_date)) {
                $a = strtotime($s->start_date);
                $b = strtotime($s->end_date) + 86400;
                $planned += $w * ($t <= $a ? 0 : ($t >= $b ? 1 : ($t - $a) / ($b - $a)));
            }
        }
        if ($tw <= 0) {
            return ['planned' => null, 'actual' => null, 'stages' => count($stages)];
        }

        return ['planned' => round($planned / $tw * 100, 1), 'actual' => round($actual / $tw * 100, 1), 'stages' => count($stages)];
    }

    // ---- weekly site reports ---------------------------------------------------------------------------------------------

    public function reports(int $projectId)
    {
        return $this->db()->table('site_reports')->where('project_id', $projectId)->orderByDesc('week_start')->orderByDesc('id')->get();
    }

    public function report(int $id): ?object
    {
        $r = $this->db()->table('site_reports')->where('id', $id)->first();
        if ($r) {
            $r->auto = $r->auto_json ? json_decode($r->auto_json, true) : [];
            $r->photos = $this->db()->table('site_report_photos')->where('report_id', $id)->orderBy('id')->get();
        }

        return $r;
    }

    /** What the report fills in by itself for one week. */
    public function reportAuto(int $projectId, string $from, string $to): array
    {
        $auto = ['manpower' => [], 'materials' => [], 'costs' => [], 'stages' => [], 'workers' => 0, 'man_days' => 0];
        $rows = $this->db()->table('wage_lines as l')->join('wage_sheets as s', 's.id', '=', 'l.sheet_id')->leftJoin('workers as w', 'w.id', '=', 'l.worker_id')
            ->where('l.project_id', $projectId)->where('s.status', '!=', 'void')->where('s.date_from', '<=', $to)->where('s.date_to', '>=', $from)->get(['l.worker_id', 'l.days', 'w.trade']);
        $byTrade = [];
        $seen = [];
        foreach ($rows as $w) {
            $trade = trim((string) $w->trade) !== '' ? $w->trade : 'General';
            $byTrade[$trade]['workers'][(int) $w->worker_id] = 1;
            $byTrade[$trade]['days'] = ($byTrade[$trade]['days'] ?? 0) + (float) $w->days;
            $seen[(int) $w->worker_id] = 1;
            $auto['man_days'] += (float) $w->days;
        }
        foreach ($byTrade as $trade => $t) {
            $auto['manpower'][] = ['trade' => $trade, 'workers' => count($t['workers']), 'days' => round($t['days'], 1)];
        }
        $auto['workers'] = count($seen);
        foreach ($this->db()->table('stock_moves as m')->leftJoin('items as i', 'i.id', '=', 'm.item_id')->where('m.project_id', $projectId)->where('m.kind', 'issue')->where('m.status', 'posted')
            ->whereBetween('m.date', [$from, $to])->groupBy('m.item_id', 'i.name')->selectRaw('i.name, SUM(m.qty) qty, SUM(m.amount) amount')->get() as $m) {
            $auto['materials'][] = ['item' => $m->name, 'qty' => rtrim(rtrim(number_format((float) $m->qty, 2, '.', ''), '0'), '.'), 'amount' => Money::round($m->amount)];
        }
        foreach ($this->db()->table('entryitems as ei')->join('entries as e', 'e.id', '=', 'ei.entry_id')->where('ei.project_id', $projectId)->whereNotNull('ei.cost_type')->whereBetween('e.date', [$from, $to])
            ->groupBy('ei.cost_type')->selectRaw("ei.cost_type, SUM(CASE WHEN ei.dc = 'D' THEN ei.amount ELSE -ei.amount END) amt")->get() as $c) {
            $auto['costs'][$c->cost_type] = Money::round($c->amt);
        }
        foreach ($this->stages($projectId) as $s) {
            if (! empty($s->start_date) && ! empty($s->end_date) && $s->start_date <= $to && $s->end_date >= $from) {
                $auto['stages'][] = ['name' => $s->name, 'percent_done' => (float) $s->percent_done, 'end_date' => $s->end_date];
            }
        }
        $pr = $this->progress($projectId, $to);
        $auto['planned'] = $pr['planned'];
        $auto['actual'] = $pr['actual'];

        return $auto;
    }

    /** A new draft report for the week (Monday to Sunday) holding $date. Returns its id. */
    public function newReport(int $projectId, ?string $date): int
    {
        $t = $date && SettingsService::isDate($date) ? Carbon::parse($date) : ($date ? null : now());
        $t ?? $this->fail('Choose the week.');
        $from = $t->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $to = $t->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        if ($this->db()->table('site_reports')->where('project_id', $projectId)->where('week_start', $from)->exists()) {
            $this->fail('There is already a report for the week of '.Money::date($from).'.');
        }
        $num = (int) $this->db()->table('site_reports')->where('project_id', $projectId)->max('number') + 1;
        $auto = $this->reportAuto($projectId, $from, $to);

        return $this->db()->table('site_reports')->insertGetId(['project_id' => $projectId, 'number' => $num, 'week_start' => $from, 'week_end' => $to, 'status' => 'draft', 'planned_pct' => $auto['planned'],
            'actual_pct' => $auto['actual'], 'auto_json' => json_encode($auto), 'prepared_by' => $this->estimates->who(), 'created_at' => now()]);
    }

    public function saveReport(int $id, array $in): void
    {
        $r = $this->report($id) ?? $this->fail('That report no longer exists.');
        if ($r->status === 'approved') {
            $this->fail("An approved report can't be changed.");
        }
        $row = [];
        foreach (self::REPORT_TEXT_FIELDS as $k => $label) {
            $row[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, $k === 'weather' ? 255 : 5000) ?: null;
        }
        if (! empty($in['refresh'])) {
            $auto = $this->reportAuto((int) $r->project_id, substr((string) $r->week_start, 0, 10), substr((string) $r->week_end, 0, 10));
            $row += ['auto_json' => json_encode($auto), 'planned_pct' => $auto['planned'], 'actual_pct' => $auto['actual']];
        }
        $this->db()->table('site_reports')->where('id', $id)->update($row);
    }

    /** submit (engineer) | approve | return | reopen (managers). */
    public function reportStatus(int $id, string $action, string $note = ''): void
    {
        $r = $this->report($id) ?? $this->fail('That report no longer exists.');
        if ($action === 'submit') {
            if ($r->status !== 'draft') {
                $this->fail('Only a draft can be submitted.');
            }
            if (trim((string) $r->work_done) === '') {
                $this->fail('Write what was done this week before submitting.');
            }
            $this->db()->table('site_reports')->where('id', $id)->update(['status' => 'submitted', 'submitted_at' => now(), 'return_note' => null]);

            return;
        }
        if (! $this->estimates->canApprove()) {
            $this->fail('Only an administrator or manager can approve or return a site report.');
        }
        if ($action === 'approve') {
            if ($r->status === 'approved') {
                $this->fail('Already approved.');
            }
            $this->db()->table('site_reports')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $this->estimates->who(), 'approved_at' => now()]);
            ActivityLog::add("Approved weekly site report {$r->number} for project #{$r->project_id}");
        } elseif ($action === 'return') {
            if ($r->status !== 'submitted') {
                $this->fail('Only a submitted report can be returned.');
            }
            $note = mb_substr(trim($note), 0, 500);
            $note !== '' || $this->fail('Say what needs changing.');
            $this->db()->table('site_reports')->where('id', $id)->update(['status' => 'draft', 'return_note' => $note]);
        } elseif ($action === 'reopen') {
            if ($r->status !== 'approved') {
                $this->fail('Only an approved report can be reopened.');
            }
            $this->db()->table('site_reports')->where('id', $id)->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        } else {
            $this->fail('Unknown action.');
        }
    }

    public function deleteReport(int $id): int
    {
        $r = $this->report($id) ?? $this->fail('That report no longer exists.');
        if ($r->status === 'approved') {
            $this->fail("An approved report can't be deleted.");
        }
        foreach ($r->photos as $ph) {
            @unlink(public_path('uploads/site/'.$ph->file));
        }
        $this->db()->transaction(function () use ($id) {
            $this->db()->table('site_report_photos')->where('report_id', $id)->delete();
            $this->db()->table('site_reports')->where('id', $id)->delete();
        });

        return (int) $r->project_id;
    }

    /** Saves an uploaded photo (resized to 1280 px, JPEG) under public/uploads/site/. */
    public function addPhoto(int $id, UploadedFile $file, ?string $caption): void
    {
        $r = $this->report($id) ?? $this->fail('That report no longer exists.');
        if ($r->status === 'approved') {
            $this->fail("An approved report can't be changed.");
        }
        if ($r->photos->count() >= 8) {
            $this->fail('Up to 8 photos per report.');
        }
        if (! $file->isValid()) {
            $this->fail("\"{$file->getClientOriginalName()}\" did not upload (too big for the server?).");
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            $this->fail('That photo is too big (over 15 MB).');
        }
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $this->fail('Only JPG, PNG or WebP photos.');
        }
        $dir = public_path('uploads/site');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = 'r'.$id.'_'.bin2hex(random_bytes(6)).'.jpg';
        $ok = false;
        if (function_exists('imagecreatetruecolor')) {
            $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()), IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()), default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false };
            if ($src) {
                [$w, $h] = [imagesx($src), imagesy($src)];
                $scale = min(1, 1280 / max($w, $h));
                [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $ok = imagejpeg($dst, $dir.DIRECTORY_SEPARATOR.$name, 72);
                imagedestroy($src);
                imagedestroy($dst);
            }
        }
        if (! $ok) {
            if ($file->getSize() > 3 * 1024 * 1024) {
                $this->fail("This server can't shrink photos - use one under 3 MB.");
            }
            $name = preg_replace('/\.jpg$/', $info[2] === IMAGETYPE_PNG ? '.png' : ($info[2] === IMAGETYPE_WEBP ? '.webp' : '.jpg'), $name);
            $ok = (bool) @copy($file->getRealPath(), $dir.DIRECTORY_SEPARATOR.$name);
        }
        $ok || $this->fail('The photo could not be saved.');
        $this->db()->table('site_report_photos')->insert(['report_id' => $id, 'file' => $name, 'caption' => mb_substr(trim((string) $caption), 0, 255) ?: null, 'created_at' => now()]);
    }

    /** Returns the report id the photo belonged to. */
    public function deletePhoto(int $photoId): int
    {
        $ph = $this->db()->table('site_report_photos')->where('id', $photoId)->first() ?? $this->fail('That photo no longer exists.');
        $r = $this->report((int) $ph->report_id);
        if ($r && $r->status === 'approved') {
            $this->fail("An approved report can't be changed.");
        }
        @unlink(public_path('uploads/site/'.$ph->file));
        $this->db()->table('site_report_photos')->where('id', $photoId)->delete();

        return (int) $ph->report_id;
    }
}
