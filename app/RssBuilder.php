<?php
declare(strict_types=1);

final class RssBuilder
{
    public static function build(array $feed, array $items): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $rss->setAttribute('xmlns:media', 'http://search.yahoo.com/mrss/');
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        self::text($dom, $channel, 'title', (string)$feed['name']);
        self::text($dom, $channel, 'link', (string)($feed['site_url'] ?: $feed['list_url']));
        self::text($dom, $channel, 'description', 'Feed full-text gerado pelo RSS Motor Penedo');
        self::text($dom, $channel, 'language', 'pt-br');
        self::text($dom, $channel, 'lastBuildDate', date(DATE_RSS));

        $atom = $dom->createElement('atom:link');
        $atom->setAttribute('href', self::feedUrl($feed));
        $atom->setAttribute('rel', 'self');
        $atom->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atom);

        foreach ($items as $row) {
            $item = $dom->createElement('item');
            $channel->appendChild($item);

            $content = self::cleanContent((string)($row['content'] ?: $row['summary']));
            $summary = trim((string)($row['summary'] ?: mb_substr(strip_tags($content), 0, 300, 'UTF-8')));
            $image = self::cleanImage((string)($row['image'] ?? ''));

            self::text($dom, $item, 'title', (string)$row['title']);
            self::text($dom, $item, 'link', (string)$row['url']);
            self::text($dom, $item, 'guid', (string)$row['url']);

            if (!empty($row['published_at'])) {
                self::text($dom, $item, 'pubDate', date(DATE_RSS, strtotime((string)$row['published_at'])));
            }

            self::cdata($dom, $item, 'description', $summary);
            self::cdata($dom, $item, 'content:encoded', $content !== '' ? $content : $summary);

            if ($image !== '') {
                $media = $dom->createElement('media:content');
                $media->setAttribute('url', $image);
                $media->setAttribute('medium', 'image');
                $item->appendChild($media);

                $enclosure = $dom->createElement('enclosure');
                $enclosure->setAttribute('url', $image);
                $enclosure->setAttribute('type', self::mimeFromImage($image));
                $item->appendChild($enclosure);
            }
        }

        return $dom->saveXML() ?: '';
    }

    public static function feedUrl(array $feed): string
    {
        $url = base_url('feed.php?slug=' . rawurlencode((string)$feed['slug']));

        if ((int)($feed['require_token'] ?? 1) === 1) {
            $url .= '&token=' . rawurlencode((string)$feed['token']);
        }

        return $url;
    }

    private static function cleanContent(string $content): string
    {
        if (class_exists('Extractor') && method_exists('Extractor', 'cleanRssContent')) {
            return Extractor::cleanRssContent($content);
        }

        $content = preg_replace('~<img[^>]+src=["\'][^"\']*data:image/[^"\']*["\'][^>]*>~iu', '', $content) ?? $content;
        $content = preg_replace('~<p>\s*(?:<br\s*/?>)?\s*</p>~iu', '', $content) ?? $content;
        $content = preg_replace('~(?:<br\s*/?>\s*){3,}~iu', '<br><br>', $content) ?? $content;

        return trim($content);
    }

    private static function cleanImage(string $image): string
    {
        $image = trim(html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($image === '') {
            return '';
        }

        if (class_exists('Extractor') && method_exists('Extractor', 'isPublicImageUrl')) {
            return Extractor::isPublicImageUrl($image) ? $image : '';
        }

        if (str_contains(strtolower($image), 'data:image/')) {
            return '';
        }

        return $image;
    }

    private static function mimeFromImage(string $url): string
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: $url));

        return match (true) {
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.gif') => 'image/gif',
            str_ends_with($path, '.webp') => 'image/webp',
            str_ends_with($path, '.avif') => 'image/avif',
            default => 'image/jpeg',
        };
    }

    private static function text(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($dom->createElement($name, $value));
    }

    private static function cdata(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createCDATASection($value));
        $parent->appendChild($el);
    }
}