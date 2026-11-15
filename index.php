<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
session_set_cookie_params(['path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
function fail(int $status, string $message): never {
    http_response_code($status);
    exit(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$code = $_GET['code'] ?? '';
if (!is_string($code) || !hash_equals('02634c2a4e21183416e32c5d9cc39491455d5dcebc8d5fdc18f5ddd4e7f2ea2f', hash('sha256', $code))) fail(403, 'Abre el enlace de tu tarjeta NFC.');
$url = '?code=' . rawurlencode($code);
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
session_write_close();
$root = realpath($_SERVER['DOCUMENT_ROOT']);
if ($root === false) fail(500, 'Document root no disponible.');
$dir = getenv('NOTAS_DATA_DIR') ?: dirname($root) . '/aguitech-notas-data';
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) fail(500, 'Configura un directorio privado escribible para Notas.');
$dir = realpath($dir);
if ($dir === false || $dir === $root || str_starts_with($dir, $root . '/')) fail(500, 'El directorio de datos debe quedar fuera del directorio público.');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$lock = fopen($dir . '/notes.lock', 'c');
if (!$lock || !flock($lock, $post ? LOCK_EX : LOCK_SH)) fail(503, 'No se pudo abrir la libreta.');
$csv = $dir . '/notes.csv';
$notes = [];
if (is_file($csv)) {
    $input = fopen($csv, 'r');
    if (!$input) fail(500, 'No se pudo leer la libreta.');
    while (($row = fgetcsv($input, 0, ',', '"', '')) !== false) {
        if (count($row) !== 7) fail(500, 'Hay una fila inválida en la libreta.');
        [$id, $title, $body, $date, $pinned, $name, $mime] = $row;
        $notes[$id] = compact('id','title','body','date','pinned','name','mime');
    }
    fclose($input);
}
$id = $_POST['id'] ?? $_GET['file'] ?? $_GET['edit'] ?? '';
if (!is_string($id) || ($id !== '' && !preg_match('/^[a-f0-9]{32}$/D', $id))) fail(400, 'Identificador inválido.');
$note = $notes[$id] ?? null;
if (isset($_GET['file']) && !$post) {
    if (!$note || $note['name'] === '') fail(404, 'Adjunto no encontrado.');
    $parts = explode(':', $note['mime'], 2);
    $file = $dir . '/' . $parts[1] . '.blob';
    if (!is_file($file)) fail(404, 'Adjunto no encontrado.');
    $inline = str_starts_with($parts[0], 'image/') && !isset($_GET['download']);
    header('Content-Type: ' . $parts[0]);
    header("Content-Disposition: " . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($note['name']));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
if ($post) {
    $submitted = $_POST['csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals($csrf, $submitted)) fail(403, 'Recarga la página y vuelve a intentar.');
    if ($id !== '' && !$note) fail(404, 'La nota ya no existe.');
    $previous = $note && $note['mime'] ? explode(':', $note['mime'], 2)[1] : '';
    $newBlob = '';
    $action = $_POST['action'] ?? '';
    if ($action === 'delete' && $note) {
        unset($notes[$id]);
    } elseif ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || strlen($title) > 480 || strlen($body) > 48000) fail(422, 'Revisa el título y la longitud de la nota.');
        $name = isset($_POST['remove']) ? '' : ($note['name'] ?? '');
        $mime = $name ? $note['mime'] : '';
        $upload = $_FILES['attachment'] ?? null;
        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 10 * 1024 * 1024) fail(422, 'El adjunto debe pesar hasta 10 MB.');
            $type = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if (!in_array($type, ['image/jpeg','image/png','image/webp','image/gif','application/pdf','text/plain','text/csv','application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) fail(422, 'Formato de adjunto no admitido.');
            $name = basename(str_replace(chr(92), '/', $upload['name']));
            $newBlob = bin2hex(random_bytes(16));
            $mime = $type . ':' . $newBlob;
            if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $newBlob . '.blob')) fail(500, 'No se pudo guardar el adjunto.');
        }
        $id = $id ?: bin2hex(random_bytes(16));
        $date = gmdate('Y-m-d H:i:s');
        $pinned = isset($_POST['pinned']) ? '1' : '0';
        $notes[$id] = compact('id','title','body','date','pinned','name','mime');
    } else {
        fail(400, 'Acción inválida.');
    }
    $tmp = tempnam($dir, 'notes-');
    $output = $tmp ? fopen($tmp, 'w') : false;
    $ok = (bool) $output;
    if ($output) {
        foreach ($notes as $row) {
            if (fputcsv($output, $row, ',', '"', '') === false) { $ok = false; break; }
        }
        $ok = fflush($output) && $ok;
        $ok = fclose($output) && $ok;
    }
    if (!$ok || !rename($tmp, $csv)) {
        if ($tmp) @unlink($tmp);
        if ($newBlob) @unlink($dir . '/' . $newBlob . '.blob');
        fail(500, 'No se pudo guardar la libreta.');
    }
    if ($previous && ($action === 'delete' || ($notes[$id]['mime'] ?? '') !== $note['mime'])) @unlink($dir . '/' . $previous . '.blob');
    header('Location: ' . $url, true, 303);
    exit;
}
flock($lock, LOCK_UN);
fclose($lock);
if (isset($_GET['edit']) && !$note) fail(404, 'Nota no encontrada.');
usort($notes, fn($a, $b) => [$b['pinned'], $b['date']] <=> [$a['pinned'], $a['date']]);
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notas · Aguitech</title>
<link rel="stylesheet" href="style.css"><script src="app.js" defer></script>
</head>
<body><main>
<header><a href="<?= e($url) ?>" class="brand"><span aria-hidden="true">A</span> AGUITECH</a><span class="tag">LIBRETA NFC</span></header>
<section class="intro"><h1>Mis notas<span>.</span></h1><p>Ideas, pendientes y archivos en un solo lugar.</p></section>
<div class="layout">
<section class="composer" id="editor">
<h2><?= $note ? 'Editar nota' : 'Nueva nota' ?></h2>
<form method="post" action="<?= e($url) ?>" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($note['id'] ?? '') ?>">
<label>Título<input name="title" maxlength="120" required placeholder="¿Qué quieres recordar?" value="<?= e($note['title'] ?? '') ?>"></label>
<label>Nota<textarea name="body" maxlength="12000" rows="6" placeholder="Escribe aquí…"><?= e($note['body'] ?? '') ?></textarea></label>
<label>Adjunto <small>Un archivo de hasta 10 MB</small><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.zip,.docx,.xlsx"></label>
<?php if (!empty($note['name'])): ?><label class="check"><input type="checkbox" name="remove"> Quitar <?= e($note['name']) ?></label><?php endif ?>
<label class="check"><input type="checkbox" name="pinned" <?= ($note['pinned'] ?? '') === '1' ? 'checked' : '' ?>> Fijar arriba</label>
<div class="actions"><button name="action" value="save">Guardar nota</button><?php if ($note): ?><a href="<?= e($url) ?>">Cancelar</a><?php endif ?></div>
</form>
</section>
<section class="list">
<div class="listhead"><h2>Libreta <small><?= count($notes) ?></small></h2><input id="search" type="search" placeholder="Buscar notas…" aria-label="Buscar notas"></div>
<?php if (!$notes): ?><p class="empty">Tu libreta está vacía. Guarda tu primera nota.</p><?php endif ?>
<p id="no-results" class="empty" hidden>Sin coincidencias.</p>
<?php foreach ($notes as $item): $fileUrl = $url . '&file=' . $item['id']; ?>
<article class="card">
<div class="meta"><time datetime="<?= e(str_replace(' ', 'T', $item['date']) . 'Z') ?>"><?= e($item['date']) ?> UTC</time><?= $item['pinned'] === '1' ? '<span>FIJADA</span>' : '' ?></div>
<h3><?= e($item['title']) ?></h3><p class="body"><?= e($item['body']) ?></p>
<?php if ($item['name'] !== ''): ?>
<?php if (str_starts_with($item['mime'], 'image/')): ?><img src="<?= e($fileUrl) ?>" alt="<?= e($item['name']) ?>" loading="lazy"><?php endif ?>
<a class="attachment" href="<?= e($fileUrl . '&download=1') ?>">↓ <?= e($item['name']) ?></a>
<?php endif ?>
<div class="actions"><a href="<?= e($url . '&edit=' . $item['id']) ?>#editor">Editar</a>
<form class="delete" method="post" action="<?= e($url) ?>"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button name="action" value="delete">Eliminar</button></form></div>
</article>
<?php endforeach ?>
</section>
</div></main></body></html>
