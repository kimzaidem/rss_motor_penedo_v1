<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
if (!Database::hasUser()) {
    redirect_to('install.php');
}
$page = $_GET['page'] ?? 'dashboard';
$error = '';
$notice = '';
if ($page === 'login') {
    if (Auth::check()) {
        redirect_to('index.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (Auth::login(trim((string)($_POST['email'] ?? '')), (string)($_POST['password'] ?? ''))) {
            redirect_to('index.php');
        }
        $error = 'E-mail ou senha inválidos.';
    }
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><main class="login-box"><h1>RSS Motor Penedo</h1><p>Entre para administrar seus feeds.</p><?php if ($error): ?><div class="alert error"><?=h($error)?></div><?php endif; ?><form method="post" class="stack"><?=csrf_field()?><label>E-mail<input name="email" type="email" required autofocus></label><label>Senha<input name="password" type="password" required></label><button class="btn primary">Entrar</button></form></main></body></html>
    <?php
    exit;
}
if ($page === 'logout') {
    Auth::logout();
    redirect_to('index.php?page=login');
}
Auth::require();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_feed') {
            $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
            $saved = FeedRepository::save($_POST, $id);
            redirect_to('index.php?page=edit&id=' . $saved . '&saved=1');
        }
        if ($action === 'delete_feed') {
            FeedRepository::delete((int)($_POST['id'] ?? 0));
            redirect_to('index.php?deleted=1');
        }
        if ($action === 'run_feed') {
            Engine::runOne((int)($_POST['id'] ?? 0));
            redirect_to('index.php?page=edit&id=' . (int)$_POST['id'] . '&ran=1');
        }
        if ($action === 'save_settings') {
            Settings::set('cron_key', trim((string)($_POST['cron_key'] ?? Settings::cronKey())) ?: Settings::cronKey());
            Settings::set('gemini_api_key', trim((string)($_POST['gemini_api_key'] ?? '')));
            Settings::set('translate_model', trim((string)($_POST['translate_model'] ?? 'gemini-2.5-flash-lite')) ?: 'gemini-2.5-flash-lite');
            Settings::set('ollama_url', trim((string)($_POST['ollama_url'] ?? '')));
            redirect_to('index.php?page=settings&saved=1');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
if (isset($_GET['saved'])) {
    $notice = 'Feed salvo com sucesso.';
}
if (isset($_GET['ran'])) {
    $notice = 'Busca executada. Veja o status e o log abaixo.';
}
if (isset($_GET['deleted'])) {
    $notice = 'Feed apagado.';
}
$user = Auth::user();
function checked(mixed $v): string { return (int)$v === 1 ? 'checked' : ''; }
function val(array $feed, string $key, mixed $default = ''): string { return h($feed[$key] ?? $default); }
function setting_val(string $key, string $default = ''): string
{
    try {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (string)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}
function layout_start(string $title, ?array $user): void { ?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> - RSS Motor Penedo</title><link rel="stylesheet" href="assets/app.css"><script>window.RSSMOTOR_CSRF=<?=json_encode(csrf_token())?>;</script></head><body><header class="topbar"><div><a class="brand" href="index.php">RSS Motor Penedo</a><span>gerador pessoal de RSS full-text</span></div><nav><a href="index.php">Feeds</a><a href="index.php?page=settings">Configurações</a><a href="index.php?page=logout">Sair</a></nav></header><main class="wrap"><?php }
function layout_end(): void { ?></main><script src="assets/app.js"></script></body></html><?php }
function feed_form(array $feed, string $error, string $notice): void { ?>
<?php if ($error): ?><div class="alert error"><?=h($error)?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert ok"><?=h($notice)?></div><?php endif; ?>
<form method="post" id="feedForm" class="feed-form">
<?=csrf_field()?>
<input type="hidden" name="action" value="save_feed">
<?php if (!empty($feed['id'])): ?><input type="hidden" name="id" value="<?=h($feed['id'])?>"><?php endif; ?>
<section class="grid two">
<div class="card"><h2>Fonte</h2><div class="grid two"><label>Nome do feed<input name="name" required value="<?=val($feed,'name')?>"></label><label>Slug<input name="slug" value="<?=val($feed,'slug')?>" placeholder="gerado automaticamente"></label></div><label>URL da página/listagem<input name="list_url" id="list_url" required value="<?=val($feed,'list_url')?>" placeholder="https://site.com/editoria/"></label><div class="grid two"><label>Intervalo de busca em minutos<input name="interval_minutes" type="number" min="2" value="<?=val($feed,'interval_minutes',60)?>"></label><label>Máximo de itens por busca<input name="max_items" type="number" min="1" max="100" value="<?=val($feed,'max_items',20)?>"></label></div><div class="actions"><button type="button" class="btn ghost" onclick="openLiveSelector()">Selecionar na página</button><button type="button" class="btn ghost" onclick="previewFeed()">Testar raspagem</button></div></div>
<div class="card"><h2>Status</h2><div class="checks"><label><input type="checkbox" name="active" <?=checked($feed['active'] ?? 1)?>> ativo</label><label><input type="checkbox" name="full_text" <?=checked($feed['full_text'] ?? 1)?>> buscar texto completo</label><label><input type="checkbox" name="auto_extract" <?=checked($feed['auto_extract'] ?? 1)?>> usar extração automática</label><label><input type="checkbox" name="include_images" <?=checked($feed['include_images'] ?? 1)?>> incluir imagens no conteúdo</label><label><input type="checkbox" name="keep_html" <?=checked($feed['keep_html'] ?? 1)?>> manter HTML no RSS</label><label><input type="checkbox" name="require_token" <?=checked($feed['require_token'] ?? 1)?>> exigir token no link RSS</label></div><?php if (!empty($feed['id'])): ?><div class="status-box"><p><b>Última busca:</b> <?=h($feed['last_fetch_at'] ?: 'nunca')?></p><p><b>Status:</b> <?=h($feed['last_status'] ?: 'sem execução')?></p><p><b>Itens na última busca:</b> <?=h($feed['last_count'] ?? 0)?></p><?php if (!empty($feed['translate_enabled'])): ?><p><b>Tradução:</b> ativada, <?=h($feed['translate_provider'] ?? 'gemini')?> / <?=h($feed['translate_model'] ?? 'gemini-2.5-flash-lite')?></p><?php endif; ?><?php if (!empty($feed['rewrite_enabled'])): ?><p><b>Reescrita SEO:</b> ativada, <?=h($feed['rewrite_provider'] ?? 'gemini')?> / <?=h($feed['rewrite_model'] ?? 'gemini-2.5-flash-lite')?></p><?php endif; ?><?php if (!empty($feed['last_error'])): ?><p class="error-text"><?=h($feed['last_error'])?></p><?php endif; ?></div><?php endif; ?></div>
</section>
<section class="card"><div class="section-head"><h2>Seletores</h2><span>Selecione principalmente título, descrição, data e imagem. O bloco e o link ajudam muito quando o site é mais complicado.</span></div><div class="selector-checklist"><span data-check="title_selector">Título</span><span data-check="description_selector">Descrição</span><span data-check="date_selector">Data</span><span data-check="image_selector">Imagem</span></div><div class="grid three"><label>Bloco de item<input name="item_selector" value="<?=val($feed,'item_selector')?>" placeholder="article, .td_module_wrap"></label><label>Link<input name="link_selector" value="<?=val($feed,'link_selector')?>" placeholder=".entry-title a"></label><label>Título<input name="title_selector" value="<?=val($feed,'title_selector')?>" placeholder=".entry-title a"></label><label>Descrição<input name="description_selector" value="<?=val($feed,'description_selector')?>" placeholder=".td-excerpt, p"></label><label>Data<input name="date_selector" value="<?=val($feed,'date_selector')?>" placeholder="time, .td-post-date"></label><label>Imagem<input name="image_selector" value="<?=val($feed,'image_selector')?>" placeholder="img"></label><label class="wide">Conteúdo do artigo<input name="content_selector" value="<?=val($feed,'content_selector')?>" placeholder=".td-post-content, .entry-content"></label></div><div class="hint">Se deixar conteúdo do artigo vazio, o motor tenta descobrir sozinho o texto principal da notícia.</div></section>
<section class="grid two"><div class="card"><h2>Filtros opcionais</h2><label>Incluir apenas se tiver estas palavras<textarea name="include_filter" rows="4"><?=val($feed,'include_filter')?></textarea></label><label>Excluir se tiver estas palavras<textarea name="exclude_filter" rows="4"><?=val($feed,'exclude_filter')?></textarea></label></div><div class="card"><h2>Avançado</h2><label>Cookies, se precisar<textarea name="cookies" rows="4"><?=val($feed,'cookies')?></textarea></label><label>User-Agent<input name="user_agent" value="<?=val($feed,'user_agent')?>" placeholder="deixe vazio para usar padrão"></label></div></section>
<section class="card translation-card">
    <div class="section-head">
        <h2>Tradução por IA</h2>
        <span>Ative apenas nos feeds de sites em inglês ou em outro idioma.</span>
    </div>

    <input type="hidden" name="translate_enabled" value="0">

    <label class="ai-switch">
        <input type="checkbox" name="translate_enabled" value="1" <?= !empty($feed['translate_enabled']) ? 'checked' : '' ?>>
        <span class="ai-switch-ui"></span>
        <strong>Traduzir este feed para português</strong>
    </label>

    <div class="grid two">
        <label>
            Provedor
            <select name="translate_provider">
                <option value="gemini" <?= (($feed['translate_provider'] ?? 'gemini') === 'gemini') ? 'selected' : '' ?>>Gemini API</option>
                <option value="ollama" <?= (($feed['translate_provider'] ?? '') === 'ollama') ? 'selected' : '' ?>>Ollama local/VPS</option>
            </select>
        </label>

        <label>
            Modelo
            <input name="translate_model" value="<?= val($feed, 'translate_model', 'gemini-2.5-flash-lite') ?>" placeholder="gemini-2.5-flash-lite">
        </label>
    </div>

    <div class="grid two">
        <label>
            Chave Gemini API
            <input type="password" name="gemini_api_key" value="<?= val($feed, 'gemini_api_key') ?>" placeholder="se vazio, usa a chave global das configurações">
        </label>

        <label>
            URL do Ollama
            <input name="ollama_url" value="<?= val($feed, 'ollama_url') ?>" placeholder="http://127.0.0.1:11434">
        </label>
    </div>

    <div class="hint">A tradução acontece durante a raspagem, no cron ou no botão Rodar agora. O RSS já sai salvo em português para o WP Automatic.</div>
</section>
<section class="card translation-card">
    <div class="section-head">
        <h2>Reescrita original + SEO por IA</h2>
        <span>Ative quando quiser que o RSS salve a matéria com outro estilo jornalístico.</span>
    </div>

    <input type="hidden" name="rewrite_enabled" value="0">

    <label class="ai-switch">
        <input type="checkbox" name="rewrite_enabled" value="1" <?= !empty($feed['rewrite_enabled']) ? 'checked' : '' ?>>
        <span class="ai-switch-ui"></span>
        <strong>Reescrever título, resumo e conteúdo com IA</strong>
    </label>

    <div class="grid two">
        <label>
            Provedor
            <select name="rewrite_provider">
                <option value="gemini" <?= (($feed['rewrite_provider'] ?? 'gemini') === 'gemini') ? 'selected' : '' ?>>Gemini API</option>
                <option value="ollama" <?= (($feed['rewrite_provider'] ?? '') === 'ollama') ? 'selected' : '' ?>>Ollama local/VPS</option>
            </select>
        </label>

        <label>
            Modelo
            <input name="rewrite_model" value="<?= val($feed, 'rewrite_model', 'gemini-2.5-flash-lite') ?>" placeholder="gemini-2.5-flash-lite">
        </label>
    </div>

    <label>
        Prompt da reescrita
        <textarea name="rewrite_prompt" rows="8"><?= val($feed, 'rewrite_prompt', class_exists('AiTranslator') ? AiTranslator::defaultRewritePrompt() : '') ?></textarea>
    </label>

    <div class="hint">Esse recurso não é executado no botão Testar raspagem para evitar gasto desnecessário de IA. Ele roda no cron e no botão Rodar agora.</div>
</section>
<div id="previewBox" class="card preview hidden"><h2>Resultado do teste</h2><div id="previewContent"></div></div>
<div class="floating-actions"><button class="btn primary">Salvar feed</button><?php if (!empty($feed['id'])): ?><button class="btn secondary" form="runForm">Rodar agora</button><a class="btn ghost" href="<?=h(RssBuilder::feedUrl($feed))?>" target="_blank">Abrir RSS</a><?php endif; ?></div>
</form>
<?php if (!empty($feed['id'])): ?><form id="runForm" method="post" class="hidden"><?=csrf_field()?><input type="hidden" name="action" value="run_feed"><input type="hidden" name="id" value="<?=h($feed['id'])?>"></form><?php endif; ?>
<?php if (!empty($feed['id'])): ?><section class="grid two lower"><div class="card"><h2>Itens capturados</h2><?php foreach (FeedRepository::items((int)$feed['id'], 10) as $item): ?><div class="mini-item"><b><?=h($item['title'])?></b><small><?=h($item['published_at'] ?: $item['created_at'])?></small><a href="<?=h($item['url'])?>" target="_blank"><?=h($item['url'])?></a></div><?php endforeach; ?></div><div class="card"><h2>Log</h2><?php foreach (FeedRepository::recentLogs((int)$feed['id'], 10) as $log): ?><div class="log <?=h($log['level'])?>"><small><?=h($log['created_at'])?>, <?=h($log['level'])?></small><p><?=h($log['message'])?></p></div><?php endforeach; ?></div></section><?php endif; ?>
<?php }
if ($page === 'new' || $page === 'edit') {
    $feed = [
        'active' => 1, 'full_text' => 1, 'auto_extract' => 1, 'include_images' => 1, 'keep_html' => 1, 'require_token' => 1,
        'interval_minutes' => 60, 'max_items' => 20,
        'translate_enabled' => 0, 'translate_provider' => 'gemini', 'translate_model' => setting_val('translate_model', 'gemini-2.5-flash-lite'), 'gemini_api_key' => '', 'ollama_url' => '',
        'rewrite_enabled' => 0, 'rewrite_provider' => 'gemini', 'rewrite_model' => setting_val('translate_model', 'gemini-2.5-flash-lite'), 'rewrite_prompt' => class_exists('AiTranslator') ? AiTranslator::defaultRewritePrompt() : ''
    ];
    if ($page === 'edit') {
        $feed = FeedRepository::find((int)($_GET['id'] ?? 0));
        if (!$feed) {
            redirect_to('index.php');
        }
    }
    layout_start($page === 'new' ? 'Novo feed' : 'Editar feed', $user);
    ?><div class="title-row"><div><h1><?= $page === 'new' ? 'Novo feed' : 'Editar feed' ?></h1><p>Gerador pessoal de RSS full-text para usar no WP Automatic.</p></div><a class="btn ghost" href="index.php">Voltar</a></div><?php
    feed_form($feed, $error, $notice);
    layout_end();
    exit;
}
if ($page === 'settings') {
    layout_start('Configurações', $user);
    $cronUrl = base_url('cron.php?key=' . Settings::cronKey());
    ?><div class="title-row"><div><h1>Configurações</h1><p>Chave do cron, instalação e tradução por IA.</p></div></div><?php if (isset($_GET['saved'])): ?><div class="alert ok">Configurações salvas.</div><?php endif; ?><?php if ($error): ?><div class="alert error"><?=h($error)?></div><?php endif; ?><section class="card"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="save_settings"><label>Chave secreta do cron<input name="cron_key" value="<?=h(Settings::cronKey())?>"></label><h2>Tradução por IA</h2><label>Chave Gemini API global<input type="password" name="gemini_api_key" value="<?=h(setting_val('gemini_api_key'))?>" placeholder="cole a chave do Google AI Studio"></label><label>Modelo Gemini padrão<input name="translate_model" value="<?=h(setting_val('translate_model','gemini-2.5-flash-lite'))?>" placeholder="gemini-2.5-flash-lite"></label><label>URL padrão do Ollama, opcional<input name="ollama_url" value="<?=h(setting_val('ollama_url'))?>" placeholder="http://127.0.0.1:11434"></label><button class="btn primary">Salvar</button></form></section><section class="card"><h2>URL para cron</h2><input readonly value="<?=h($cronUrl)?>" onclick="this.select()"><p class="hint">No cPanel, crie um cron de 1 em 1 minuto chamando essa URL com curl ou wget. O sistema decide sozinho quais feeds estão vencidos.</p><pre>wget -q -O - "<?=h($cronUrl)?>" >/dev/null 2>&1</pre></section><?php
    layout_end();
    exit;
}
layout_start('Feeds', $user);
$feeds = FeedRepository::all();
?>
<div class="title-row"><div><h1>Feeds</h1><p>Seus motores de RSS full-text.</p></div><a class="btn primary" href="index.php?page=new">+ Novo feed</a></div>
<?php if ($notice): ?><div class="alert ok"><?=h($notice)?></div><?php endif; ?>
<section class="feed-list">
<?php if (!$feeds): ?><div class="card empty"><h2>Nenhum feed ainda</h2><p>Crie seu primeiro feed e use o seletor ao vivo para marcar título, descrição, data e imagem.</p><a class="btn primary" href="index.php?page=new">Criar feed</a></div><?php endif; ?>
<?php foreach ($feeds as $feed): ?><article class="card feed-card"><div><h2><?=h($feed['name'])?> <?php if (!empty($feed['translate_enabled'])): ?><span class="feed-badge">IA PT-BR</span><?php endif; ?><?php if (!empty($feed['rewrite_enabled'])): ?><span class="feed-badge">IA SEO</span><?php endif; ?></h2><p><?=h($feed['list_url'])?></p><small>Status: <?=h($feed['last_status'] ?: 'sem execução')?>, itens: <?=h($feed['item_count'] ?? 0)?>, próxima busca: <?=h($feed['next_fetch_at'] ?: 'não agendada')?></small><?php if (!empty($feed['last_error'])): ?><p class="error-text"><?=h($feed['last_error'])?></p><?php endif; ?></div><div class="feed-actions"><a class="btn ghost" href="index.php?page=edit&id=<?=h($feed['id'])?>">Editar</a><a class="btn ghost" href="<?=h(RssBuilder::feedUrl($feed))?>" target="_blank">RSS</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="run_feed"><input type="hidden" name="id" value="<?=h($feed['id'])?>"><button class="btn secondary">Rodar</button></form><form method="post" onsubmit="return confirm('Apagar este feed?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_feed"><input type="hidden" name="id" value="<?=h($feed['id'])?>"><button class="btn danger">Apagar</button></form></div></article><?php endforeach; ?>
</section>
<?php layout_end();