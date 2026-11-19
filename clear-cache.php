#!/usr/bin/env php
<?php
/**
 * Clear the Sanatana / Vemana answer cache.
 *
 * The sites cache every answer forever (table l9huvwhjjmwprag_cache_*),
 * so a wrong answer stays wrong until its row is deleted — e.g. the
 * one-word "salt" answer cached before the vector-index fix. This script
 * deletes cache rows without hand-written SQL.
 *
 * Usage (on the box, from any folder):
 *   php clear-cache.php "salt"                   delete one question (Vemana)
 *   php clear-cache.php --site=sanatana "salt"   same for Sanatana
 *   php clear-cache.php --all                    wipe the whole Vemana cache
 *   php clear-cache.php --site=sanatana --all    wipe the whole Sanatana cache
 *   php clear-cache.php --dry-run "salt"         show what would be deleted
 *
 * CLI only — it refuses to run under a web server, so nobody can wipe the
 * cache by opening a URL. DB credentials are read from wp-config.php,
 * exactly like ask.php and the importer. No secrets in this file.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "FAIL: CLI only — run this from the box shell, not a browser.\n");
    exit(1);
}

define('WP_ROOT', '/var/www/vhosts/socialnews.xyz/httpdocs/specpals.com/clickandbuilds/SocialNewsXYZ');

// ---- args ----
$site = 'vemana';
$all = false;
$dry_run = false;
$question = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--all') {
        $all = true;
    } elseif ($a === '--dry-run') {
        $dry_run = true;
    } elseif (strpos($a, '--site=') === 0) {
        $site = substr($a, 7);
    } elseif (strpos($a, '--') === 0) {
        fwrite(STDERR, "FAIL: unknown option '{$a}'.\n");
        exit(1);
    } elseif ($question === null) {
        $question = $a;
    } else {
        fwrite(STDERR, "FAIL: only one question at a time.\n");
        exit(1);
    }
}
if ($site !== 'vemana' && $site !== 'sanatana') {
    fwrite(STDERR, "FAIL: --site must be vemana or sanatana.\n");
    exit(1);
}
if (!$all && $question === null) {
    fwrite(STDERR, "Usage: php clear-cache.php [--site=vemana|sanatana] [--all] [--dry-run] [\"question\"]\n");
    exit(1);
}
$table = 'l9huvwhjjmwprag_cache_' . $site;

// Same normalization as ask.php: the cache key is sha256 of this.
function normalize_question($q)
{
    $q = mb_strtolower(trim((string) $q), 'UTF-8');
    $q = preg_replace('/\s+/u', ' ', $q);
    $q = rtrim($q, " \t\n\r\0\x0B?!.");
    return $q;
}

if ($all) {
    $sql = "DELETE FROM {$table}";
    $what = "all rows in {$table}";
} else {
    $qhash = hash('sha256', normalize_question($question));
    $sql = "DELETE FROM {$table} WHERE qhash = '{$qhash}'";
    $what = "question \"{$question}\" (hash {$qhash}) in {$table}";
}

if ($dry_run) {
    echo "INFO: dry run — would execute: {$sql}\n";
    exit(0);
}

// ---- wp-config.php: DB credentials (same helpers as ask.php) ----
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

$wp_config = WP_ROOT . '/wp-config.php';
$db_name = wp_config_value('DB_NAME', $wp_config);
$db_user = wp_config_value('DB_USER', $wp_config);
$db_pass = wp_config_value('DB_PASSWORD', $wp_config);
$db_host = wp_config_value('DB_HOST', $wp_config);
if (!$db_name || !$db_user || $db_pass === null) {
    fwrite(STDERR, "FAIL: could not read DB credentials from wp-config.php.\n");
    exit(1);
}
if (!$db_host) {
    $db_host = '127.0.0.1';
}

// Try PDO first, then mysqli — the shell default php has neither driver
// guaranteed, the Plesk php has at least one. $done stays false if both
// are unavailable, and the error below says which php to use instead.
$done = false;
$n = 0;
$err = null;
try {
    $pdo = new PDO(
        'mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4',
        $db_user,
        $db_pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $r = $pdo->exec($sql);
    if ($r !== false) {
        $done = true;
        $n = $r;
    }
} catch (PDOException $e) {
    $err = $e->getMessage();
} catch (Error $e) {
    // PDO class itself missing in this PHP — fall through to mysqli.
    $err = $e->getMessage();
}

// Fallback: the shell default php has no pdo_mysql, but usually has
// mysqli (and wp-cli works with it). Either driver is fine here —
// the query is a plain DELETE with no parameters.
if (!$done && extension_loaded('mysqli')) {
    $mysqli = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli) {
        if (mysqli_query($mysqli, $sql)) {
            $done = true;
            $n = mysqli_affected_rows($mysqli);
        } else {
            $err = mysqli_error($mysqli);
        }
        mysqli_close($mysqli);
    } else {
        $err = mysqli_connect_error();
    }
}

if (!$done) {
    $hint = 'no MySQL driver loaded in this PHP (need pdo_mysql or mysqli). '
        . 'Run it with the Plesk PHP instead: '
        . 'PHP=$(ls /opt/plesk/php/*/bin/php | sort -Vr | head -1); $PHP clear-cache.php ...';
    fwrite(STDERR, "FAIL: database error — " . ($err ? $err : $hint) . "\n");
    exit(1);
}

if ($n === 0) {
    echo "INFO: no rows matched — nothing cached for {$what}.\n";
} else {
    echo "INFO: deleted {$n} row(s) for {$what}.\n";
}
