<?php
// sa.php
// Single-file uploader + Telegram webhook receiver.
// CONFIGURATION - change these:
$BOT_TOKEN = '8415548330:AAGRVkSEAwcsAdQc3f2n_yjOfOixbtBfnIs';     // Telegram bot token, e.g. 123456:ABC-DEF...
$WEBHOOK_SECRET = 'my_super_secret_key_98472'; // secret query string to protect webhook, e.g. "s3cr3t_upload_key"
// Where to save uploaded files (ensure web server has write permission)
$UPLOAD_DIR = __DIR__ . '/uploads';

// Create upload dir if missing
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// Helper: respond JSON for webhook, or render page for normal GET
function respond_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**************/
/* TELEGRAM  */
/**************/
$isWebhookCall = ($_SERVER['REQUEST_METHOD'] === 'POST') && isset($_GET['token']) && $_GET['token'] === $GLOBALS['WEBHOOK_SECRET'];

if ($isWebhookCall) {
    // Read incoming update
    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);
    if (!$update) {
        respond_json(['ok' => false, 'error' => 'invalid json']);
    }

    // Basic handling: message with document, or message with text
    if (isset($update['message'])) {
        $msg = $update['message'];
        $chat_id = $msg['chat']['id'] ?? null;

        // If a document was sent (common when user uploads file)
        if (isset($msg['document'])) {
            $doc = $msg['document'];
            $file_id = $doc['file_id'];
            $file_name = $doc['file_name'] ?? ($file_id . '.txt');
            $saved = downloadTelegramFile($file_id, $file_name);
            if ($saved) {
                sendTelegramMessage($chat_id, "Uploaded and saved as: $saved");
                respond_json(['ok' => true, 'saved' => $saved]);
            } else {
                sendTelegramMessage($chat_id, "Failed to download file.");
                respond_json(['ok' => false, 'error' => 'download_failed']);
            }
        }
        // If user just sent plain text (we'll save it as a .txt file)
        elseif (isset($msg['text'])) {
            $text = $msg['text'];
            $timestamp = time();
            $file_name = "telegram_text_{$chat_id}_{$timestamp}.txt";
            $filepath = $GLOBALS['UPLOAD_DIR'] . '/' . sanitizeFilename($file_name);
            if (file_put_contents($filepath, $text) !== false) {
                sendTelegramMessage($chat_id, "Saved text message as: $file_name");
                respond_json(['ok' => true, 'saved' => $file_name]);
            } else {
                sendTelegramMessage($chat_id, "Failed to save text message.");
                respond_json(['ok' => false, 'error' => 'save_failed']);
            }
        } else {
            // Unsupported content
            sendTelegramMessage($chat_id, "I received something I can't save (only files or text).");
            respond_json(['ok' => false, 'error' => 'unsupported_content']);
        }
    }

    respond_json(['ok' => false, 'error' => 'no message found']);
    // ---- helper functions for Telegram download/send ----
    function downloadTelegramFile($file_id, $file_name) {
        $token = $GLOBALS['BOT_TOKEN'];
        // 1) getFile to obtain file_path
        $url = "https://api.telegram.org/bot{$token}/getFile?file_id=" . urlencode($file_id);
        $resp = file_get_contents($url);
        if (!$resp) return false;
        $j = json_decode($resp, true);
        if (!$j || !isset($j['ok']) || !$j['ok']) return false;
        $file_path = $j['result']['file_path'] ?? null;
        if (!$file_path) return false;

        // 2) download via https://api.telegram.org/file/bot<token>/<file_path>
        $file_url = "https://api.telegram.org/file/bot{$token}/" . $file_path;
        $data = @file_get_contents($file_url);
        if ($data === false) return false;

        // sanitize filename and ensure unique
        $filename = sanitizeFilename(basename($file_name));
        $target = $GLOBALS['UPLOAD_DIR'] . '/' . uniqueFilename($GLOBALS['UPLOAD_DIR'], $filename);
        if (file_put_contents($target, $data) === false) return false;

        return basename($target);
    }

    function sendTelegramMessage($chat_id, $text) {
        if (!$chat_id) return false;
        $token = $GLOBALS['BOT_TOKEN'];
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $post = http_build_query([
            'chat_id' => $chat_id,
            'text' => $text
        ]);
        $opts = ["http" => ["method" => "POST", "header" => "Content-type: application/x-www-form-urlencoded\r\n", "content" => $post]];
        @file_get_contents($url, false, stream_context_create($opts));
        return true;
    }
}

/*********************/
/* NORMAL PAGE (GET) */
/*********************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isWebhookCall) {
    // Handle manual browser upload (multipart/form-data)
    if (!empty($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['upload_file']['tmp_name'];
        $name = basename($_FILES['upload_file']['name']);
        $name = sanitizeFilename($name);
        $dest = $UPLOAD_DIR . '/' . uniqueFilename($UPLOAD_DIR, $name);
        if (move_uploaded_file($tmp, $dest)) {
            header('Location: ?ok=1&f=' . urlencode(basename($dest)));
            exit;
        } else {
            $error = "Failed to move uploaded file.";
        }
    } else {
        $error = "No file uploaded or upload error.";
    }
}

// Render HTML page
$files = array_values(array_filter(scandir($UPLOAD_DIR), function($f){
    return !in_array($f, ['.','..']) && is_file(__DIR__ . '/uploads/' . $f);
}));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>sa.php — Upload / Telegram webhook</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:20px;background:#f6f7fb;color:#111}
.container{max-width:880px;margin:0 auto;background:white;padding:18px;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,0.06)}
h1{margin-top:0}
#drop{border:2px dashed #cbd5e1;padding:30px;text-align:center;border-radius:8px;margin-bottom:12px;background:#fbfdff}
input[type="file"]{display:none}
.btn{display:inline-block;padding:8px 14px;border-radius:6px;background:#2563eb;color:#fff;text-decoration:none}
.file-list{margin-top:16px}
.file-item{padding:8px 10px;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between}
.small{font-size:0.9rem;color:#555}
.notice{padding:8px;background:#f0fdf4;color:#065f46;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:12px}
.error{padding:8px;background:#fff1f2;color:#9f1239;border:1px solid #fecaca;border-radius:6px;margin-bottom:12px}
.code{background:#0f172a;color:#fff;padding:6px 8px;border-radius:6px;font-family:monospace}
</style>
</head>
<body>
<div class="container">
  <h1>sa.php — Upload files & Telegram webhook receiver</h1>

  <?php if (!empty($_GET['ok']) && !empty($_GET['f'])): ?>
    <div class="notice">Uploaded: <strong><?php echo htmlspecialchars($_GET['f']); ?></strong></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div id="drop">
    <p>Drag & drop a text file here, or</p>
    <label class="btn" for="file">Choose file</label>
    <form id="uploadForm" method="post" enctype="multipart/form-data" style="display:inline-block;margin-left:12px">
      <input id="file" name="upload_file" type="file" accept=".txt,text/plain" onchange="document.getElementById('uploadForm').submit()">
    </form>
    <p class="small">Also configure your Telegram bot webhook to point to: <span class="code"><?php echo htmlspecialchars(getWebhookUrlHint()); ?></span></p>
  </div>

  <h3>Uploaded files</h3>
  <div class="file-list">
    <?php if (empty($files)): ?>
      <div class="small">No files yet.</div>
    <?php else: foreach ($files as $f): ?>
      <div class="file-item">
        <div>
          <strong><?php echo htmlspecialchars($f); ?></strong>
          <div class="small"><?php echo formatBytes(filesize($UPLOAD_DIR . '/' . $f)); ?> — <?php echo date('Y-m-d H:i:s', filemtime($UPLOAD_DIR . '/' . $f)); ?></div>
        </div>
        <div><a class="btn" href="uploads/<?php echo rawurlencode($f); ?>" download>Download</a></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <h3>Setup & Quick notes</h3>
  <ol>
    <li>Create a Telegram bot with <em>@BotFather</em>. Get the bot token and paste into <code>$BOT_TOKEN</code>.</li>
    <li>Set a webhook so Telegram forwards messages/files to this script. Example (replace placeholders):<br>
      <pre class="code">curl -F "url=<?php echo htmlspecialchars(getWebhookUrlHint()); ?>" https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook</pre>
      The webhook URL contains a secret token query param to prevent random posts to the endpoint.
    </li>
    <li>Send a file to your bot (as a "Document") from Telegram — the file will be auto-downloaded to <code>uploads/</code>.</li>
    <li>Permissions: ensure your webserver/PHP can write to <code>uploads/</code>.</li>
  </ol>

  <h4>Security tips</h4>
  <ul>
    <li>Keep <code>$WEBHOOK_SECRET</code> secret and long. Do not expose it in public repos.</li>
    <li>If HTTPS is not enabled on your domain, do not expose webhook publicly. Telegram requires HTTPS for webhooks.</li>
  </ul>
</div>

<script>
(function(){
  var drop = document.getElementById('drop');
  var fileInput = document.getElementById('file');

  drop.addEventListener('dragover', function(e){
    e.preventDefault();
    drop.style.background = '#eef6ff';
  });
  drop.addEventListener('dragleave', function(e){
    drop.style.background = '';
  });
  drop.addEventListener('drop', function(e){
    e.preventDefault();
    drop.style.background = '';
    var f = e.dataTransfer.files[0];
    if (!f) return;
    var form = new FormData();
    form.append('upload_file', f);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.onload = function(){
      location.reload();
    };
    xhr.send(form);
  });
})();
</script>
</body>
</html>

<?php
/************* PHP helper functions *************/
function sanitizeFilename($name) {
    // remove dangerous chars
    $name = preg_replace('/[^\w\.\-\_\(\) ]+/', '', $name);
    $name = trim($name);
    if ($name === '') $name = 'file';
    return $name;
}
function uniqueFilename($dir, $name) {
    $path = $dir . '/' . $name;
    if (!file_exists($path)) return $name;
    $i = 1;
    $ext = '';
    $base = $name;
    if (strpos($name, '.') !== false) {
        $parts = explode('.', $name);
        $ext = array_pop($parts);
        $base = implode('.', $parts);
        $ext = '.' . $ext;
    }
    while (file_exists($dir . '/' . ($base . "({$i})" . $ext))) {
        $i++;
    }
    return $base . "({$i})" . $ext;
}
function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) {
        $bytes /= 1024; $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}
function getWebhookUrlHint() {
    // build URL to this file with token param
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT']==443 ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'];
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    // ensure we keep the same script path
    $script = $_SERVER['SCRIPT_NAME'];
    $u = "{$proto}://{$host}{$script}?token=" . urlencode($GLOBALS['WEBHOOK_SECRET']);
    return $u;
}
?>
