<?php
declare(strict_types=1);

final class AiTranslator
{
    private const DEFAULT_GEMINI_MODEL = 'gemini-2.5-flash-lite';
    private const DEFAULT_OLLAMA_MODEL = 'gemma3:4b';

    public static function defaultGeminiModel(): string
    {
        return self::DEFAULT_GEMINI_MODEL;
    }

    public static function defaultRewritePrompt(): string
    {
        return 'Reescreva essa matéria jornalística de forma totalmente original, preservando a linha de raciocínio e a essência da informação, mas com um estilo diferente, como se tivesse sido escrita por outro jornalista. Não copie frases longas nem a mesma estrutura do texto original. Preserve nomes próprios, cargos, locais, datas, números, valores e fatos verificáveis. Não invente informações, não acrescente dados que não estejam no texto e não altere o sentido da notícia. Não mencione veículo de comunicação, autor original ou fonte original. Não coloque travessão. Otimize o título e o conteúdo com técnicas avançadas de SEO, mantendo linguagem jornalística natural, forte e de fácil leitura.';
    }

    public static function translateItem(array $item, array $feed): array
    {
        if ((int)($feed['translate_enabled'] ?? 0) !== 1) {
            return $item;
        }

        $provider = self::provider($feed, 'translate');

        if ($provider === 'ollama') {
            return self::translateWithOllama($item, $feed);
        }

        return self::translateWithGemini($item, $feed);
    }

    public static function rewriteItem(array $item, array $feed): array
    {
        if ((int)($feed['rewrite_enabled'] ?? 0) !== 1) {
            return $item;
        }

        $provider = self::provider($feed, 'rewrite');

        if ($provider === 'ollama') {
            return self::rewriteWithOllama($item, $feed);
        }

        return self::rewriteWithGemini($item, $feed);
    }

    private static function translateWithGemini(array $item, array $feed): array
    {
        $apiKey = self::geminiApiKey($feed);

        if ($apiKey === '') {
            return $item;
        }

        $model = self::model($feed, 'translate');
        $item['title'] = self::translateTextGemini((string)($item['title'] ?? ''), $apiKey, $model, false, false, 'title');
        $item['summary'] = self::translateTextGemini((string)($item['summary'] ?? ''), $apiKey, $model, false, false, 'summary');
        $item['content'] = self::translateTextGemini((string)($item['content'] ?? ''), $apiKey, $model, true, true, 'content');

        return $item;
    }

    private static function translateWithOllama(array $item, array $feed): array
    {
        $url = self::ollamaUrl($feed);

        if ($url === '') {
            return $item;
        }

        $model = self::model($feed, 'translate');
        $item['title'] = self::translateTextOllama((string)($item['title'] ?? ''), $url, $model, false, false, 'title');
        $item['summary'] = self::translateTextOllama((string)($item['summary'] ?? ''), $url, $model, false, false, 'summary');
        $item['content'] = self::translateTextOllama((string)($item['content'] ?? ''), $url, $model, true, true, 'content');

        return $item;
    }

    private static function rewriteWithGemini(array $item, array $feed): array
    {
        $apiKey = self::geminiApiKey($feed);

        if ($apiKey === '') {
            return $item;
        }

        $model = self::model($feed, 'rewrite');
        $prompt = self::rewritePrompt($feed);
        $item['title'] = self::rewriteTextGemini((string)($item['title'] ?? ''), $apiKey, $model, false, 'title', $prompt);
        $item['summary'] = self::rewriteTextGemini((string)($item['summary'] ?? ''), $apiKey, $model, false, 'summary', $prompt);
        $item['content'] = self::rewriteTextGemini((string)($item['content'] ?? ''), $apiKey, $model, true, 'content', $prompt);

        return $item;
    }

    private static function rewriteWithOllama(array $item, array $feed): array
    {
        $url = self::ollamaUrl($feed);

        if ($url === '') {
            return $item;
        }

        $model = self::model($feed, 'rewrite');
        $prompt = self::rewritePrompt($feed);
        $item['title'] = self::rewriteTextOllama((string)($item['title'] ?? ''), $url, $model, false, 'title', $prompt);
        $item['summary'] = self::rewriteTextOllama((string)($item['summary'] ?? ''), $url, $model, false, 'summary', $prompt);
        $item['content'] = self::rewriteTextOllama((string)($item['content'] ?? ''), $url, $model, true, 'content', $prompt);

        return $item;
    }

    private static function translateTextGemini(string $text, string $apiKey, string $model, bool $html, bool $cleanBloat, string $field): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (self::looksPortuguese($text)) {
            return $text;
        }

        return self::processTextGemini($text, $apiKey, $model, 'translate', $html, $cleanBloat, $field, '');
    }

    private static function translateTextOllama(string $text, string $baseUrl, string $model, bool $html, bool $cleanBloat, string $field): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (self::looksPortuguese($text)) {
            return $text;
        }

        return self::processTextOllama($text, $baseUrl, $model, 'translate', $html, $cleanBloat, $field, '');
    }

    private static function rewriteTextGemini(string $text, string $apiKey, string $model, bool $html, string $field, string $customPrompt): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return self::processTextGemini($text, $apiKey, $model, 'rewrite', $html, true, $field, $customPrompt);
    }

    private static function rewriteTextOllama(string $text, string $baseUrl, string $model, bool $html, string $field, string $customPrompt): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return self::processTextOllama($text, $baseUrl, $model, 'rewrite', $html, true, $field, $customPrompt);
    }

    private static function processTextGemini(string $text, string $apiKey, string $model, string $mode, bool $html, bool $cleanBloat, string $field, string $customPrompt): string
    {
        $model = self::normalizeModel($model);
        $cacheKey = self::cacheKey($mode, $customPrompt);
        $cached = self::cacheGet($text, 'gemini', $model, $cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $chunks = $html ? self::splitHtml($text) : [$text];
        $resultParts = [];

        foreach ($chunks as $chunk) {
            $payload = [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => self::prompt($chunk, $mode, $html, $cleanBloat, $field, $customPrompt)]]
                ]],
                'generationConfig' => [
                    'temperature' => $mode === 'rewrite' ? 0.65 : 0.2,
                    'topP' => $mode === 'rewrite' ? 0.9 : 0.8,
                    'maxOutputTokens' => 8192
                ]
            ];

            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
            $response = self::postJson($endpoint, $payload, 90);
            $out = self::extractGeminiText($response);

            if ($out === '') {
                $resultParts[] = $chunk;
                continue;
            }

            $resultParts[] = self::cleanAiOutput($out, $html);
        }

        $result = trim(implode("\n\n", $resultParts));

        if ($result === '') {
            $result = $text;
        }

        self::cacheSet($text, $result, 'gemini', $model, $cacheKey);

        return $result;
    }

    private static function processTextOllama(string $text, string $baseUrl, string $model, string $mode, bool $html, bool $cleanBloat, string $field, string $customPrompt): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $cacheKey = self::cacheKey($mode, $customPrompt);
        $cached = self::cacheGet($text, 'ollama', $model, $cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $chunks = $html ? self::splitHtml($text) : [$text];
        $resultParts = [];

        foreach ($chunks as $chunk) {
            $payload = [
                'model' => $model,
                'prompt' => self::prompt($chunk, $mode, $html, $cleanBloat, $field, $customPrompt),
                'stream' => false,
                'options' => [
                    'temperature' => $mode === 'rewrite' ? 0.65 : 0.2,
                    'top_p' => $mode === 'rewrite' ? 0.9 : 0.8
                ]
            ];

            $response = self::postJson($baseUrl . '/api/generate', $payload, 180);
            $out = trim((string)($response['response'] ?? ''));

            if ($out === '') {
                $resultParts[] = $chunk;
                continue;
            }

            $resultParts[] = self::cleanAiOutput($out, $html);
        }

        $result = trim(implode("\n\n", $resultParts));

        if ($result === '') {
            $result = $text;
        }

        self::cacheSet($text, $result, 'ollama', $model, $cacheKey);

        return $result;
    }

    private static function prompt(string $text, string $mode, bool $html, bool $cleanBloat, string $field, string $customPrompt): string
    {
        if ($mode === 'rewrite') {
            return self::rewriteInstruction($text, $html, $field, $customPrompt);
        }

        if ($html && $cleanBloat) {
            return "Você é um assistente especializado em extração de artigos e tradução. Sua tarefa é limpar o HTML abaixo, removendo lixo visual e estrutural do site, e traduzir o conteúdo principal para o Português do Brasil.\n\nREGRAS DE LIMPEZA:\n1. Remova elementos que não fazem parte do artigo principal, como menus, banners, rodapés, anúncios, leia também, botões de redes sociais, formulários, comentários e iframes não essenciais.\n2. Exclua tags script, style, nav, header, footer, aside e form.\n3. Remova atributos desnecessários das tags, como class, id, style, onclick e data-*. Mantenha apenas href em links e src/alt em imagens.\n4. Mantenha uma estrutura semântica limpa para leitura, usando p, h2, h3, ul, li, strong, em, blockquote, img e a.\n\nREGRAS DE TRADUÇÃO:\n1. Traduza mantendo o sentido original e fluidez natural em PT-BR.\n2. Não resuma o conteúdo do artigo.\n3. Preserve nomes próprios, marcas, siglas, termos técnicos, datas, números e valores.\n\nSAÍDA:\nRetorne estritamente o HTML resultante. Não adicione saudações, explicações, comentários ou markdown.\n\nHTML ORIGINAL:\n" . $text;
        }

        if ($html) {
            return "Traduza o conteúdo abaixo para português do Brasil, mantendo o HTML original. Não resuma. Não explique. Não adicione comentários. Preserve links, imagens, tags, atributos, nomes próprios, marcas, siglas, datas, números e valores. Retorne somente o HTML traduzido, sem markdown.\n\nCONTEÚDO:\n" . $text;
        }

        return "Traduza o texto abaixo para português do Brasil. Não resuma. Não explique. Preserve nomes próprios, marcas, siglas, datas, números e valores. Retorne somente o texto traduzido, sem formatações adicionais.\n\nTEXTO:\n" . $text;
    }

    private static function rewriteInstruction(string $text, bool $html, string $field, string $customPrompt): string
    {
        $base = trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultRewritePrompt();

        if ($field === 'title') {
            return $base . "\n\nTAREFA ESPECÍFICA:\nCrie um novo título jornalístico, forte, claro e otimizado para SEO. Preserve o fato central, nomes próprios e informações essenciais. Não use travessão. Não coloque aspas desnecessárias. Retorne somente o título final, sem explicações.\n\nTÍTULO ORIGINAL:\n" . $text;
        }

        if ($field === 'summary') {
            return $base . "\n\nTAREFA ESPECÍFICA:\nReescreva como resumo ou descrição SEO curta, com texto original, natural e chamativo. Preserve a informação central. Não use travessão. Retorne somente o resumo final, sem explicações.\n\nRESUMO ORIGINAL:\n" . $text;
        }

        if ($html) {
            return $base . "\n\nTAREFA ESPECÍFICA:\nReescreva o conteúdo em HTML abaixo como uma matéria jornalística original em português do Brasil. Preserve todos os fatos, nomes, cargos, datas, números, locais e valores. Não invente dados. Não copie frases longas do texto original. Mude estrutura, vocabulário, ritmo e construção dos parágrafos. Otimize naturalmente para SEO, com boa escaneabilidade e leitura fluida. Não use travessão. Mantenha apenas HTML limpo e semântico, usando p, h2, h3, ul, li, strong, em, blockquote, img e a. Preserve imagens e links úteis quando fizerem sentido jornalístico. Remova menus, anúncios, botões, scripts, estilos, comentários, rodapés e blocos de leia também. Retorne somente o HTML final, sem markdown, sem explicações e sem assinatura.\n\nHTML ORIGINAL:\n" . $text;
        }

        return $base . "\n\nTAREFA ESPECÍFICA:\nReescreva o texto abaixo de forma original, clara e jornalística. Preserve o sentido e os fatos. Não use travessão. Retorne somente o texto final, sem explicações.\n\nTEXTO ORIGINAL:\n" . $text;
    }

    private static function provider(array $feed, string $mode): string
    {
        if ($mode === 'rewrite') {
            $provider = trim((string)($feed['rewrite_provider'] ?? ''));

            if ($provider !== '') {
                return $provider;
            }
        }

        $provider = trim((string)($feed['translate_provider'] ?? 'gemini'));

        return $provider !== '' ? $provider : 'gemini';
    }

    private static function model(array $feed, string $mode): string
    {
        if ($mode === 'rewrite') {
            $model = trim((string)($feed['rewrite_model'] ?? ''));

            if ($model !== '') {
                return self::normalizeModel($model);
            }
        }

        $model = trim((string)($feed['translate_model'] ?? ''));

        if ($model === '') {
            $model = trim((string)Settings::get('translate_model', self::DEFAULT_GEMINI_MODEL));
        }

        if ($model === '') {
            $model = self::DEFAULT_GEMINI_MODEL;
        }

        if (self::provider($feed, $mode) === 'ollama' && $model === self::DEFAULT_GEMINI_MODEL) {
            return self::DEFAULT_OLLAMA_MODEL;
        }

        return self::normalizeModel($model);
    }

    private static function rewritePrompt(array $feed): string
    {
        $prompt = trim((string)($feed['rewrite_prompt'] ?? ''));

        return $prompt !== '' ? $prompt : self::defaultRewritePrompt();
    }

    private static function geminiApiKey(array $feed): string
    {
        $apiKey = trim((string)($feed['gemini_api_key'] ?? ''));

        if ($apiKey === '') {
            $apiKey = trim((string)Settings::get('gemini_api_key', ''));
        }

        return $apiKey;
    }

    private static function ollamaUrl(array $feed): string
    {
        $url = trim((string)($feed['ollama_url'] ?? ''));

        if ($url === '') {
            $url = trim((string)Settings::get('ollama_url', 'http://127.0.0.1:11434'));
        }

        return $url;
    }

    private static function normalizeModel(string $model): string
    {
        $model = trim($model);

        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return $model !== '' ? $model : self::DEFAULT_GEMINI_MODEL;
    }

    private static function splitHtml(string $html): array
    {
        $html = trim($html);

        if (mb_strlen($html, 'UTF-8') <= 8500) {
            return [$html];
        }

        $parts = preg_split('~(?=</p>|</div>|</h2>|</h3>|</li>|<h2|<h3)~iu', $html) ?: [$html];
        $chunks = [];
        $current = '';

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (mb_strlen($current . "\n" . $part, 'UTF-8') > 8500 && $current !== '') {
                $chunks[] = trim($current);
                $current = $part;
            } else {
                $current .= "\n" . $part;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks ?: [$html];
    }

    private static function extractGeminiText(array $response): string
    {
        $text = '';

        if (isset($response['candidates'][0]['content']['parts'])) {
            foreach ($response['candidates'][0]['content']['parts'] as $part) {
                $text .= (string)($part['text'] ?? '');
            }
        }

        return trim($text);
    }

    private static function cleanAiOutput(string $text, bool $html): string
    {
        $text = trim($text);
        $text = preg_replace('~^```(?:html|xml|text|json)?\s*~iu', '', $text) ?? $text;
        $text = preg_replace('~\s*```$~u', '', $text) ?? $text;
        $text = trim($text);

        if ($html) {
            $text = preg_replace('~</?(?:html|body|head)[^>]*>~iu', '', $text) ?? $text;
            $text = preg_replace('~<!doctype[^>]*>~iu', '', $text) ?? $text;
        } else {
            $text = trim(strip_tags($text));
            $text = preg_replace('~^["“”\']+|["“”\']+$~u', '', $text) ?? $text;
        }

        return trim($text);
    }

    private static function postJson(string $url, array $payload, int $timeout): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('Erro ao chamar IA: ' . $err);
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new RuntimeException('Resposta inválida da IA.');
        }

        if ($code >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException('Erro da IA: ' . $msg);
        }

        return $json;
    }

    private static function looksPortuguese(string $text): bool
    {
        $plain = mb_strtolower(strip_tags($text), 'UTF-8');

        if (mb_strlen($plain, 'UTF-8') < 80) {
            return false;
        }

        $hits = 0;

        foreach ([' de ', ' que ', ' para ', ' com ', ' uma ', ' não ', 'ção', 'ões', ' está ', ' por ', ' mais '] as $term) {
            if (str_contains($plain, $term)) {
                $hits++;
            }
        }

        return $hits >= 4;
    }

    private static function cacheGet(string $source, string $provider, string $model, string $mode): ?string
    {
        try {
            self::ensureCacheTable();
            $hash = self::hash($source, $provider, $model, $mode);
            $stmt = Database::pdo()->prepare('SELECT translated_text FROM translation_cache WHERE hash = :hash LIMIT 1');
            $stmt->execute([':hash' => $hash]);
            $value = $stmt->fetchColumn();

            return $value !== false ? (string)$value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function cacheSet(string $source, string $translated, string $provider, string $model, string $mode): void
    {
        try {
            self::ensureCacheTable();
            $stmt = Database::pdo()->prepare('INSERT OR REPLACE INTO translation_cache (hash, provider, model, source_text, translated_text, created_at) VALUES (:hash, :provider, :model, :source_text, :translated_text, :created_at)');
            $stmt->execute([
                ':hash' => self::hash($source, $provider, $model, $mode),
                ':provider' => $provider,
                ':model' => $model . '|' . $mode,
                ':source_text' => $source,
                ':translated_text' => $translated,
                ':created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable) {
        }
    }

    private static function cacheKey(string $mode, string $customPrompt): string
    {
        return $mode . '|v4|' . sha1($customPrompt);
    }

    private static function hash(string $source, string $provider, string $model, string $mode): string
    {
        return hash('sha256', $mode . '|' . $provider . '|' . $model . '|' . $source);
    }

    private static function ensureCacheTable(): void
    {
        Database::pdo()->exec('CREATE TABLE IF NOT EXISTS translation_cache (
            hash TEXT PRIMARY KEY,
            provider TEXT NOT NULL,
            model TEXT NOT NULL,
            source_text TEXT NOT NULL,
            translated_text TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
}
