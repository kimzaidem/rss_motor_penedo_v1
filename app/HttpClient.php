<?php
declare(strict_types=1);

final class HttpClient
{
    public static function fetch(string $url, string $cookies = '', string $userAgent = '', int $timeout = 30, int $maxBytes = 15728640): array
    {
        $url = trim($url);
        self::assertAllowedUrl($url);
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('Não foi possível iniciar o cURL.');
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent !== '' ? $userAgent : self::defaultUserAgent(),
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache',
            ],
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        if ($cookies !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        }
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($ok === false || $errno !== 0) {
            throw new RuntimeException('Falha ao acessar a URL: ' . ($error ?: 'cURL ' . $errno));
        }
        if ($status >= 400) {
            throw new RuntimeException('A URL respondeu com erro HTTP ' . $status . '.');
        }
        $body = self::normalizeEncoding($body, $type);
        return ['body' => $body, 'status' => $status, 'final_url' => $finalUrl ?: $url, 'content_type' => $type];
    }

    public static function defaultUserAgent(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 RSSMotorPenedo/2.0';
    }

    public static function absoluteUrl(string $base, string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('~^(javascript|mailto|tel|whatsapp):~i', $url)) {
            return '';
        }
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }
        $parts = parse_url($base);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            $basePath = $parts['path'] ?? '/';
            $dir = preg_replace('~/[^/]*$~', '/', $basePath) ?: '/';
            $path = $dir . $url;
        }
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
            } else {
                $segments[] = $seg;
            }
        }
        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }

    public static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('URL inválida. Use uma URL completa começando com http:// ou https://.');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Por segurança, apenas URLs http e https são permitidas.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || $host === '0.0.0.0' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Host local bloqueado.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isPrivateIp($host)) {
                throw new RuntimeException('IP interno/local bloqueado.');
            }
            return;
        }
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new RuntimeException('O domínio aponta para IP interno/local e foi bloqueado.');
            }
        }
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        return false;
    }

    private static function normalizeEncoding(string $body, string $contentType): string
    {
        $charset = '';
        if (preg_match('~charset=([a-zA-Z0-9_\-]+)~i', $contentType, $m)) {
            $charset = strtoupper($m[1]);
        } elseif (preg_match('~<meta[^>]+charset=["\']?([a-zA-Z0-9_\-]+)~i', $body, $m)) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== '' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return $body;
    }
}
