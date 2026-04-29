<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
Auth::require();
$url = trim((string)($_GET['url'] ?? ''));
$blockScripts = (int)($_GET['block_scripts'] ?? 1) === 1;
try {
    $response = HttpClient::fetch($url, '', HttpClient::defaultUserAgent(), 30, 15728640);
    $html = $response['body'];
    $base = '<base href="' . h($response['final_url']) . '">';
    if (stripos($html, '<head') !== false) {
        $html = preg_replace('/<head([^>]*)>/i', '<head$1>' . $base, $html, 1) ?? ($base . $html);
    } else {
        $html = $base . $html;
    }
    if ($blockScripts) {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
    }
    $inject = '<style id="rssmotor-proxy-style">.rssm-hover{outline:3px solid #7c5cff!important;outline-offset:2px!important}.rssm-selected{outline:4px solid #f4d35e!important;outline-offset:2px!important}.rssm-match{outline:3px solid #31c48d!important;outline-offset:2px!important}.rssm-block{outline:3px dashed #1c64f2!important;outline-offset:3px!important}</style>';
    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', $inject . '</head>', $html);
    } else {
        $html = $inject . $html;
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo $html;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Não foi possível carregar a página</h1><p>' . h($e->getMessage()) . '</p>';
}
