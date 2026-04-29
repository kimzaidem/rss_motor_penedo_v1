<?php
declare(strict_types=1);

final class Extractor
{
    /**
     * Inicializa e retorna o DOMDocument e DOMXPath a partir de uma string HTML.
     */
    public static function dom(string $html): array
    {
        $previousState = libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        // Remove charset meta tag para evitar conflitos de codificação do libxml
        $html = preg_replace('/<meta[^>]+charset=[^>]+>/i', '', $html) ?? $html;
        
        // Carrega o HTML suprimindo erros de formatação comum na web
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html, 
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT
        );

        // Remove a instrução de processamento XML adicionada acima
        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
                break;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        return [$dom, new DOMXPath($dom)];
    }

    /**
     * Extrai uma lista de itens a partir da URL do feed/lista.
     */
    public static function extractFromList(array $feed, bool $preview = false): array
    {
        $response = HttpClient::fetch((string)$feed['list_url'], (string)($feed['cookies'] ?? ''), (string)($feed['user_agent'] ?? ''));

        [$dom, $xp] = self::dom($response['body']);

        self::removeSelectorNoise($dom, false);
        self::absolutize($dom, $response['final_url']);

        $blocks = self::findBlocks($xp, $dom, $feed, $response['final_url']);
        $items = [];
        $seen = [];
        $max = max(1, min(100, (int)($feed['max_items'] ?? 20)));

        foreach ($blocks as $block) {
            if (count($items) >= $max) {
                break;
            }

            $item = self::extractItem($xp, $dom, $block, $response['final_url'], $feed);

            if ($item['url'] === '' || !preg_match('~^https?://~i', $item['url']) || self::sameCleanUrl($item['url'], $response['final_url']) || self::isBadArticleUrl($item['url'])) {
                continue;
            }

            $key = sha1($item['url']);

            if (isset($seen[$key])) {
                continue;
            }

            if (trim($item['title']) === '' || mb_strlen($item['title'], 'UTF-8') < 4) {
                continue;
            }

            if (!self::passesFilters($item, $feed)) {
                continue;
            }

            $seen[$key] = true;

            if ((int)($feed['full_text'] ?? 1) === 1) {
                try {
                    $full = self::fullArticle($item['url'], $feed);

                    if ($full['content'] !== '') {
                        $item['content'] = $preview ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($full['content'])) ?? ''), 0, 900, 'UTF-8') : $full['content'];
                    }

                    if ($full['image'] !== '' && $item['image'] === '') {
                        $item['image'] = $full['image'];
                    }
                } catch (Throwable $e) {
                    if ($item['content'] === '') {
                        $item['content'] = $item['summary'];
                    }
                }
            }

            $items[] = $item;
        }

        if (!$items) {
            $items = self::extractFromGlobalLinks($xp, $dom, $response['final_url'], $feed, $max, $preview);
        }

        if (!$items) {
            $debug = self::debugCounts($xp, $dom, $feed);
            throw new RuntimeException('Nenhum item foi encontrado. Contagem dos seletores: ' . $debug . '. Abra o seletor, clique em Título e Link, veja se vários itens ficam destacados e teste antes de salvar.');
        }

        return $items;
    }

    /**
     * Acessa a URL do artigo completo e extrai o conteúdo principal e imagem.
     */
    public static function fullArticle(string $url, array $feed): array
    {
        $response = HttpClient::fetch($url, (string)($feed['cookies'] ?? ''), (string)($feed['user_agent'] ?? ''), 25, 12582912);

        [$dom, $xp] = self::dom($response['body']);

        $image = self::metaImage($xp, $dom, $response['final_url']);

        self::removeSelectorNoise($dom, true);
        self::absolutize($dom, $response['final_url']);

        $content = '';
        $selector = trim((string)($feed['content_selector'] ?? ''));
        $chosen = null;

        if ($selector !== '') {
            $chosen = self::bestNodeFromSelector($xp, $dom, $selector);

            if ($chosen) {
                self::pruneInsideNode($xp, $chosen);
                $content = self::innerHtml($chosen);
            }
        }

        if ($content === '' || self::contentIsNoisy($content) || self::contentLooksLikeListing($content)) {
            $node = self::bestContentNode($xp, $dom);

            if ($node) {
                self::pruneInsideNode($xp, $node);
                $content = self::innerHtml($node);
            }
        }

        $content = self::sanitizeHtml($content, (int)($feed['include_images'] ?? 1) === 1, (int)($feed['keep_html'] ?? 1) === 1);

        if ($content === '' || self::contentIsNoisy($content) || self::contentLooksLikeListing($content)) {
            $pure = self::paragraphHtmlFromDom($xp, $dom, (int)($feed['include_images'] ?? 1) === 1);

            if ($pure !== '') {
                $content = $pure;
            }
        }

        if ($image === '') {
            $img = self::firstGlobal($xp, $dom, 'article img, main img, [class*="article"] img, [class*="body"] img, img');

            if ($img instanceof DOMElement) {
                $image = self::imageFromElement($img, $response['final_url']);
            }
        }

        return [
            'content' => $content,
            'image' => $image
        ];
    }

    /**
     * Extrai o texto limpo de um nó DOM.
     */
    public static function text(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /**
     * Obtém o HTML interno (inner HTML) de um nó DOM.
     */
    public static function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return trim($html);
    }

    /**
     * Limpa de forma rígida o conteúdo para saída no formato RSS padrão.
     */
    public static function cleanRssContent(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        [$dom, $xp] = self::dom('<div id="rssmotor-root">' . $html . '</div>');

        self::removeBadImageNodes($xp);
        self::removeCssTextNodes($xp);
        self::removeJsTextNodes($xp);
        self::removeNoisyElements($xp, $xp->query('//*[@id="rssmotor-root"]')->item(0));
        self::removeBadTextNodes($xp, $xp->query('//*[@id="rssmotor-root"]')->item(0));
        self::removeEmptyNodes($xp);

        $root = $xp->query('//*[@id="rssmotor-root"]')->item(0);
        $out = $root ? self::innerHtml($root) : trim($html);
        $out = preg_replace('~<p>\s*(?:<br\s*/?>)?\s*</p>~iu', '', $out) ?? $out;
        $out = preg_replace('~(?:<br\s*/?>\s*){3,}~iu', '<br><br>', $out) ?? $out;

        return trim($out);
    }

    public static function isPublicImageUrl(string $url): bool
    {
        return !self::isBadImageUrl($url) && self::looksLikeImage($url);
    }

    private static function findBlocks(DOMXPath $xp, DOMDocument $dom, array $feed, string $base): array
    {
        $blocks = [];
        $itemSelector = trim((string)($feed['item_selector'] ?? ''));

        if ($itemSelector !== '') {
            $nodes = Selector::query($xp, $dom, $itemSelector);

            if ($nodes && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    if ($node instanceof DOMElement) {
                        self::collectUsefulBlocks($xp, $node, $blocks, $base);
                    }
                }
            }
        }

        if (!$blocks) {
            $seedSelector = trim((string)($feed['title_selector'] ?? '')) ?: trim((string)($feed['link_selector'] ?? ''));

            if ($seedSelector !== '') {
                $nodes = Selector::query($xp, $dom, $seedSelector);

                if ($nodes && $nodes->length > 0) {
                    foreach ($nodes as $node) {
                        $block = self::nearestItemContainer($node);

                        if ($block instanceof DOMElement) {
                            self::collectUsefulBlocks($xp, $block, $blocks, $base);
                        }
                    }
                }
            }
        }

        if (!$blocks) {
            foreach ([
                'main a[href]',
                'section a[href]',
                'article a[href]',
                '[class*="card"] a[href]',
                '[class*="item"] a[href]',
                '[class*="module"] a[href]',
                '[class*="post"] a[href]',
                'a[href]'
            ] as $fallback) {
                $nodes = Selector::query($xp, $dom, $fallback);

                if ($nodes) {
                    foreach ($nodes as $node) {
                        if ($node instanceof DOMElement) {
                            self::collectUsefulBlocks($xp, $node, $blocks, $base);
                        }
                    }
                }

                if (count($blocks) >= 5) {
                    break;
                }
            }
        }

        $scored = [];

        foreach ($blocks as $block) {
            if (!$block instanceof DOMElement) {
                continue;
            }

            if (!self::blockLooksUseful($xp, $block, $base)) {
                continue;
            }

            $url = self::bestUrlFromBlock($xp, $block, $base);

            if ($url === '') {
                continue;
            }

            $key = sha1($url);

            if (isset($scored[$key])) {
                continue;
            }

            $scored[$key] = [
                'node' => $block,
                'score' => self::scoreListBlock($xp, $block, $base)
            ];
        }

        uasort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_map(static fn(array $row): DOMElement => $row['node'], $scored));
    }

    private static function collectUsefulBlocks(DOMXPath $xp, DOMElement $node, array &$blocks, string $base): void
    {
        $tag = strtolower($node->tagName);
        $text = self::text($node);
        $links = $xp->query('.//a[@href]', $node);
        $articles = $xp->query('.//article', $node);

        if ($tag === 'a' && $node->hasAttribute('href')) {
            $url = HttpClient::absoluteUrl($base, trim($node->getAttribute('href')));

            if ($url !== '' && !self::sameCleanUrl($url, $base) && !self::isBadArticleUrl($url)) {
                $blocks[] = $node;
            }

            return;
        }

        if ($articles && $articles->length > 1) {
            foreach ($articles as $article) {
                if ($article instanceof DOMElement) {
                    $parentLink = self::closestOrChildLink($article);

                    if ($parentLink instanceof DOMElement) {
                        $blocks[] = $parentLink;
                    } else {
                        $blocks[] = $article;
                    }
                }
            }

            return;
        }

        if (($links?->length ?? 0) > 4 && mb_strlen($text, 'UTF-8') > 1200) {
            foreach ($links as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $url = HttpClient::absoluteUrl($base, trim($link->getAttribute('href')));
                $label = self::linkLabel($link);

                if ($url !== '' && !self::sameCleanUrl($url, $base) && !self::isBadArticleUrl($url) && mb_strlen($label, 'UTF-8') >= 8) {
                    $blocks[] = $link;
                }
            }

            return;
        }

        $blocks[] = $node;
    }

    private static function blockLooksUseful(DOMXPath $xp, DOMElement $node, string $base): bool
    {
        $text = self::text($node);
        $len = mb_strlen($text, 'UTF-8');

        if ($len < 8) {
            return false;
        }

        if ($len > 2600) {
            return false;
        }

        $url = self::bestUrlFromBlock($xp, $node, $base);

        if ($url === '' || self::sameCleanUrl($url, $base) || self::isBadArticleUrl($url)) {
            return false;
        }

        $title = self::titleFromBlock($xp, $node);

        if (mb_strlen($title, 'UTF-8') < 6) {
            return false;
        }

        if (self::textHasNoise($text)) {
            return false;
        }

        return true;
    }

    private static function scoreListBlock(DOMXPath $xp, DOMElement $node, string $base): int
    {
        $score = 0;
        $tag = strtolower($node->tagName);
        $text = self::text($node);
        $url = self::bestUrlFromBlock($xp, $node, $base);
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

        if ($tag === 'a') {
            $score += 80;
        }

        if ($node->getElementsByTagName('article')->length > 0) {
            $score += 100;
        }

        if ($node->getElementsByTagName('img')->length > 0) {
            $score += 30;
        }

        if ($node->getElementsByTagName('h1')->length || $node->getElementsByTagName('h2')->length || $node->getElementsByTagName('h3')->length) {
            $score += 60;
        }

        if (preg_match('~/(news|features|reviews|how-to|apps|microsoft|copilot|gaming|hardware|software-apps|artificial-intelligence)/[^/]+~i', $path)) {
            $score += 100;
        }

        if (substr_count(trim($path, '/'), '/') >= 2) {
            $score += 40;
        }

        $len = mb_strlen($text, 'UTF-8');

        if ($len >= 40 && $len <= 900) {
            $score += 40;
        }

        if ($len > 1200) {
            $score -= 80;
        }

        return $score;
    }

    private static function nearestItemContainer(DOMNode $node): ?DOMElement
    {
        $current = $node instanceof DOMElement ? $node : $node->parentNode;
        $classParts = ['td_module_', 'td_module_wrap', 'td-block-span', 'post', 'entry', 'item', 'card', 'module', 'news', 'article', 'tease', 'listing'];

        while ($current instanceof DOMElement) {
            $tag = strtolower($current->tagName);
            $class = ' ' . $current->getAttribute('class') . ' ';

            if (in_array($tag, ['article', 'li'], true)) {
                return $current;
            }

            foreach ($classParts as $part) {
                if (stripos($class, $part) !== false) {
                    return $current;
                }
            }

            if (strtolower((string)$current->parentNode?->nodeName) === 'a') {
                return $current->parentNode instanceof DOMElement ? $current->parentNode : $current;
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private static function extractItem(DOMXPath $xp, DOMDocument $dom, DOMNode $block, string $base, array $feed): array
    {
        $linkNode = null;

        if ($block instanceof DOMElement && strtolower($block->tagName) === 'a' && $block->hasAttribute('href')) {
            $linkNode = $block;
        }

        if (!$linkNode) {
            $linkNode = self::nodeBySelectorOrDefault($xp, $dom, $block, (string)($feed['link_selector'] ?? ''), 'a[href]');
        }

        if (!$linkNode) {
            $linkNode = self::closestOrChildLink($block);
        }

        $url = '';

        if ($linkNode instanceof DOMElement) {
            $url = HttpClient::absoluteUrl($base, $linkNode->getAttribute('href'));
        }

        if ($url === '' || self::sameCleanUrl($url, $base) || self::isBadArticleUrl($url)) {
            $betterLink = self::findBetterArticleLink($xp, $block, $base);

            if ($betterLink !== '') {
                $url = $betterLink;
            }
        }

        $title = self::titleFromBlock($xp, $block);

        if ($title === '' && $linkNode instanceof DOMElement) {
            $title = self::linkLabel($linkNode);
        }

        if ($title === '') {
            $title = $url;
        }

        $summaryNode = self::nodeBySelectorOrDefault($xp, $dom, $block, (string)($feed['description_selector'] ?? ''), '.td-excerpt, .entry-summary, .excerpt, .summary, .item-details p, p');
        $dateNode = self::nodeBySelectorOrDefault($xp, $dom, $block, (string)($feed['date_selector'] ?? ''), 'time[datetime], time, .td-post-date, .entry-date, .date, .post-date, [class*="date"]');
        $imageNode = self::nodeBySelectorOrDefault($xp, $dom, $block, (string)($feed['image_selector'] ?? ''), '.td-module-thumb img, picture img, img, [style*="background-image"]');

        $summary = self::summaryFromBlock($xp, $block, $summaryNode, $title);

        $date = self::dateFromNode($dateNode);
        
        // Fallback global de data se não encontrar no bloco
        if (!$date) {
            $date = self::metaDate($xp);
        }

        $image = '';

        if ($imageNode instanceof DOMElement) {
            $image = self::imageFromElement($imageNode, $base);
        }

        return [
            'url' => $url,
            'title' => self::cleanTitle($title),
            'summary' => $summary,
            'content' => $summary,
            'image' => $image,
            'published_at' => $date
        ];
    }

    private static function titleFromBlock(DOMXPath $xp, DOMNode $block): string
    {
        if ($block instanceof DOMElement) {
            foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
                foreach ($block->getElementsByTagName($tag) as $node) {
                    $text = self::cleanTitle(self::text($node));

                    if (mb_strlen($text, 'UTF-8') >= 6 && !self::textHasNoise($text)) {
                        return $text;
                    }
                }
            }

            if (strtolower($block->tagName) === 'a') {
                $label = self::linkLabel($block);

                if ($label !== '') {
                    return self::cleanTitle($label);
                }
            }

            $links = $block->getElementsByTagName('a');

            foreach ($links as $a) {
                if (!$a instanceof DOMElement) {
                    continue;
                }

                $label = self::linkLabel($a);

                if (mb_strlen($label, 'UTF-8') >= 10 && !self::textHasNoise($label)) {
                    return self::cleanTitle($label);
                }
            }

            foreach ($block->getElementsByTagName('p') as $p) {
                $text = self::cleanTitle(self::text($p));

                if (mb_strlen($text, 'UTF-8') >= 12 && mb_strlen($text, 'UTF-8') <= 220 && !self::textHasNoise($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    private static function summaryFromBlock(DOMXPath $xp, DOMNode $block, ?DOMNode $summaryNode, string $title): string
    {
        $summary = self::text($summaryNode);

        if ($summary !== '' && $summary !== $title && mb_strlen($summary, 'UTF-8') <= 450 && !self::textHasNoise($summary)) {
            return $summary;
        }

        if ($block instanceof DOMElement) {
            foreach ($block->getElementsByTagName('p') as $p) {
                $text = self::text($p);

                if ($text !== '' && $text !== $title && mb_strlen($text, 'UTF-8') >= 30 && mb_strlen($text, 'UTF-8') <= 450 && !self::textHasNoise($text)) {
                    return $text;
                }
            }
        }

        return '';
    }

    private static function linkLabel(DOMElement $link): string
    {
        foreach (['aria-label', 'title'] as $attr) {
            $value = trim($link->getAttribute($attr));

            if ($value !== '' && !self::textHasNoise($value)) {
                return $value;
            }
        }

        $text = self::text($link);

        if ($text !== '' && !self::textHasNoise($text)) {
            return $text;
        }

        foreach ($link->getElementsByTagName('img') as $img) {
            if ($img instanceof DOMElement) {
                $alt = trim($img->getAttribute('alt'));

                if ($alt !== '' && !self::textHasNoise($alt)) {
                    return $alt;
                }
            }
        }

        return '';
    }

    private static function bestUrlFromBlock(DOMXPath $xp, DOMNode $block, string $base): string
    {
        if ($block instanceof DOMElement && strtolower($block->tagName) === 'a' && $block->hasAttribute('href')) {
            $url = HttpClient::absoluteUrl($base, $block->getAttribute('href'));

            if ($url !== '' && !self::sameCleanUrl($url, $base) && !self::isBadArticleUrl($url)) {
                return $url;
            }
        }

        return self::findBetterArticleLink($xp, $block, $base);
    }

    private static function extractFromGlobalLinks(DOMXPath $xp, DOMDocument $dom, string $base, array $feed, int $max, bool $preview): array
    {
        $selector = trim((string)($feed['title_selector'] ?? '')) ?: trim((string)($feed['link_selector'] ?? '')) ?: 'main a[href], section a[href], article a[href], a[href]';
        $nodes = Selector::query($xp, $dom, $selector);
        $items = [];
        $seen = [];

        if (!$nodes) {
            return [];
        }

        foreach ($nodes as $node) {
            if (count($items) >= $max) {
                break;
            }

            $link = self::closestOrChildLink($node);

            if (!$link instanceof DOMElement) {
                continue;
            }

            $url = HttpClient::absoluteUrl($base, $link->getAttribute('href'));

            if ($url === '' || self::sameCleanUrl($url, $base) || self::isBadArticleUrl($url) || isset($seen[sha1($url)])) {
                continue;
            }

            $block = self::nearestItemContainer($node) ?: $node;
            $title = self::titleFromBlock($xp, $block) ?: self::linkLabel($link);

            if ($title === '' || mb_strlen($title, 'UTF-8') < 4) {
                continue;
            }

            $seen[sha1($url)] = true;

            $item = self::extractItem($xp, $dom, $block, $base, $feed);
            $item['url'] = $url;
            $item['title'] = self::cleanTitle($title);

            if ((int)($feed['full_text'] ?? 1) === 1) {
                try {
                    $full = self::fullArticle($url, $feed);

                    if ($full['content'] !== '') {
                        $item['content'] = $preview ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($full['content'])) ?? ''), 0, 900, 'UTF-8') : $full['content'];
                    }

                    if ($full['image'] !== '' && $item['image'] === '') {
                        $item['image'] = $full['image'];
                    }
                } catch (Throwable $e) {
                }
            }

            if (self::passesFilters($item, $feed)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function nodeBySelectorOrDefault(DOMXPath $xp, DOMDocument $dom, DOMNode $block, string $selector, string $fallback): ?DOMNode
    {
        if (trim($selector) !== '') {
            $found = self::firstInsideBlock($xp, $dom, $block, $selector);

            if ($found) {
                return $found;
            }
        }

        return self::firstInsideBlock($xp, $dom, $block, $fallback);
    }

    private static function firstInsideBlock(DOMXPath $xp, DOMDocument $dom, DOMNode $block, string $selector): ?DOMNode
    {
        try {
            $local = Selector::first($xp, $block, $selector);

            if ($local) {
                return $local;
            }

            $global = Selector::query($xp, $dom, $selector);

            if (!$global) {
                return null;
            }

            foreach ($global as $node) {
                if ($node && self::containsNode($block, $node)) {
                    return $node;
                }
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private static function firstGlobal(DOMXPath $xp, DOMDocument $dom, string $selector): ?DOMNode
    {
        try {
            return Selector::first($xp, $dom, $selector);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function containsNode(DOMNode $parent, DOMNode $child): bool
    {
        $node = $child;

        while ($node) {
            if ($node === $parent) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    private static function closestOrChildLink(?DOMNode $node): ?DOMElement
    {
        if (!$node) {
            return null;
        }

        $current = $node instanceof DOMElement ? $node : $node->parentNode;

        while ($current instanceof DOMElement) {
            if (strtolower($current->tagName) === 'a' && $current->hasAttribute('href')) {
                return $current;
            }

            $current = $current->parentNode;
        }

        if ($node instanceof DOMElement) {
            $links = $node->getElementsByTagName('a');

            foreach ($links as $a) {
                if ($a->hasAttribute('href')) {
                    return $a;
                }
            }
        }

        return null;
    }

    private static function imageFromElement(DOMElement $el, string $base): string
    {
        foreach (['data-full-url', 'data-orig-file', 'data-large-file', 'data-lazy-src', 'data-src', 'data-original', 'data-img-url', 'content', 'src'] as $attr) {
            $value = self::cleanImageCandidate($el->getAttribute($attr));

            if ($value !== '') {
                $abs = HttpClient::absoluteUrl($base, $value);

                if ($abs === '' && preg_match('~^https?://~i', $value)) {
                    $abs = $value;
                }

                if (!self::isBadImageUrl($abs)) {
                    return $abs;
                }
            }
        }

        foreach (['data-srcset', 'srcset'] as $attr) {
            $candidate = self::bestSrcsetCandidate($el->getAttribute($attr));

            if ($candidate !== '') {
                $abs = HttpClient::absoluteUrl($base, $candidate);

                if ($abs === '' && preg_match('~^https?://~i', $candidate)) {
                    $abs = $candidate;
                }

                if (!self::isBadImageUrl($abs)) {
                    return $abs;
                }
            }
        }

        $style = $el->getAttribute('style');

        if ($style && preg_match('~url\(["\']?([^"\')]+)~i', $style, $m)) {
            $candidate = self::cleanImageCandidate($m[1]);
            $abs = HttpClient::absoluteUrl($base, $candidate);

            if ($abs === '' && preg_match('~^https?://~i', $candidate)) {
                $abs = $candidate;
            }

            if (!self::isBadImageUrl($abs)) {
                return $abs;
            }
        }

        $parent = $el->parentNode;

        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'a') {
            $href = self::cleanImageCandidate($parent->getAttribute('href'));

            if (self::looksLikeImage($href)) {
                $abs = HttpClient::absoluteUrl($base, $href);

                if ($abs === '' && preg_match('~^https?://~i', $href)) {
                    $abs = $href;
                }

                if (!self::isBadImageUrl($abs)) {
                    return $abs;
                }
            }
        }

        return '';
    }

    private static function metaImage(DOMXPath $xp, DOMDocument $dom, string $base): string
    {
        $nodes = Selector::query($xp, $dom, 'meta[property="og:image"], meta[name="twitter:image"], meta[property="og:image:secure_url"]');

        if ($nodes) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $candidate = self::cleanImageCandidate($node->getAttribute('content'));
                    $img = HttpClient::absoluteUrl($base, $candidate);

                    if ($img !== '' && !self::isBadImageUrl($img)) {
                        return $img;
                    }
                }
            }
        }

        return '';
    }

    private static function metaDate(DOMXPath $xp): ?string
    {
        $queries = [
            '//meta[@property="article:published_time"]/@content',
            '//meta[@name="pubdate"]/@content',
            '//meta[@name="publishdate"]/@content',
            '//meta[@name="timestamp"]/@content',
            '//meta[@name="dc.date.issued"]/@content'
        ];

        foreach ($queries as $query) {
            $nodes = $xp->query($query);
            if ($nodes && $nodes->length > 0) {
                $val = $nodes->item(0)->nodeValue;
                $parsed = self::parseDate($val);
                if ($parsed) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    private static function findBetterArticleLink(DOMXPath $xp, DOMNode $block, string $base): string
    {
        $links = $xp->query('.//a[@href]', $block);

        if (!$links && $block instanceof DOMElement && strtolower($block->tagName) === 'a' && $block->hasAttribute('href')) {
            $url = HttpClient::absoluteUrl($base, $block->getAttribute('href'));

            return self::isBadArticleUrl($url) || self::sameCleanUrl($url, $base) ? '' : $url;
        }

        if (!$links) {
            return '';
        }

        $best = '';
        $bestScore = -9999;

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $url = HttpClient::absoluteUrl($base, $href);

            if ($url === '' || !preg_match('~^https?://~i', $url)) {
                continue;
            }

            if (self::sameCleanUrl($url, $base) || self::isBadArticleUrl($url)) {
                continue;
            }

            $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
            $text = self::linkLabel($link);
            $score = 0;

            if (substr_count(trim($path, '/'), '/') >= 2) {
                $score += 60;
            }

            if (mb_strlen($text, 'UTF-8') >= 20) {
                $score += 40;
            }

            if ($link->getElementsByTagName('article')->length > 0) {
                $score += 120;
            }

            if (preg_match('~/(features|news|apps|microsoft|copilot|gaming|hardware|software-apps|artificial-intelligence)/[^/]+~i', $path)) {
                $score += 100;
            }

            if (preg_match('~-\w+~', basename($path))) {
                $score += 30;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $url;
            }
        }

        return $best;
    }

    private static function sameCleanUrl(string $a, string $b): bool
    {
        $a = rtrim(strtolower((string)preg_replace('~[#?].*$~', '', $a)), '/');
        $b = rtrim(strtolower((string)preg_replace('~[#?].*$~', '', $b)), '/');

        return $a !== '' && $a === $b;
    }

    private static function isBadArticleUrl(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: $url));
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return true;
        }

        if (preg_match('~/(page/\d+|archive|category|tag|author|membership|newsletter|about|contact|privacy|terms|search|login|signup|signin|account)$~i', $path)) {
            return true;
        }

        if (preg_match('~/(features|news|reviews|deals|best|how-to|gaming|hardware|software-apps|microsoft|artificial-intelligence|apps|pc-gaming|xbox|windows)$~i', $path)) {
            return true;
        }

        if (preg_match('~\.(jpg|jpeg|png|gif|webp|avif|svg|css|js|ico|pdf|zip|mp4|mov|xml)$~i', $path)) {
            return true;
        }

        return false;
    }

    private static function dateFromNode(?DOMNode $node): ?string
    {
        if (!$node) {
            return null;
        }

        if ($node instanceof DOMElement) {
            foreach (['datetime', 'content', 'data-time', 'title'] as $attr) {
                $value = $node->getAttribute($attr);

                if ($value !== '') {
                    $date = self::parseDate($value);

                    if ($date) {
                        return $date;
                    }
                }
            }
        }

        return self::parseDate(self::text($node));
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Suporte para datas ISO 8601 diretas
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/i', $value)) {
            $ts = strtotime($value);
            return $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        // Formato BR clássico: DD/MM/YYYY às HH:MM
        if (preg_match('~(\d{1,2})/(\d{1,2})/(\d{4})(?:\s*(?:às|as|-)?\s*(\d{1,2}:\d{2}(?::\d{2})?))?~iu', $value, $m)) {
            $time = $m[4] ?? '00:00:00';

            if (substr_count($time, ':') === 1) {
                $time .= ':00';
            }

            return sprintf('%04d-%02d-%02d %s', (int)$m[3], (int)$m[2], (int)$m[1], $time);
        }

        $normalized = str_replace([' às ', ' as ', ' de ', ',', ' às '], [' ', ' ', ' ', '', ' '], mb_strtolower($value, 'UTF-8'));
        $months = [
            'janeiro' => 'january', 'fevereiro' => 'february', 'março' => 'march', 'marco' => 'march',
            'abril' => 'april', 'maio' => 'may', 'junho' => 'june', 'julho' => 'july',
            'agosto' => 'august', 'setembro' => 'september', 'outubro' => 'october',
            'novembro' => 'november', 'dezembro' => 'december'
        ];

        $normalized = strtr($normalized, $months);
        
        // Tenta interpretar a data em inglês após substituição dos meses em pt-br
        $ts = strtotime($normalized);

        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function cleanTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        $title = preg_replace('/\bBy\s+[A-Z][\p{L}\s\.\'-]+published\b.*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s+[-|–]\s+.*$/u', '', $title) ?? $title;

        return trim($title);
    }

    private static function passesFilters(array $item, array $feed): bool
    {
        $haystack = mb_strtolower(($item['title'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . ($item['content'] ?? ''), 'UTF-8');
        $include = trim((string)($feed['include_filter'] ?? ''));
        $exclude = trim((string)($feed['exclude_filter'] ?? ''));

        if ($include !== '') {
            $ok = false;

            foreach (preg_split('/\r\n|\r|\n|,/', $include) as $term) {
                $term = trim(mb_strtolower($term, 'UTF-8'));

                if ($term !== '' && str_contains($haystack, $term)) {
                    $ok = true;
                    break;
                }
            }

            if (!$ok) {
                return false;
            }
        }

        if ($exclude !== '') {
            foreach (preg_split('/\r\n|\r|\n|,/', $exclude) as $term) {
                $term = trim(mb_strtolower($term, 'UTF-8'));

                if ($term !== '' && str_contains($haystack, $term)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function bestNodeFromSelector(DOMXPath $xp, DOMDocument $dom, string $selector): ?DOMNode
    {
        try {
            $nodes = Selector::query($xp, $dom, $selector);
        } catch (Throwable $e) {
            return null;
        }

        if (!$nodes || $nodes->length === 0) {
            return null;
        }

        return self::bestContentFromNodes($xp, $nodes);
    }

    private static function bestContentNode(DOMXPath $xp, DOMDocument $dom): ?DOMNode
    {
        $selectors = [
            '[class*="article-body"]', '[class*="ArticleBody"]', '[class*="articleBody"]',
            '[class*="article-content"]', '[class*="ArticleContent"]', '[class*="post-content"]',
            '[class*="entry-content"]', '[class*="story-body"]', '[class*="StoryBody"]',
            '[class*="body-content"]', '[data-component*="ArticleBody"]', '[data-component*="article-body"]',
            '[data-testid*="article-body"]', 'main article', 'article', 'main', '[role="main"]'
        ];

        $pool = [];

        foreach ($selectors as $selector) {
            try {
                $nodes = Selector::query($xp, $dom, $selector);

                if (!$nodes) {
                    continue;
                }

                foreach ($nodes as $node) {
                    if ($node instanceof DOMNode) {
                        $pool[] = $node;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (!$pool) {
            return null;
        }

        return self::bestContentFromArray($xp, $pool);
    }

    private static function bestContentFromNodes(DOMXPath $xp, DOMNodeList $nodes): ?DOMNode
    {
        $pool = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMNode) {
                $pool[] = $node;
            }
        }

        return self::bestContentFromArray($xp, $pool);
    }

    private static function bestContentFromArray(DOMXPath $xp, array $nodes): ?DOMNode
    {
        $best = null;
        $bestScore = PHP_INT_MIN;

        foreach ($nodes as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }

            $score = self::scoreContentNode($xp, $node);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }

        return $bestScore > 80 ? $best : null;
    }

    private static function scoreContentNode(DOMXPath $xp, DOMNode $node): int
    {
        $text = self::text($node);
        $len = mb_strlen($text, 'UTF-8');

        if ($len < 100) {
            return -9999;
        }

        $p = $xp->query('.//p', $node);
        $links = $xp->query('.//a', $node);
        $imgs = $xp->query('.//img', $node);
        $headers = $xp->query('.//h1|.//h2|.//h3', $node);

        $paragraphs = $p?->length ?? 0;
        $linkCount = $links?->length ?? 0;
        $imgCount = $imgs?->length ?? 0;
        $headerCount = $headers?->length ?? 0;

        $score = 0;
        $score += min($len, 12000);
        $score += $paragraphs * 220;
        $score += $headerCount * 90;
        $score += min($imgCount, 6) * 35;
        $score -= $linkCount * 45;

        $linkDensity = $len > 0 ? ($linkCount / max(1, $paragraphs + 1)) : 999;

        if ($linkDensity > 5) {
            $score -= 1600;
        }

        if (self::textHasNoise($text)) {
            $score -= 5000;
        }

        if (self::textHasJs($text)) {
            $score -= 8000;
        }

        if (self::contentLooksLikeListingText($text)) {
            $score -= 3500;
        }

        if ($node instanceof DOMElement) {
            $class = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id') . ' ' . $node->getAttribute('data-component') . ' ' . $node->getAttribute('data-testid'));

            foreach (['article-body', 'articlebody', 'article-content', 'entry-content', 'post-content', 'story-body', 'body-content', 'text-copy', 'article__body'] as $good) {
                if (str_contains($class, $good)) {
                    $score += 3500;
                }
            }

            foreach (['newsletter', 'membership', 'insider', 'banner', 'comment', 'related', 'sidebar', 'ad-', 'advert', 'promo', 'footer', 'header', 'nav'] as $bad) {
                if (str_contains($class, $bad)) {
                    $score -= 3000;
                }
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'article') {
                $score += 600;
            }

            if ($tag === 'body') {
                $score -= 5000;
            }

            if ($tag === 'main') {
                $score += 300;
            }
        }

        return $score;
    }

    private static function paragraphHtmlFromDom(DOMXPath $xp, DOMDocument $dom, bool $images): string
    {
        $node = self::bestContentNode($xp, $dom);

        if (!$node) {
            return '';
        }

        self::pruneInsideNode($xp, $node);

        $allowedQuery = './/h1|.//h2|.//h3|.//h4|.//p|.//ul|.//ol|.//blockquote|.//figure';

        if ($images) {
            $allowedQuery .= '|.//img';
        }

        $nodes = $xp->query($allowedQuery, $node);

        if (!$nodes) {
            return '';
        }

        $html = '';
        $seen = [];

        foreach ($nodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            $text = self::text($child);

            if ($tag === 'img') {
                $src = self::imageFromElement($child, '');

                if ($src === '' || isset($seen['img:' . $src])) {
                    continue;
                }

                // h() é presumido existir no seu ecossistema global
                $alt = function_exists('h') ? h($child->getAttribute('alt')) : htmlspecialchars($child->getAttribute('alt'), ENT_QUOTES, 'UTF-8');
                $srcSafe = function_exists('h') ? h($src) : htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                $html .= '<p><img src="' . $srcSafe . '" alt="' . $alt . '"></p>';
                $seen['img:' . $src] = true;
                continue;
            }

            if (!self::isGoodContentText($text, $tag)) {
                continue;
            }

            $key = sha1($tag . '|' . $text);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $html .= $child->ownerDocument?->saveHTML($child) ?: '';
        }

        $html = self::sanitizeHtml($html, $images, true);

        return trim($html);
    }

    private static function isGoodContentText(string $text, string $tag): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if (self::textHasNoise($text) || self::textHasJs($text)) {
            return false;
        }

        $len = mb_strlen($text, 'UTF-8');

        if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) {
            return $len >= 8 && $len <= 220;
        }

        if (in_array($tag, ['ul', 'ol'], true)) {
            return $len >= 20 && $len <= 1600;
        }

        if ($tag === 'blockquote') {
            return $len >= 20 && $len <= 1800;
        }

        return $len >= 35 && $len <= 2500;
    }

    private static function removeSelectorNoise(DOMDocument $dom, bool $articleMode): void
    {
        $xp = new DOMXPath($dom);
        $q = '//script|//style|//noscript|//iframe|//svg|//canvas|//form|//input|//button|//template';

        if ($articleMode) {
            $q .= '|//nav|//header|//footer|//aside';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "newsletter")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "membership")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "insider")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "banner")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comment")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "related")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "share")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "advert")]';
            $q .= '|//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "promo")]';
            $q .= '|//*[contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "newsletter")]';
            $q .= '|//*[contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "membership")]';
            $q .= '|//*[contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "banner")]';
            $q .= '|//*[contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "comment")]';
            $q .= '|//*[contains(translate(@data-component-name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "newsletter")]';
            $q .= '|//*[contains(translate(@data-component-name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "membership")]';
            $q .= '|//*[contains(translate(@data-component-name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "banner")]';
        }

        $remove = $xp->query($q);

        if ($remove) {
            for ($i = $remove->length - 1; $i >= 0; $i--) {
                $n = $remove->item($i);

                if ($n && $n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }

        if ($articleMode) {
            self::removeNoisyElements($xp, null);
            self::removeBadTextNodes($xp, null);
        }
    }

    private static function pruneInsideNode(DOMXPath $xp, DOMNode $node): void
    {
        self::removeNoisyElements($xp, $node);
        self::removeBadTextNodes($xp, $node);

        $remove = $xp->query('.//script|.//style|.//noscript|.//iframe|.//form|.//input|.//button|.//template|.//nav|.//header|.//footer|.//aside', $node);

        if ($remove) {
            for ($i = $remove->length - 1; $i >= 0; $i--) {
                $n = $remove->item($i);

                if ($n && $n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }
    }

    private static function removeNoisyElements(DOMXPath $xp, ?DOMNode $root): void
    {
        $query = $root ? './/*' : '//*';
        $nodes = $root ? $xp->query($query, $root) : $xp->query($query);

        if (!$nodes) {
            return;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            if (!$node instanceof DOMElement || !$node->parentNode) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, ['html', 'body', 'main', 'article'], true)) {
                continue;
            }

            $text = self::text($node);
            $len = mb_strlen($text, 'UTF-8');
            $attrs = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id') . ' ' . $node->getAttribute('data-component') . ' ' . $node->getAttribute('data-component-name') . ' ' . $node->getAttribute('data-testid'));

            $attrNoise = false;

            foreach (['newsletter', 'membership', 'insider', 'skinnybanner', 'banner', 'comment', 'related', 'share', 'advert', 'promo', 'sidebar', 'social', 'cookie', 'consent', 'sign-in', 'signin', 'signup', 'login'] as $needle) {
                if (str_contains($attrs, $needle)) {
                    $attrNoise = true;
                    break;
                }
            }

            $textNoise = self::textHasNoise($text) || self::textHasJs($text);

            if (($attrNoise && $len < 7000) || ($textNoise && $len < 9000)) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private static function removeBadTextNodes(DOMXPath $xp, ?DOMNode $root): void
    {
        $nodes = $root ? $xp->query('.//text()', $root) : $xp->query('//text()');

        if (!$nodes) {
            return;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            if (!$node || !$node->parentNode) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '');

            if ($text === '') {
                continue;
            }

            if (self::textHasJs($text) || (self::textHasNoise($text) && mb_strlen($text, 'UTF-8') < 1000)) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private static function absolutize(DOMDocument $dom, string $base): void
    {
        $xp = new DOMXPath($dom);

        foreach (['href', 'src', 'data-src', 'data-lazy-src', 'data-original', 'srcset', 'data-srcset'] as $attr) {
            $nodes = $xp->query('//*[@' . $attr . ']');

            if (!$nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $value = trim($node->getAttribute($attr));

                if ($value === '' || self::shouldSkipAbsolute($value)) {
                    continue;
                }

                if (str_contains($attr, 'srcset')) {
                    $node->setAttribute($attr, self::absoluteSrcset($base, $value));
                } else {
                    $abs = HttpClient::absoluteUrl($base, $value);

                    if ($abs !== '') {
                        $node->setAttribute($attr, $abs);
                    }
                }
            }
        }
    }

    private static function absoluteSrcset(string $base, string $srcset): string
    {
        $parts = [];

        foreach (explode(',', $srcset) as $piece) {
            $bits = preg_split('/\s+/', trim($piece));

            if (!$bits || ($bits[0] ?? '') === '') {
                continue;
            }

            if (self::shouldSkipAbsolute($bits[0])) {
                continue;
            }

            $bits[0] = HttpClient::absoluteUrl($base, $bits[0]);
            $parts[] = implode(' ', array_filter($bits));
        }

        return implode(', ', $parts);
    }

    private static function shouldSkipAbsolute(string $value): bool
    {
        $lower = strtolower(trim($value));

        return $lower === '' ||
            str_starts_with($lower, 'data:') ||
            str_starts_with($lower, 'javascript:') ||
            str_starts_with($lower, 'mailto:') ||
            str_starts_with($lower, 'tel:') ||
            str_starts_with($lower, '#');
    }

    private static function sanitizeHtml(string $html, bool $images, bool $keepHtml): string
    {
        if (!$keepHtml) {
            return trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        }

        [$dom, $xp] = self::dom('<div id="rssmotor-root">' . $html . '</div>');
        $root = $xp->query('//*[@id="rssmotor-root"]')->item(0);

        if ($images) {
            self::fixImagesInsideHtml($xp);
        }

        $remove = $xp->query('//script|//style|//noscript|//iframe|//form|//input|//button|//link|//meta|//object|//embed|//svg|//canvas|//template' . ($images ? '' : '|//img|//picture|//source'));

        if ($remove) {
            for ($i = $remove->length - 1; $i >= 0; $i--) {
                $n = $remove->item($i);

                if ($n && $n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }

        self::removeBadImageNodes($xp);
        self::removeCssTextNodes($xp);
        self::removeJsTextNodes($xp);
        self::removeNoisyElements($xp, $root);
        self::removeBadTextNodes($xp, $root);
        self::removeEmptyNodes($xp);

        $all = $xp->query('//*[@id="rssmotor-root"]//*');

        if ($all) {
            foreach ($all as $el) {
                if (!$el instanceof DOMElement) {
                    continue;
                }

                $allowed = ['href', 'src', 'alt', 'title', 'width', 'height'];

                for ($i = $el->attributes->length - 1; $i >= 0; $i--) {
                    $attr = $el->attributes->item($i);

                    if ($attr) {
                        $attrName = strtolower($attr->name);
                        
                        // Remoção de eventos (XSS Protection) e atributos não permitidos
                        if (!in_array($attrName, $allowed, true) || str_starts_with($attrName, 'on')) {
                            $el->removeAttribute($attr->name);
                        }
                    }
                }

                // Remove links com esquemas javascript:
                if ($el->hasAttribute('href') && str_starts_with(strtolower($el->getAttribute('href')), 'javascript:')) {
                    $el->removeAttribute('href');
                }

                $tag = strtolower($el->tagName);

                if (in_array($tag, ['div', 'span', 'section', 'main', 'article'], true) && !$el->hasChildNodes()) {
                    $el->parentNode?->removeChild($el);
                }
            }
        }

        $out = $root ? self::innerHtml($root) : trim($html);
        $out = preg_replace('~<p>\s*(?:<br\s*/?>)?\s*</p>~iu', '', $out) ?? $out;
        $out = preg_replace('~(?:<br\s*/?>\s*){3,}~iu', '<br><br>', $out) ?? $out;

        return trim($out);
    }

    private static function fixImagesInsideHtml(DOMXPath $xp): void
    {
        $imgs = $xp->query('//*[@id="rssmotor-root"]//img');

        if (!$imgs) {
            return;
        }

        foreach ($imgs as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            $best = self::imageFromElement($img, '');

            if ($best !== '') {
                $img->setAttribute('src', $best);
            }
        }
    }

    private static function removeBadImageNodes(DOMXPath $xp): void
    {
        $imgs = $xp->query('//*[@id="rssmotor-root"]//img');

        if (!$imgs) {
            return;
        }

        for ($i = $imgs->length - 1; $i >= 0; $i--) {
            $img = $imgs->item($i);

            if (!$img instanceof DOMElement) {
                continue;
            }

            $src = $img->getAttribute('src');

            if (self::isBadImageUrl($src)) {
                $parent = $img->parentNode;

                if ($parent && strtolower($parent->nodeName) === 'p' && trim($parent->textContent ?? '') === '') {
                    $parent->parentNode?->removeChild($parent);
                } elseif ($parent) {
                    $parent->removeChild($img);
                }
            }
        }
    }

    private static function removeCssTextNodes(DOMXPath $xp): void
    {
        $nodes = $xp->query('//*[@id="rssmotor-root"]//p|//*[@id="rssmotor-root"]//div|//*[@id="rssmotor-root"]//span');

        if (!$nodes) {
            return;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            if (!$node || !$node->parentNode) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');

            if ($text === '') {
                continue;
            }

            $cssSignals = 0;

            if (preg_match('/#tdi_\d+/i', $text)) {
                $cssSignals++;
            }

            if (preg_match('/\.td-[a-z0-9_-]+/i', $text)) {
                $cssSignals++;
            }

            if (preg_match('/background\s*:\s*url\(/i', $text)) {
                $cssSignals++;
            }

            if (preg_match('/\{\s*[^}]+\}/', $text)) {
                $cssSignals++;
            }

            if ($cssSignals >= 2) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private static function removeJsTextNodes(DOMXPath $xp): void
    {
        $nodes = $xp->query('//*[@id="rssmotor-root"]//p|//*[@id="rssmotor-root"]//div|//*[@id="rssmotor-root"]//span');

        if (!$nodes) {
            return;
        }

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            if (!$node || !$node->parentNode) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');

            if ($text === '') {
                continue;
            }

            if (self::textHasJs($text) || (self::textHasNoise($text) && mb_strlen($text, 'UTF-8') < 4000)) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private static function removeEmptyNodes(DOMXPath $xp): void
    {
        for ($round = 0; $round < 5; $round++) {
            $nodes = $xp->query('//*[@id="rssmotor-root"]//p|//*[@id="rssmotor-root"]//div|//*[@id="rssmotor-root"]//figure|//*[@id="rssmotor-root"]//section|//*[@id="rssmotor-root"]//span');

            if (!$nodes) {
                return;
            }

            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);

                if (!$node || !$node->parentNode) {
                    continue;
                }

                $html = trim($node->ownerDocument?->saveHTML($node) ?: '');
                $text = trim($node->textContent ?? '');
                $hasMedia = $node instanceof DOMElement && ($node->getElementsByTagName('img')->length > 0 || $node->getElementsByTagName('iframe')->length > 0);

                if (!$hasMedia && ($text === '' || preg_match('~^<[^>]+>\s*(?:&nbsp;|\s|<br\s*/?>)*\s*</[^>]+>$~iu', $html))) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private static function cleanImageCandidate(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($value === '') {
            return '';
        }

        if (str_contains(strtolower($value), 'data:image/')) {
            return '';
        }

        return $value;
    }

    private static function bestSrcsetCandidate(string $srcset): string
    {
        $best = '';
        $bestScore = -1;

        foreach (explode(',', $srcset) as $piece) {
            $piece = trim($piece);

            if ($piece === '') {
                continue;
            }

            $bits = preg_split('/\s+/', $piece);
            $url = self::cleanImageCandidate((string)($bits[0] ?? ''));

            if ($url === '') {
                continue;
            }

            $score = 0;

            foreach ($bits as $bit) {
                if (preg_match('/^(\d+)w$/', $bit, $m)) {
                    $score = (int)$m[1];
                } elseif (preg_match('/^(\d+(?:\.\d+)?)x$/', $bit, $m)) {
                    $score = (int)((float)$m[1] * 1000);
                }
            }

            if ($score >= $bestScore) {
                $bestScore = $score;
                $best = $url;
            }
        }

        return $best;
    }

    private static function isBadImageUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '') {
            return true;
        }

        $lower = strtolower($url);

        if (str_contains($lower, 'data:image/')) {
            return true;
        }

        if (str_contains($lower, 'data:image/svg')) {
            return true;
        }

        if (str_contains($lower, '<svg')) {
            return true;
        }

        if (preg_match('~/(?:1x1|blank|spacer|transparent|placeholder)\.(?:gif|png|jpg|jpeg|webp)(?:\?|$)~i', $lower)) {
            return true;
        }

        if (preg_match('~viewbox(?:=|%3d)[^\s]*0(?:%20|\+|\s)0(?:%20|\+|\s)0(?:%20|\+|\s)0~i', $lower)) {
            return true;
        }

        return false;
    }

    private static function looksLikeImage(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: $url));

        return (bool)preg_match('~\.(?:jpe?g|png|gif|webp|avif)(?:$|\?)~i', $path);
    }

    private static function contentIsNoisy(string $html): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        if ($text === '') {
            return true;
        }

        if (self::textHasJs($text)) {
            return true;
        }

        $hits = 0;

        foreach ([
            'Windows Central Insider',
            'TORNE-SE UM INSIDER',
            'Became an Insider',
            'Become an Insider',
            'Premium Benefits',
            'Benefícios Premium',
            'Join our community',
            'Junte-se à nossa comunidade',
            'newsletter',
            'skinnyBanner',
            'membershipEvent',
            'Please login or signup to comment',
            'Submission failed',
            'MutationObserver'
        ] as $needle) {
            if (stripos($text, $needle) !== false) {
                $hits++;
            }
        }

        return $hits >= 1;
    }

    private static function contentLooksLikeListing(string $html): bool
    {
        return self::contentLooksLikeListingText(trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''));
    }

    private static function contentLooksLikeListingText(string $text): bool
    {
        $text = mb_strtolower($text, 'UTF-8');
        $hits = 0;

        foreach (['latest articles', 'últimos artigos', 'read now', 'ler agora', 'archives', 'arquivo', 'sponsored', 'patrocinado', 'newsletter', 'related', 'relacionado'] as $needle) {
            if (str_contains($text, $needle)) {
                $hits++;
            }
        }

        return $hits >= 3;
    }

    private static function textHasNoise(string $text): bool
    {
        $low = mb_strtolower($text, 'UTF-8');

        foreach ([
            'windows central insider',
            'torne-se um insider',
            'become an insider',
            'premium benefits',
            'benefícios premium',
            'join our community',
            'junte-se à nossa comunidade',
            'find out about our magazine',
            'saiba mais sobre nossa revista',
            'please login or signup to comment',
            'por favor, faça login',
            'newsletter',
            'terms & conditions',
            'termos e condições',
            'privacy policy',
            'política de privacidade',
            'active members',
            'membros ativos',
            'exclusive articles',
            'artigos exclusivos',
            'member-only',
            'apenas para membros',
            'curated deals',
            'ofertas selecionadas',
            'skinnybanner',
            'membershipevent',
            'submission failed'
        ] as $needle) {
            if (str_contains($low, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function textHasJs(string $text): bool
    {
        $signals = 0;

        foreach ([
            'window.addEventListener',
            'MutationObserver',
            'document.querySelector',
            'localStorage.',
            'function ',
            'const ',
            'let ',
            'bannerVisible',
            'scrollTriggered',
            'dispatchOrQueueAction',
            'console.error',
            'catch(error',
            '=>',
            'return response.json',
            'addEventListener(',
            'getElementById('
        ] as $needle) {
            if (str_contains($text, $needle)) {
                $signals++;
            }
        }

        return $signals >= 2 || ($signals >= 1 && mb_strlen($text, 'UTF-8') > 250);
    }

    private static function debugCounts(DOMXPath $xp, DOMDocument $dom, array $feed): string
    {
        $map = [
            'bloco' => (string)($feed['item_selector'] ?? ''),
            'titulo' => (string)($feed['title_selector'] ?? ''),
            'link' => (string)($feed['link_selector'] ?? ''),
            'descricao' => (string)($feed['description_selector'] ?? ''),
            'data' => (string)($feed['date_selector'] ?? ''),
            'imagem' => (string)($feed['image_selector'] ?? ''),
            'conteudo' => (string)($feed['content_selector'] ?? '')
        ];

        $out = [];

        foreach ($map as $name => $selector) {
            $selector = trim($selector);

            if ($selector === '') {
                $out[] = $name . '=vazio';
                continue;
            }

            try {
                $nodes = Selector::query($xp, $dom, $selector);
                $out[] = $name . '=' . ($nodes?->length ?? 0);
            } catch (Throwable $e) {
                $out[] = $name . '=erro';
            }
        }

        return implode(', ', $out);
    }
}