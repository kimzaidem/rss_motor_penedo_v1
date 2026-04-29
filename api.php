<?php
require __DIR__ . '/app/bootstrap.php';
Database::pdo();
Auth::require();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
try {
    if ($action === 'run_now') {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        json_response(['ok' => true, 'result' => Engine::runOne($id)]);
    }
    if ($action === 'preview' || $action === 'test_selectors') {
        verify_csrf();
        $data = $_POST;
        $data['active'] = 1;
        $data['full_text'] = isset($data['full_text']) ? 1 : 0;
        if ($action === 'test_selectors') {
            $data['full_text'] = 0;
            $data['auto_extract'] = 1;
            $data['include_images'] = 1;
            $data['keep_html'] = 1;
        }
        $items = Engine::preview($data);
        json_response(['ok' => true, 'count' => count($items), 'items' => $items]);
    }
    json_response(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
