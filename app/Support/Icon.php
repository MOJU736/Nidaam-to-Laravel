<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/** The small line icons of the Nidaam interface (24x24, stroke = the text colour). One place, so every page draws them the same. */
class Icon
{
    protected const PATHS = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.8"/><rect x="14" y="3" width="7" height="7" rx="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.8"/>',
        'sales' => '<path d="M6 2.5h8.5L19 7v14.5H6z"/><path d="M14 2.5V8h5M9.5 13h6M9.5 17h6"/>',
        'purchases' => '<path d="M5 8h14l-1 12.5H6z"/><path d="M9 8a3 3 0 016 0"/>',
        'customers' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18 14.4c2.2.7 3.5 2.6 3.5 5.6"/>',
        'vendors' => '<path d="M2 6.5h11v10H2zM13 9.5h4l4 4v3h-8z"/><circle cx="6.5" cy="18.5" r="1.8"/><circle cx="17.5" cy="18.5" r="1.8"/>',
        'items' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'commissions' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.6"/><circle cx="17" cy="17" r="2.6"/>',
        'projects' => '<path d="M3 21h18M5 21V9.5l7-5 7 5V21"/><path d="M10 21v-6h4v6"/>',
        'banking' => '<path d="M3 10l9-6 9 6M5.5 10v8M9.5 10v8M14.5 10v8M18.5 10v8M3 21h18"/>',
        'payroll' => '<path d="M3 7.5h16a2 2 0 012 2V18a2 2 0 01-2 2H5a2 2 0 01-2-2zM3 7.5L5.5 4h11"/><circle cx="16.5" cy="13.5" r="1.4"/>',
        'reports' => '<path d="M4 20V11M10 20V4M16 20v-6M22 20H2"/>',
        'settings' => '<path d="M4 6h9M19 6h1M4 12h3M13 12h7M4 18h11M21 18h-1"/><circle cx="16" cy="6" r="2.2"/><circle cx="10" cy="12" r="2.2"/><circle cx="18" cy="18" r="2.2"/>',
        'admin' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9.5C7.5 20 4 17 4 12V6z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
        'entries' => '<path d="M5 4h11a3 3 0 013 3v13H8a3 3 0 01-3-3z"/><path d="M5 17a3 3 0 013-3h11"/>',
        'accounts' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'datasharing' => '<path d="M7 3h7l4 4v14H7z"/><path d="M10 12h5v6h-5z"/>',
        'switch' => '<path d="M17 2.5l4 4-4 4M3 11V9.5a3 3 0 013-3h15M7 21.5l-4-4 4-4M21 13v1.5a3 3 0 01-3 3H3"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 13A9 9 0 1111 3a7 7 0 0010 10z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
    ];

    public static function svg(string $name, int $size = 20): HtmlString
    {
        return new HtmlString('<svg class="ic" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(self::PATHS[$name] ?? '').'</svg>');
    }

    /** The Nidaam mark: one rounded stroke (a noon) with its dot. Purple and emerald, or white and light emerald when $reversed. */
    public static function mark(int $size = 28, bool $reversed = false): HtmlString
    {
        [$stroke, $dot] = $reversed ? ['#ffffff', '#5DCAA5'] : ['var(--brand-strong)', 'var(--accent)'];

        return new HtmlString('<svg class="mark" width="'.$size.'" height="'.$size.'" viewBox="0 0 100 100" role="img" aria-label="Nidaam"><path d="M 20 38 Q 20 76 50 76 Q 80 76 80 38" fill="none" stroke="'.$stroke.'" stroke-width="9" stroke-linecap="round"/><circle cx="50" cy="18" r="8" fill="'.$dot.'"/></svg>');
    }
}
