<?php
/* ======================================================================
   FILE: app-url.php
   ----------------------------------------------------------------------
   Builds verification URLs that work on PHONES (not localhost).
   - If you have a tunnel/prod domain, set PUBLIC_BASE_URL below.
   - If left blank, it auto-uses your PC’s LAN IP + port so phones on the
     same Wi-Fi can open the link (e.g., http://192.168.x.x/...).
====================================================================== */

if (!defined('PUBLIC_BASE_URL')) {
    // OPTIONAL: set when you use ngrok / Cloudflare Tunnel / prod
    // Example:
    // define('PUBLIC_BASE_URL', 'https://your-ngrok-domain/HTCCC-SYSTEM');
    define('PUBLIC_BASE_URL', '');
}

/** Choose a base URL that's reachable by phones. */
function htccc_public_base_url(): string {
    if (PUBLIC_BASE_URL !== '') return rtrim(PUBLIC_BASE_URL, '/');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $port   = (int)($_SERVER['SERVER_PORT'] ?? 0);

    $isLocal = preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~i', $host);
    if ($isLocal) {
        $ip = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
        if (!$ip || $ip === '127.0.0.1') $ip = '0.0.0.0'; // last resort; replace with your LAN IP if needed
        $portStr = ($port && !in_array($port, [80, 443], true)) ? (':'.$port) : '';
        return $scheme.'://'.$ip.$portStr;
    }

    $portStr = ($port && !in_array($port, [80, 443], true) && strpos($host, ':') === false) ? (':'.$port) : '';
    return $scheme.'://'.$host.$portStr;
}

/** Build a full verify URL for a token, keeping current subfolder. */
function htccc_verify_link(string $token, ?string $subdir = null): string {
    $base = htccc_public_base_url();
    $dir  = $subdir ?? (isset($_SERVER['PHP_SELF']) ? rtrim(dirname($_SERVER['PHP_SELF']), '/\\') : '');
    return $base . $dir . '/verify-email.php?token=' . urlencode($token);
}
