<?php
/**
 * Servicio de tasa oficial BCV (USD -> VES).
 * - Consulta exclusivamente el sitio oficial del BCV.
 * - Cachea el último valor válido para no ralentizar cada request.
 * - Si BCV no responde, conserva la última tasa válida conocida.
 */

if (!defined('BCV_CACHE_TTL')) define('BCV_CACHE_TTL', 1800); // 30 min
if (!defined('BCV_URL')) define('BCV_URL', 'https://www.bcv.org.ve/seccionportal/tipo-de-cambio-oficial-del-bcv');
if (!defined('BCV_FALLBACK_URL')) define('BCV_FALLBACK_URL', 'https://www.bcv.org.ve/');

function bcv_cache_file(): string {
    $dir = dirname(__DIR__) . '/_private/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/bcv_usd.json';
}

function bcv_read_cache(): ?array {
    $file = bcv_cache_file();
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || empty($data['rate']) || !is_numeric($data['rate'])) return null;
    $data['rate'] = (float)$data['rate'];
    return $data;
}

function bcv_parse_rate(string $html): ?float {
    if ($html === '') return null;
    // Página principal BCV: bloque Drupal #dolar, normalmente con <strong>...</strong>.
    $patterns = [
        '/id=["\']dolar["\'][\s\S]{0,1200}?<strong[^>]*>\s*([0-9][0-9\.]*,[0-9]+)\s*<\/strong>/i',
        '/id=["\']dolar["\'][\s\S]{0,1200}?([0-9]{1,6}(?:\.[0-9]{3})*,[0-9]{2,8})/i',
        '/USD[\s\S]{0,800}?([0-9]{1,6}(?:\.[0-9]{3})*,[0-9]{2,8})/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $raw = str_replace('.', '', $m[1]);
            $raw = str_replace(',', '.', $raw);
            $rate = (float)$raw;
            if ($rate > 0 && $rate < 10000000) return $rate;
        }
    }
    return null;
}

function bcv_http_get(string $url): ?string {
    $ua = 'Mozilla/5.0 (compatible; VetNexo/1.0; +BCV-rate-cache)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $status >= 200 && $status < 400) return $body;
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'follow_location' => 1,
            'header' => "User-Agent: {$ua}\r\nAccept: text/html,application/xhtml+xml\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
}

function bcv_get_rate(bool $forceRefresh = false): array {
    static $memo = null;
    if (!$forceRefresh && is_array($memo)) return $memo;

    $cache = bcv_read_cache();
    $now = time();
    if (!$forceRefresh && $cache && !empty($cache['fetched_at'])) {
        $age = $now - (int)$cache['fetched_at'];
        if ($age >= 0 && $age < BCV_CACHE_TTL) {
            $cache['status'] = 'cache';
            return $memo = $cache;
        }
    }

    $attempt=dirname(bcv_cache_file()).'/bcv_last_attempt';
    if(getenv('AUTOVET_BCV_OFFLINE')==='1' || (!$forceRefresh && is_file($attempt) && time()-filemtime($attempt)<BCV_CACHE_TTL)){return $memo=array_merge($cache??['rate'=>0,'updated_at'=>null],['status'=>$cache?'stale':'unavailable']);}
    @touch($attempt);
    $html = bcv_http_get(BCV_URL);
    $rate = $html ? bcv_parse_rate($html) : null;
    if ($rate === null) {
        $html = bcv_http_get(BCV_FALLBACK_URL);
        $rate = $html ? bcv_parse_rate($html) : null;
    }
    if ($rate !== null) {
        $fresh = [
            'rate' => $rate,
            'currency' => 'VES',
            'source' => 'BCV',
            'source_url' => BCV_URL,
            'fetched_at' => $now,
            'updated_at' => date('c', $now),
            'status' => 'live',
        ];
        @file_put_contents(bcv_cache_file(), json_encode($fresh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $memo = $fresh;
    }

    if ($cache) {
        $cache['status'] = 'stale';
        return $memo = $cache;
    }

    // Sin inventar tasa: el sistema continúa en USD hasta obtener el primer valor oficial.
    return $memo = [
        'rate' => 0.0,
        'currency' => 'VES',
        'source' => 'BCV',
        'source_url' => BCV_URL,
        'fetched_at' => 0,
        'updated_at' => null,
        'status' => 'unavailable',
    ];
}

function bcv_usd_to_bs($usd): ?float {
    $rate = bcv_get_rate()['rate'] ?? 0;
    return $rate > 0 ? round(((float)$usd) * $rate, 2) : null;
}

function bcv_format_bs($usd): string {
    $bs = bcv_usd_to_bs($usd);
    return $bs === null ? 'Bs. —' : 'Bs. ' . number_format($bs, 2, ',', '.');
}


function bcv_badge_html(): string {
    $info = bcv_get_rate();
    $rate = (float)($info['rate'] ?? 0);
    $status = (string)($info['status'] ?? 'unavailable');
    $class = 'bcv-rate-badge';
    if ($status === 'stale' || $status === 'seeded_official') $class .= ' bcv-rate-stale';
    if ($rate <= 0) $class .= ' bcv-rate-unavailable';
    $label = $rate > 0
        ? (($status==='stale'||$status==='seeded_official')?'BCV · última disponible · 1 USD = ':'BCV · 1 USD = ') . number_format($rate, 4, ',', '.') . ' Bs'
        : 'BCV · tasa temporalmente no disponible';
    $title = !empty($info['updated_at']) ? 'Última tasa oficial disponible: ' . $info['updated_at'] : 'Consultar BCV';
    return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars(BCV_URL, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong><span aria-hidden="true">↗</span></a>';
}
