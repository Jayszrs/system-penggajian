<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = '', array $query = []): string { return '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : ''); }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function flash(string $type, string $message): void { $_SESSION['flash'][] = compact('type', 'message'); }
function money(mixed $value): string {
    $s = (string) ($value ?? '0'); $negative = str_starts_with($s, '-'); $s = ltrim($s, '-');
    [$whole] = explode('.', $s); $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole ?: '0');
    return ($negative ? '-Rp ' : 'Rp ') . $whole;
}
function month_name(int $month): string { return [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month] ?? ''; }
function selected(mixed $a, mixed $b): string { return (string) $a === (string) $b ? ' selected' : ''; }
function json_response(array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR); exit;
}
function icon(string $name, string $class = ''): string {
    $paths = [
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M15 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'building'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4M8 7h1m6 0h1M8 11h1m6 0h1"/>',
        'wallet'=>'<path d="M20 8V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v11H5a3 3 0 0 1-3-3V6"/><path d="M20 12h-5v5h5M17 14.5h.01"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>', 'minus'=>'<path d="M5 12h14"/>',
        'receipt'=>'<path d="m4 3 3 2 3-2 3 2 3-2 4 2v17l-4-2-3 2-3-2-3 2-3-2zM8 9h8M8 13h8M8 17h5"/>',
        'chart'=>'<path d="M3 3v18h18M8 16v-5m5 5V7m5 9v-8"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2"/>',
        'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>', 'chevron-down'=>'<path d="m6 9 6 6 6-6"/>',
        'search'=>'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18m-12 4h.01M12 15h.01M16 15h.01"/>',
        'arrow-up'=>'<path d="m7 14 10-10M7 4h10v10M4 20h16"/>', 'arrow-right'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'shield'=>'<path d="m12 3 8 4v5c0 5-8 9-8 9s-8-4-8-9V7z"/><path d="m8 12 3 3 5-6"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>', 'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'edit'=>'<path d="m16 3 5 5-12 12H4v-5zM13 6l5 5"/>',
        'trash'=>'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v6m4-6v6"/>',
        'print'=>'<path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6zM17 11h.01"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'leaf'=>'<path d="M20 3c0 0 2 12-5 16a8 8 0 0 1-10-2C0 9 11 4 20 3Z"/><path d="M4 21 16 8"/>',
        'lock'=>'<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'save'=>'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
        'database'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
    ];
    return '<svg class="icon ' . e($class) . '" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
}
