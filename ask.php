<?php
/**
 * Sanatana Dharma — ask the book.
 *
 * POST {"question": "..."} -> JSON {"answer": "...", "passages": [...]}
 *
 * Pipeline (all on this box, same as the Kaggle notebook):
 *   question -> box gemma service (127.0.0.1:8797, embeddinggemma-2)
 *           -> MariaDB l9huvwhjjmwprag_embeddings_sanatana (VEC_DISTANCE_COSINE)
 *           -> OpenRouter qwen/qwen3.5-9b answers ONLY from the passages.
 *
 * OpenRouter key, first hit wins:
 *   1. environment variable OPENROUTER_API_KEY
 *   2. config.php next to this file: define('OPENROUTER_API_KEY', 'sk-or-...');
 *   3. WordPress option wprag_openrouter_key (RAG Search > Settings), read
 *      from the same database this script already connects to.
 *
 * DB credentials are read from wp-config.php (same file the importer uses).
 * No secrets are stored in this file.
 */

header('Content-Type: application/json; charset=utf-8');

define('WP_ROOT', '/var/www/vhosts/socialnews.xyz/httpdocs/specpals.com/clickandbuilds/SocialNewsXYZ');
define('EMBED_URL', 'http://127.0.0.1:8797/embed');
define('CHAT_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('CHAT_MODEL', 'openrouter/free');
define('TABLE', 'l9huvwhjjmwprag_embeddings_sanatana');
define('RATE_LIMIT_MAX', 20);      // questions per IP per hour
define('RATE_LIMIT_WINDOW', 3600); // seconds

function fail($code, $msg)
{
    http_response_code($code);
    echo json_encode(array('error' => $msg));
    exit;
}

// ---- rate limit (per IP, file-based, fails open if the store is unusable) ----
function rate_limited($ip)
{
    $dir = __DIR__ . '/data';
    $file = $dir . '/ratelimit.json';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    $raw = stream_get_contents($fp);
    $store = $raw ? json_decode($raw, true) : array();
    if (!is_array($store)) {
        $store = array();
    }
    $now = time();
    $key = md5($ip);
    $hits = isset($store[$key]) ? $store[$key] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) {
        return ($now - $t) < RATE_LIMIT_WINDOW;
    }));
    if (count($hits) >= RATE_LIMIT_MAX) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    $hits[] = $now;
    $store[$key] = $hits;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($store));
    flock($fp, LOCK_UN);
    fclose($fp);
    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST only.');
}

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
if (rate_limited($ip)) {
    fail(429, 'Too many questions from this address — please wait a while and try again.');
}

$body = json_decode(file_get_contents('php://input'), true);
$question = isset($body['question']) ? trim((string) $body['question']) : '';
if ($question === '') {
    fail(400, 'Ask a question first.');
}
if (mb_strlen($question) > 500) {
    fail(400, 'Please keep the question under 500 characters.');
}

// ---- wp-config.php: DB credentials + table prefix ----
function wp_config_value($key, $path)
{
    $text = @file_get_contents($path);
    if ($text === false) {
        return null;
    }
    if (preg_match("/define\(\s*'" . preg_quote($key, '/') . "'\s*,\s*'([^']+)'/", $text, $m)) {
        return $m[1];
    }
    return null;
}
function wp_config_prefix($path)
{
    $text = @file_get_contents($path);
    if ($text === false) {
        return 'l9huvwhjjm';
    }
    if (preg_match("/\\\$table_prefix\s*=\s*'([^']+)'/", $text, $m)) {
        return $m[1];
    }
    return 'l9huvwhjjm';
}

$wp_config = WP_ROOT . '/wp-config.php';
$db_name = wp_config_value('DB_NAME', $wp_config);
$db_user = wp_config_value('DB_USER', $wp_config);
$db_pass = wp_config_value('DB_PASSWORD', $wp_config);
$db_host = wp_config_value('DB_HOST', $wp_config);
$prefix = wp_config_prefix($wp_config);
if (!$db_name || !$db_user || $db_pass === null) {
    fail(500, 'The book database is not configured on this server yet.');
}
if (!$db_host) {
    $db_host = '127.0.0.1';
}

// ---- OpenRouter key: env, then config.php, then the WordPress option ----
$openrouter_key = getenv('OPENROUTER_API_KEY');
if (!$openrouter_key && file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
    if (defined('OPENROUTER_API_KEY')) {
        $openrouter_key = OPENROUTER_API_KEY;
    }
}

// ---- tiny HTTP helper (curl) ----
function http_post_json($url, $payload, $headers, $timeout)
{
    if (!function_exists('curl_init')) {
        return array(null, 'PHP curl is not available on this server.');
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($out === false) {
        return array(null, 'Request failed: ' . $err);
    }
    $data = json_decode($out, true);
    if ($code >= 400) {
        $detail = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
        return array(null, $detail);
    }
    return array($data, null);
}

// ---- 1. embed the question (box gemma service applies the query prompt) ----
list($emb, $emb_err) = http_post_json(
    EMBED_URL,
    array('texts' => array($question), 'task' => 'query'),
    array('Content-Type: application/json'),
    30
);
if ($emb_err !== null || !isset($emb['vectors'][0])) {
    fail(502, 'The embedding service did not answer. Please try again in a moment.');
}
$vec = $emb['vectors'][0];
$vec_text = '[' . implode(',', array_map(function ($v) {
    return sprintf('%.6f', (float) $v);
}, $vec)) . ']';

// ---- 2. retrieve the closest passages ----
try {
    $pdo = new PDO(
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
        $db_user,
        $db_pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false)
    );
} catch (PDOException $e) {
    fail(500, 'The book database is unreachable right now.');
}

// WordPress option fallback for the OpenRouter key (same DB connection).
if (!$openrouter_key) {
    try {
        $stmt = $pdo->prepare(
            'SELECT option_value FROM ' . $prefix . 'options WHERE option_name = :name LIMIT 1'
        );
        $stmt->execute(array(':name' => 'wprag_openrouter_key'));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string) $row['option_value']) !== '') {
            $openrouter_key = trim((string) $row['option_value']);
        }
    } catch (PDOException $e) {
        // fall through to the missing-key error below
    }
}
if (!$openrouter_key) {
    fail(500, 'The answer service is not configured yet — the OpenRouter key is missing.');
}

try {
    $stmt = $pdo->prepare(
        'SELECT pdf_page, chunk_text, VEC_DISTANCE_COSINE(embedding, VEC_FromText(:vec)) AS dist '
        . 'FROM ' . TABLE . ' ORDER BY dist ASC LIMIT 5'
    );
    $stmt->execute(array(':vec' => $vec_text));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fail(500, 'The book search failed. Please try again.');
}
if (empty($rows)) {
    fail(500, 'No passages found for that question.');
}

$passages = array();
$context_parts = array();
foreach ($rows as $r) {
    $dist = (float) $r['dist'];
    $passages[] = array(
        'page' => (int) $r['pdf_page'],
        'text' => (string) $r['chunk_text'],
        'score' => round(1.0 - $dist, 3),
    );
    $context_parts[] = '[PDF page ' . (int) $r['pdf_page'] . '] ' . (string) $r['chunk_text'];
}
$context = implode("\n\n", $context_parts);

// ---- 3. answer ONLY from the passages ----
$messages = array(
    array('role' => 'system', 'content' =>
        "You answer questions about the book 'Sanatana Dharma: An Elementary Text-book of Hindu Religion and Ethics' (Central Hindu College, Benares, 1916). "
        . "Answer ONLY from the passages below. If the passages do not contain the answer, say so plainly instead of guessing. "
        . "Give the PDF page number for each point. Keep the answer short."),
    array('role' => 'user', 'content' => "Question: {$question}\n\nPassages:\n{$context}"),
);
list($chat, $chat_err) = http_post_json(
    CHAT_URL,
    array('model' => CHAT_MODEL, 'messages' => $messages, 'temperature' => 0.1),
    array(
        'Authorization: Bearer ' . $openrouter_key,
        'Content-Type: application/json',
        'HTTP-Referer: https://sanatana.socialnews.xyz',
        'X-Title: Sanatana Dharma RAG',
    ),
    120
);
if ($chat_err !== null || !isset($chat['choices'][0]['message']['content'])) {
    fail(502, 'The answer service did not respond. Please try again.');
}

echo json_encode(array(
    'answer' => $chat['choices'][0]['message']['content'],
    'passages' => $passages,
));
