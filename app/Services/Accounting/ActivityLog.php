<?php

namespace App\Services\Accounting;

use App\Models\Tenant\Log;
use Illuminate\Support\Str;

/** The per-company activity log (legacy Settings_model::add_log). */
class ActivityLog
{
    public static function add(string $message, int $level = 1): void
    {
        $request = request();

        Log::create([
            'level' => $level,
            'date' => now()->format('Y-m-d H:i:s'),
            'host_ip' => Str::limit((string) $request->ip(), 25, ''),
            'user_id' => (string) ($request->user()?->id ?? ''),
            'url' => Str::limit($request->fullUrl(), 255, ''),
            'user_agent' => Str::limit((string) $request->userAgent(), 300, ''),
            'message' => Str::limit(strip_tags($message), 255, ''),
        ]);
    }
}
