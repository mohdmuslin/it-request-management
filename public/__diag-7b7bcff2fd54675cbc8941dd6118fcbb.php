<?php

/*
|--------------------------------------------------------------------------
| TEMPORARY DIAGNOSTIC - DELETE THIS FILE AS SOON AS YOU HAVE READ IT
|--------------------------------------------------------------------------
|
| The site answers 500 with a ZERO-LENGTH body. That means PHP died before
| Laravel's error handler was registered, so:
|
|   - nothing is written to storage/logs, and
|   - there is no message anywhere to search for.
|
| This host has no shell and no cPanel Terminal, so the only way to see that
| fatal is to let PHP print it from inside a web request. This file does that.
|
| It deliberately does NOT load Laravel. It is plain PHP, so it still runs when
| the application cannot boot - which is the entire point of it.
|
| It walks the SAME includes that public/index.php walks, one at a time, and
| says which step it reached. If one of them kills PHP, the shutdown handler at
| the bottom prints the fatal and the exact file and line.
|
| SECURITY
|   - Gated by the token below. Without it: 404.
|   - No secret is printed. .env values appear MASKED, and only with &env=1.
|   - The log tail appears only with &logs=1.
|   - Self-destructs with &selfdestruct=1, or delete it in File Manager.
|
| DELETE IT IMMEDIATELY AFTER USE. The repository is public at the moment, so
| the token below is readable by anyone, and this file exposes server paths.
|--------------------------------------------------------------------------
*/

const DIAG_TOKEN = '7b7bcff2fd54675cbc8941dd6118fcbb';

if ((string) ($_GET['t'] ?? '') !== DIAG_TOKEN) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

// Show the fatal we are hunting, instead of hiding it behind production ini values.
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);

function dline(string $s = ''): void
{
    echo $s, "\n";
}

function dhead(string $s): void
{
    dline();
    dline('=================================================================');
    dline('== '.$s);
    dline('=================================================================');
}

function dflag(bool $b): string
{
    return $b ? 'YES' : 'NO';
}

function drow(string $label, string $value): void
{
    echo str_pad($label, 32), $value, "\n";
}

/*
 * If this script dies part-way through - likely, because it deliberately performs
 * the requires that are killing the site - print the fatal and where it happened.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();

    if ($e === null) {
        return;
    }

    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if (! in_array($e['type'], $fatal, true)) {
        return;
    }

    dline();
    dline('################################################################');
    dline('# THE FATAL - this is what produces the empty 500              #');
    dline('################################################################');
    dline($e['message']);
    dline('at '.$e['file'].' : '.$e['line']);
});

// Self-destruct, so removal is one request.
if (($_GET['selfdestruct'] ?? '') === '1') {
    dline('Deleting '.__FILE__.' ...');
    dline(@unlink(__FILE__) ? 'DELETED.' : 'COULD NOT DELETE - remove it from File Manager.');
    dline('Now revisit this URL: it MUST return 404. If it still returns this page,');
    dline('the file is still there.');
    exit;
}

// ---------------------------------------------------------------- where am I

dhead('1. WHERE THIS IS RUNNING');

drow('PHP version', PHP_VERSION);
drow('PHP SAPI', PHP_SAPI);
drow('PHP binary', PHP_BINARY);
drow('OS', PHP_OS);
drow('this file', __FILE__);
drow('this directory', __DIR__);
drow('app root (..)', $root);
drow('DOCUMENT_ROOT', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '(unset)'));
drow('SCRIPT_FILENAME', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '(unset)'));
drow('current user', (string) (get_current_user() ?: '(unknown)'));
drow('APP_ENV (server)', (string) ($_SERVER['APP_ENV'] ?? '(unset)'));

// ------------------------------------------------------------ php settings

dhead('2. PHP SETTINGS THAT DECIDE WHETHER A FILE IS VISIBLE');

drow('display_errors', (string) ini_get('display_errors'));
drow('log_errors', (string) ini_get('log_errors'));
drow('error_log', (string) ini_get('error_log'));
drow('memory_limit', (string) ini_get('memory_limit'));
drow('max_execution_time', (string) ini_get('max_execution_time'));
drow('open_basedir', (string) (ini_get('open_basedir') ?: '(unset - good)'));

/*
 * `open_basedir` is the one that catches people out on cPanel. When it is set, files
 * OUTSIDE the listed directories are reported as NON-EXISTENT rather than as forbidden -
 * so `file_exists()` returns false, a `require` fatals, and nothing says why.
 */
$basedir = (string) ini_get('open_basedir');
if ($basedir !== '') {
    dline();
    dline('  NOTE: open_basedir IS SET. Files outside it appear MISSING, not forbidden.');
    dline('        The application root must be inside that list.');
}

// ------------------------------------------------------------- extensions

dhead('3. PHP EXTENSIONS LARAVEL NEEDS');

$needed = ['mbstring', 'intl', 'ctype', 'fileinfo', 'pdo', 'pdo_mysql', 'json', 'openssl', 'tokenizer', 'curl', 'xml', 'zip', 'bcmath', 'gd'];

foreach ($needed as $ext) {
    drow($ext, dflag(extension_loaded($ext)));
}

// --------------------------------------------------------------- opcache

dhead('4. OPCACHE');

/*
 * An unloaded extension makes `ini_get()` return FALSE, which prints as a blank line and
 * reads like "the setting is switched off". Say plainly that the extension is not there.
 */
if (! extension_loaded('Zend OPcache')) {
    dline('Zend OPcache is NOT LOADED. Nothing is cached, so a deployed file is always');
    dline('re-read on the next request. A stale-code problem cannot come from here.');
} else {
    drow('opcache.enable', (string) ini_get('opcache.enable'));
    drow('opcache.enable_cli', (string) ini_get('opcache.enable_cli'));

    /*
     * `opcache.validate_timestamps=0` is the one that matters here. With it set, PHP NEVER
     * re-reads a changed file, so a deploy can land and the server keeps executing the OLD
     * public/index.php - which looks exactly like "the deploy did nothing".
     */
    $validate = ini_get('opcache.validate_timestamps');
    drow('opcache.validate_timestamps', $validate === false ? '(not set)' : (string) $validate);
    drow('opcache.revalidate_freq', (string) ini_get('opcache.revalidate_freq'));
    drow('opcache.enable_file_override', (string) ini_get('opcache.enable_file_override'));

    if ($validate !== false && ! $validate) {
        dline();
        dline('  *** validate_timestamps IS OFF. PHP WILL NOT NOTICE A DEPLOYED FILE CHANGE. ***');
        dline('  This alone can make a green deploy look like it changed nothing. Ask the host');
        dline('  to turn it on, or restart PHP.');
    }

    if (function_exists('opcache_get_status')) {
        $s = @opcache_get_status(false);
        drow('opcache running', is_array($s) ? 'YES' : 'NO');

        if (is_array($s) && isset($s['opcache_statistics']['num_cached_scripts'])) {
            drow('cached scripts', (string) $s['opcache_statistics']['num_cached_scripts']);
        }
    }
}

// ------------------------------------------------------------ path table

dhead('5. FILES AND DIRECTORIES ON THE SERVER');

$paths = [
    'artisan',
    'composer.json',
    'public/index.php',
    'public/.htaccess',
    'public/build/manifest.json',
    '.env',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'bootstrap/ensure-storage.php',
    'bootstrap/cache',
    'config/app.php',
    'vendor/autoload.php',
    'vendor/composer/platform_check.php',
    'storage',
    'storage/framework',
    'storage/framework/views',
    'storage/framework/sessions',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/logs',
    'storage/app/private/attachments',
];

dline(str_pad('PATH', 44).' EXISTS TYPE    READ  WRITE SIZE   PERMS');
dline(str_repeat('-', 44).' '.str_repeat('-', 38));

foreach ($paths as $p) {
    $full = $root.'/'.$p;
    $exists = file_exists($full);

    $type = '-';
    if ($exists) {
        $type = is_dir($full) ? 'dir' : 'file';
    }

    dline(
        str_pad($p, 44).' '
        .str_pad($exists ? 'YES' : 'NO', 6).' '
        .str_pad($type, 7).' '
        .str_pad($exists ? dflag(is_readable($full)) : '-', 5).'  '
        .str_pad($exists ? dflag(is_writable($full)) : '-', 5).' '
        .str_pad($exists ? (string) filesize($full) : '-', 6).' '
        .($exists ? substr(sprintf('%o', fileperms($full)), -4) : '-')
    );
}

/*
 * The single most important line in this report. public/index.php has to exist and has
 * to be the CURRENT one; its first require is bootstrap/ensure-storage.php.
 */

// ------------------------------------------------- the deployed index.php

dhead('6. public/index.php AS THE SERVER ACTUALLY HAS IT');

$indexPath = $root.'/public/index.php';
$indexSource = is_readable($indexPath) ? (string) file_get_contents($indexPath) : null;

if ($indexSource === null) {
    dline('COULD NOT READ public/index.php');
} else {
    drow('size', (string) strlen($indexSource).' bytes');
    drow('modified', date('Y-m-d H:i:s', (int) filemtime($indexPath)));
    drow('mentions ensure-storage', dflag(str_contains($indexSource, 'ensure-storage.php')));
    drow('mentions vendor/autoload', dflag(str_contains($indexSource, 'vendor/autoload.php')));
    dline();
    dline('--- contents ---');
    dline($indexSource);
    dline('--- end ---');
}

// --------------------------------------------------- the boot, step by step

dhead('7. THE BOOT, ONE REQUIRE AT A TIME');

dline('Each step is the exact require that public/index.php performs, in order.');
dline('When one of them kills PHP, section 8 prints the fatal.');
dline();

$ensure = $root.'/bootstrap/ensure-storage.php';

dline('STEP 1  exists: bootstrap/ensure-storage.php ... '.dflag(file_exists($ensure)));

if (file_exists($ensure)) {
    dline('STEP 1  requiring it ...');
    require $ensure;
    dline('STEP 1  OK - storage tree repaired, no fatal.');
} else {
    dline();
    dline('  *** bootstrap/ensure-storage.php IS MISSING ON THE SERVER. ***');
    dline('  If the deployed public/index.php requires it (section 6), then EVERY');
    dline('  request dies with a fatal at line 1 of index.php -> empty 500, empty log.');
    dline('  That alone explains the symptom.');
    dline();
}

$autoload = $root.'/vendor/autoload.php';

dline('STEP 2  exists: vendor/autoload.php ... '.dflag(file_exists($autoload)));

if (file_exists($autoload)) {
    dline('STEP 2  requiring it ...');
    require $autoload;
    dline('STEP 2  OK - Composer autoloader loaded, no fatal.');
} else {
    dline();
    dline('  *** vendor/autoload.php IS MISSING. Extract vendor.zip in the app root. ***');
    dline();
}

$appPhp = $root.'/bootstrap/app.php';
dline('STEP 3  exists: bootstrap/app.php ... '.dflag(file_exists($appPhp)));
dline('        (NOT executed here - it would boot the application.)');

// ------------------------------------------------- artisan over CLI

dhead('8. CAN artisan BOOT? (run over the command line, from inside PHP)');

dline('The web SAPI and the CLI SAPI can be different PHP builds with different');
dline('extensions and different ini files. This asks the CLI one directly.');
dline();

$disabled = (string) ini_get('disable_functions');
drow('exec available', dflag(function_exists('exec')));
drow('disable_functions', $disabled !== '' ? $disabled : '(none)');

if (function_exists('exec')) {
    @chdir($root);

    $candidates = array_values(array_unique(array_filter([
        PHP_BINDIR.'/php',
        '/usr/local/bin/php',
        '/usr/bin/php',
        '/opt/cpanel/ea-php84/root/usr/bin/php',
        '/opt/cpanel/ea-php83/root/usr/bin/php',
    ])));

    $ran = false;

    foreach ($candidates as $bin) {
        if (! @is_executable($bin)) {
            continue;
        }

        $ran = true;
        dline('--- '.$bin.' artisan --version');

        $out = [];
        $code = 0;
        @exec(escapeshellarg($bin).' artisan --version 2>&1', $out, $code);

        dline('exit code: '.$code);
        dline(implode("\n", array_slice($out, 0, 40)));

        if ($code === 0) {
            dline('=> artisan CAN boot over CLI. cron jobs will work.');
        }

        dline();
    }

    if (! $ran) {
        dline('No php binary found at any of the usual paths.');
    }
} else {
    dline('exec() is not available, so the CLI cannot be tested from here.');
    dline('That is not itself a fault - many hosts disable it for the web SAPI.');
}

// ------------------------------------------- cached config is a trap

dhead('9. bootstrap/cache CONTENTS');

/*
 * A stale bootstrap/cache/config.php is a classic silent breakage: Laravel loads it
 * INSTEAD of reading .env, so it can point at paths and a database from whatever machine
 * built it. `config:clear` is the fix. Only names and sizes are printed - the file can
 * contain credentials.
 */
$cacheDir = $root.'/bootstrap/cache';

if (! is_dir($cacheDir)) {
    dline('bootstrap/cache DOES NOT EXIST. artisan cannot boot without it.');
} else {
    $entries = @scandir($cacheDir) ?: [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $cacheDir.'/'.$entry;

        dline(
            str_pad($entry, 34)
            .(is_dir($full) ? 'dir' : filesize($full).' bytes')
            .'  modified '.date('Y-m-d H:i', (int) filemtime($full))
        );
    }

    if (file_exists($cacheDir.'/config.php')) {
        dline();
        dline('  *** config.php IS CACHED. Laravel is IGNORING .env and using this. ***');
        dline('  Run: php artisan config:clear   -   or delete bootstrap/cache/config.php');
    }
}

// ---------------------------------------------------------------- .env

if (($_GET['env'] ?? '') === '1') {
    dhead('10. .env (VALUES MASKED)');

    $envPath = $root.'/.env';

    if (! file_exists($envPath)) {
        dline('.env DOES NOT EXIST. It is excluded from the deploy, so it must be');
        dline('created by hand - see docs/deployment.md section 3.2.');
    } elseif (! is_readable($envPath)) {
        dline('.env exists but is NOT READABLE by the web server user.');
    } else {
        drow('size', (string) filesize($envPath).' bytes');
        drow('readable', 'YES');
        dline();

        foreach (explode("\n", (string) file_get_contents($envPath)) as $lineNo => $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '') {
                continue;
            }

            /*
             * A commented-out line is NOT a setting, so it must not be reported as an empty
             * one. `.env.example` ships `# DB_PASSWORD=` commented out, and flagging that as
             * "(EMPTY - THIS IS A PROBLEM)" is a false alarm that sends you looking for a fault
             * that is not there.
             */
            $isComment = str_starts_with(ltrim($line), '#');

            if (! str_contains($line, '=')) {
                dline(str_pad((string) ($lineNo + 1), 4).$line);

                continue;
            }

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");

            if ($isComment) {
                dline(str_pad((string) ($lineNo + 1), 4).$line);

                continue;
            }

            $secret = (bool) preg_match('/PASS|KEY|SECRET|TOKEN/i', $k);

            if ($secret) {
                $shown = $v === '' ? '(EMPTY - THIS IS A PROBLEM)' : '(set, '.strlen($v).' chars, hidden)';
            } else {
                $shown = $v === '' ? '(empty)' : $v;
            }

            drow((string) ($lineNo + 1).' '.$k, $shown);
        }
    }
} else {
    dhead('10. .env');
    dline('Not printed. Add &env=1 to the URL to see the keys with values masked.');
}

// ------------------------------------------------------------ the log

if (($_GET['logs'] ?? '') === '1') {
    dhead('11. TAIL OF storage/logs/laravel.log');

    $log = $root.'/storage/logs/laravel.log';

    if (! file_exists($log)) {
        dline('No laravel.log. On this host that usually means PHP died before Laravel');
        dline('started - which is exactly what an empty 500 means.');
    } else {
        drow('size', (string) filesize($log).' bytes');
        drow('modified', date('Y-m-d H:i:s', (int) filemtime($log)));
        dline();

        $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $tail = array_slice($lines, -60);

        foreach ($tail as $l) {
            dline(substr($l, 0, 400));
        }
    }
} else {
    dhead('11. LOG');
    dline('Not printed. Add &logs=1 to the URL to see the last 60 lines.');
}

// --------------------------------------------------------------- finish

dhead('END OF REPORT');
dline('If no fatal appeared above, the boot path is clean and the fault is INSIDE');
dline('the application - then &logs=1 and &env=1 are the next things to read.');
dline();
dline('DELETE THIS FILE when you are done:');
dline('  ...'.basename(__FILE__).'?t='.DIAG_TOKEN.'&selfdestruct=1');
