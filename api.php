<?php
/**
 * SaveClip API — fetches video info and prepares MP4 downloads with yt-dlp + ffmpeg.
 *
 * Needs a server where you can install programs (Hostinger VPS, any Linux VPS). Shared hosting usually can't run yt-dlp.
 *   sudo apt install -y ffmpeg python3-pip && sudo pip3 install -U yt-dlp      (or: sudo curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && sudo chmod +x /usr/local/bin/yt-dlp)
 * Keep yt-dlp updated weekly (platforms change often):  sudo yt-dlp -U   or   sudo pip3 install -U yt-dlp
 *
 * Endpoints (POST, form-encoded):
 *   action=info     url=…              → {ok, title, thumbnail, duration, uploader, platform, heights[]}
 *   action=prepare  url=… q=1080       → {ok, token, filename, size}
 *   GET ?action=file&token=…           → streams the MP4 once, then deletes it
 */

/* ======================= settings ======================= */
define('YTDLP', getenv('YTDLP_BIN') ?: '/usr/local/bin/yt-dlp');   // `which yt-dlp`
const FFMPEG_DIR   = '/usr/bin';                // folder that contains ffmpeg (`which ffmpeg`)
define('MAX_DURATION', (int)(getenv('MAX_DURATION') ?: 3600));                     // seconds (1 hour). 0 = no limit
const MAX_FILESIZE = '2G';                      // yt-dlp --max-filesize
define('RATE_LIMIT', (int)(getenv('RATE_LIMIT') ?: 30));                      // requests per IP per hour
define('ALLOWED_ORIGINS', array_values(array_filter(array_map('trim', explode(',', (string)getenv('ALLOWED_ORIGINS'))))));                     // e.g. ['https://iamirsaleem.com'] if the page is on another domain; empty = same site only
const ALLOWED_HOSTS = ['youtube.com', 'youtu.be', 'instagram.com', 'facebook.com', 'fb.watch', 'fb.com', 'tiktok.com', 'twitter.com', 'x.com',
                       'vimeo.com', 'linkedin.com', 'pinterest.com', 'pin.it', 'reddit.com', 'redd.it', 'v.redd.it', 'dailymotion.com', 'dai.ly',
                       'twitch.tv', 'snapchat.com', 'threads.net', 'streamable.com', 'soundcloud.com'];
define('COOKIES_FILE', getenv('COOKIES_FILE') ?: '');                        // optional Netscape cookies.txt path (helps with Instagram/Facebook rate limits). Keep it OUTSIDE public_html.
/* ======================================================== */

const TMP = __DIR__ . '/tmp';
set_time_limit(900);
ignore_user_abort(true);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

if (ALLOWED_ORIGINS && in_array($_SERVER['HTTP_ORIGIN'] ?? '', ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { header('Access-Control-Allow-Methods: POST, GET'); exit; }
}

function out(array $d, int $code = 200): void {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
function fail(string $msg, int $code = 400): void {
    $d = ['ok' => false, 'error' => $msg];
    if (getenv('DEBUG_GET') === '1') { $d['status'] = $code; $d['raw'] = substr((string)($GLOBALS['LAST_ERR'] ?? ''), -1500); $code = 200; }
    out($d, $code);
}

if (!is_dir(TMP)) @mkdir(TMP, 0755, true);
if (!is_file(TMP . '/.htaccess')) @file_put_contents(TMP . '/.htaccess', "Require all denied\n");
cleanup();

$action = $_REQUEST['action'] ?? '';

/* ---------- stream a prepared file ---------- */
if ($action === 'file') {
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
    $meta = $t !== '' && is_file(TMP . "/$t.json") ? json_decode((string)file_get_contents(TMP . "/$t.json"), true) : null;
    if (!$meta || !is_file($meta['path'] ?? '')) { http_response_code(404); header('Content-Type: text/plain'); exit('This download link has expired. Please go back and try again.'); }
    $size = filesize($meta['path']);
    header('Content-Type: video/mp4');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $meta['ascii']) . '"; filename*=UTF-8\'\'' . rawurlencode($meta['filename']));
    header('Cache-Control: no-store');
    while (ob_get_level()) ob_end_clean();
    $fh = fopen($meta['path'], 'rb');
    while (!feof($fh)) { echo fread($fh, 1 << 20); flush(); if (connection_aborted()) break; }
    fclose($fh);
    if (!connection_aborted()) { @unlink($meta['path']); @unlink(TMP . "/$t.json"); }
    exit;
}

if ($action === 'selftest' && getenv('DEBUG_GET') === '1') {
    $tests = ['youtube' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw', 'vimeo' => 'https://vimeo.com/76979871',
              'facebook' => 'https://www.facebook.com/facebook/videos/10153231379946729/', 'tiktok' => 'https://www.tiktok.com/@scout2015/video/6718335390845095173'];
    $res = [];
    foreach ($tests as $k => $u) {
        [$c, $j, $e] = ytdlp(['-J', '--no-playlist', '--no-warnings', $u], 60);
        $d = json_decode($j, true);
        $res[$k] = $c === 0 && is_array($d) ? ['ok' => true, 'title' => $d['title'] ?? '', 'max_height' => max(array_map(fn($f) => (int)($f['height'] ?? 0), (array)($d['formats'] ?? [['height' => 0]])))] : ['ok' => false, 'err' => substr(trim($e), -300)];
    }
    out($res);
}
if ($action === 'health') {
    [$c, $v] = ytdlp(['--version'], 20);
    out(['ok' => $c === 0, 'yt_dlp' => trim($v), 'ffmpeg' => is_file(FFMPEG_DIR . '/ffmpeg')]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && getenv('DEBUG_GET') !== '1') fail('Method not allowed', 405);
rate_limit();

$url = trim((string)($_POST['url'] ?? ''));
$url = valid_url($url);

/* ---------- info ---------- */
if ($action === 'info') {
    [$code, $json, $err] = ytdlp(['-J', '--no-playlist', '--no-warnings', $url], 90);
    $d = json_decode($json, true);
    if ($code !== 0 || !is_array($d)) { $GLOBALS['LAST_ERR'] = $err; fail(friendly($err), 422); }
    if (MAX_DURATION && !empty($d['duration']) && $d['duration'] > MAX_DURATION) fail('This video is longer than ' . round(MAX_DURATION / 60) . ' minutes and can\'t be downloaded here.', 422);
    $heights = [];
    foreach ((array)($d['formats'] ?? []) as $f) {
        if (($f['vcodec'] ?? 'none') === 'none' || empty($f['height'])) continue;
        $w = (int)($f['width'] ?? 0); $h = (int)$f['height'];
        $heights[] = $w ? min($w, $h) : $h;   // vertical videos (Shorts/Reels): use the short side, e.g. 1080×1920 = 1080p
    }
    if (!$heights && !empty($d['height'])) $heights[] = (int)(!empty($d['width']) ? min($d['width'], $d['height']) : $d['height']);
    $heights = array_values(array_unique($heights)); sort($heights);
    out(['ok' => true, 'title' => (string)($d['title'] ?? ''), 'thumbnail' => (string)($d['thumbnail'] ?? ''), 'duration' => (float)($d['duration'] ?? 0),
         'uploader' => (string)($d['uploader'] ?? $d['channel'] ?? ''), 'platform' => (string)($d['extractor_key'] ?? ''), 'heights' => $heights]);
}

/* ---------- prepare MP4 ---------- */
if ($action === 'prepare') {
    $q = (int)($_POST['q'] ?? 1080);
    if ($q < 144 || $q > 4320) $q = 1080;
    $token = bin2hex(random_bytes(16));
    $base = TMP . "/$token";
    // short side ≤ q, prefer H.264 + AAC (plays everywhere), fall back to best available, always output MP4
    $fmt = "bv*[vcodec^=avc1][height<=$q]+ba[ext=m4a]/bv*[vcodec^=avc1][width<=$q]+ba[ext=m4a]/bv*[height<=$q]+ba/bv*[width<=$q]+ba/b[height<=$q]/b";
    $args = ['--no-simulate', '--no-playlist', '--no-warnings', '--no-part', '-f', $fmt, '-S', "res:$q,vcodec:h264,acodec:m4a",
             '--merge-output-format', 'mp4', '--remux-video', 'mp4', '--max-filesize', MAX_FILESIZE,
             '--ffmpeg-location', FFMPEG_DIR, '-o', $base . '.%(ext)s', '--print', 'after_move:filepath', '--print', 'before_dl:title', $url];
    if (MAX_DURATION) array_splice($args, 0, 0, ['--match-filter', 'duration<=' . MAX_DURATION . ' | !duration']);
    [$code, $stdout, $err] = ytdlp($args, 840);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
    $path = '';
    foreach (array_reverse($lines) as $l) if (str_starts_with($l, TMP) && is_file($l)) { $path = $l; break; }
    if (!$path) foreach (glob($base . '.*') ?: [] as $g) if (!str_ends_with($g, '.json')) $path = $g;
    if ($code !== 0 || !$path || !is_file($path)) { foreach (glob($base . '*') ?: [] as $g) @unlink($g); fail(friendly($err), 422); }
    $title = $lines[0] ?? 'video';
    if (str_starts_with($title, TMP)) $title = 'video';
    $nice = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $title)) ?: 'video';
    $nice = mb_substr($nice, 0, 80) . " ({$q}p) - SaveClip.mp4";
    $ascii = preg_replace('/[^A-Za-z0-9 ._()-]+/', '', $nice) ?: "saveclip-{$q}p.mp4";
    file_put_contents("$base.json", json_encode(['path' => $path, 'filename' => $nice, 'ascii' => $ascii, 'created' => time()]));
    out(['ok' => true, 'token' => $token, 'filename' => $nice, 'size' => filesize($path)]);
}

fail('Unknown action', 404);

/* ======================= helpers ======================= */
function valid_url(string $u): string {
    if ($u === '' || strlen($u) > 2000 || !filter_var($u, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $u)) fail('Please paste a valid video link (https://…).');
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    foreach (ALLOWED_HOSTS as $ok) if ($host === $ok || str_ends_with($host, '.' . $ok)) return $u;
    fail('This website is not supported yet. Try YouTube, Instagram, Facebook, TikTok, X, Vimeo and more.');
    return '';
}

/** Run yt-dlp WITHOUT a shell (no command injection). Returns [exitCode, stdout, stderr]. */
function ytdlp(array $args, int $timeout): array {
    if (!function_exists('proc_open')) fail('This server can\'t run the download engine (proc_open is disabled). Host api.php on a VPS.', 503);
    if (!is_executable(YTDLP)) fail('The download engine is not installed on the server yet.', 503);
    if (COOKIES_FILE && is_file(COOKIES_FILE)) array_unshift($args, '--cookies', COOKIES_FILE);
    array_unshift($args, YTDLP, '--ignore-config', '--no-cache-dir', '--socket-timeout', '20', '--retries', '3');
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, TMP, ['PATH' => FFMPEG_DIR . ':/usr/local/bin:/usr/bin:/bin', 'HOME' => TMP, 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) fail('Could not start the download engine.', 500);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = $err = ''; $start = time();
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (time() - $start > $timeout) { proc_terminate($p, 9); fail('This is taking too long — try a lower quality or a shorter video.', 504); }
        usleep(150000);
    }
    $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if ($code === -1 && isset($st['exitcode'])) $code = $st['exitcode'];
    return [$code, $out, $err];
}

function friendly(string $err): string {
    $e = strtolower($err);
    return match (true) {
        str_contains($e, 'private') || str_contains($e, 'login') || str_contains($e, 'sign in') || str_contains($e, 'cookies') => 'This video is private or needs a login, so it can\'t be downloaded.',
        str_contains($e, 'age') && str_contains($e, 'restrict') => 'This video is age-restricted and can\'t be downloaded.',
        str_contains($e, 'unavailable') || str_contains($e, 'not found') || str_contains($e, '404') || str_contains($e, 'removed') => 'This video is unavailable or was removed.',
        str_contains($e, 'unsupported url') => 'This link type isn\'t supported. Paste the direct link to the video post.',
        str_contains($e, 'max-filesize') || str_contains($e, 'larger than max') => 'This video is too large at that quality — try a lower one.',
        str_contains($e, 'does not pass filter') => 'This video is too long to download here.',
        str_contains($e, 'rate') && str_contains($e, 'limit') => 'The platform is busy right now. Please try again in a few minutes.',
        str_contains($e, 'live') => 'Live streams can\'t be downloaded until they end.',
        default => 'We couldn\'t get this video. Check the link is public and try again.',
    };
}

function rate_limit(): void {
    $fwd = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    $ip = preg_replace('/[^0-9a-f.:]/i', '', $fwd !== '' ? $fwd : (string)($_SERVER['REMOTE_ADDR'] ?? 'x'));
    $f = TMP . '/rl-' . md5($ip) . '.txt'; $now = time();
    $hits = is_file($f) ? array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), fn($t) => $t > $now - 3600) : [];
    if (count($hits) >= RATE_LIMIT) fail('Too many requests from your network. Please try again in a little while.', 429);
    $hits[] = $now; file_put_contents($f, implode(',', $hits), LOCK_EX);
}

/** Delete prepared files older than 1 hour (and old rate-limit files) */
function cleanup(): void {
    if (mt_rand(1, 10) !== 1) return;
    foreach (glob(TMP . '/*') ?: [] as $f) if (is_file($f) && basename($f) !== '.htaccess' && filemtime($f) < time() - 3600) @unlink($f);
}
