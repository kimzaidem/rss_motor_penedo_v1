<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
Auth::require();
$url = trim((string)($_GET['url'] ?? ''));
if ($url === '') {
    exit('Informe a URL.');
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Seletor ao vivo - RSS Motor Penedo</title>
<link rel="stylesheet" href="assets/app.css">
<script>window.RSSMOTOR_CSRF=<?=json_encode(csrf_token())?>;window.SELECTOR_URL=<?=json_encode($url)?>;</script>
</head>
<body class="selector-page">
<header class="selector-top">
<div class="selector-title"><h1>Selecione</h1><span><?=h($url)?></span></div>
<div class="selector-modes">
<button data-mode="item_selector">Bloco de item</button>
<button data-mode="title_selector" class="active">Título</button>
<button data-mode="description_selector">Descrição</button>
<button data-mode="date_selector">Data</button>
<button data-mode="image_selector">Imagem</button>
<button data-mode="link_selector">Link</button>
<button data-mode="content_selector">Conteúdo do artigo</button>
</div>
<div class="selector-actions"><label><input type="checkbox" id="blockScripts" checked> bloquear scripts</label><button class="btn secondary" id="reloadFrame">recarregar</button><button class="btn secondary" id="testSelectors">testar</button><button class="btn primary" id="applySelectors">Aplicar seletores</button></div>
</header>
<main class="selector-main">
<aside class="selector-side">
<div id="selectedInfo" class="selected-info"><b>Nenhum elemento selecionado</b><p>Clique em um elemento da página.</p></div>
<div class="selector-fields">
<label>Bloco de item <small data-count="item_selector"></small><input name="item_selector"></label>
<label>Título <small data-count="title_selector"></small><input name="title_selector"></label>
<label>Descrição <small data-count="description_selector"></small><input name="description_selector"></label>
<label>Data <small data-count="date_selector"></small><input name="date_selector"></label>
<label>Imagem <small data-count="image_selector"></small><input name="image_selector"></label>
<label>Link <small data-count="link_selector"></small><input name="link_selector"></label>
<label>Conteúdo do artigo <small data-count="content_selector"></small><input name="content_selector"></label>
</div>
<div class="selector-test"><h3>Teste</h3><div id="selectorTestResult">Use o botão testar depois de marcar os elementos.</div></div>
</aside>
<section class="selector-frame-wrap"><iframe id="siteFrame" src="proxy.php?url=<?=rawurlencode($url)?>&block_scripts=1"></iframe></section>
</main>
<script src="assets/live-selector.js"></script>
</body>
</html>
