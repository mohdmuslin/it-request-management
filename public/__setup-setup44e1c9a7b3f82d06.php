<?php

/*
|--------------------------------------------------------------------------
| TEMPORARY ONE-SHOT SETUP - DELETE THIS FILE AS SOON AS IT HAS RUN
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS
|
| The deploy cannot finish setup, because:
|
|   1. APP_KEY is empty, and
|   2. DB_DATABASE and DB_USERNAME are still the literal placeholders
|      "<from cPanel>" - no database credentials have been supplied, and
|   3. this host has NO SSH, NO cPanel Terminal, and exec() is disabled, so
|      nothing can be run from outside.
|
| So the work has to be done BY the application, over the web request that is
| already allowed to run.
|
| WHAT IT DOES
|
|   1. Generates APP_KEY if it is missing (never overwrites an existing one).
|   2. Switches the database to SQLite, so a demo does not depend on a cPanel
|      MySQL database and user existing.
|   3. Creates the SQLite file.
|   4. Runs the migrations.
|   5. Seeds reference data.
|   6. Creates the first administrator and prints the password ONCE.
|   7. Records progress in the browser, with every error printed.
|
| SQLITE IS A DELIBERATE CHOICE FOR TONIGHT, NOT THE INTENDED PRODUCTION
| DATABASE. docs/deployment.md section 2 describes MySQL. Switching back is a
| four-line edit to .env followed by a fresh migrate; see the note at the end of
| this file. Nothing here prevents that.
|
| SESSION, CACHE AND QUEUE ARE MOVED TO FILE/SYNC because with a brand-new
| database there are no tables yet - and the session driver would try to read a
| session table on the very request that is supposed to create it. That is a
| deadlock, so they are changed BEFORE anything is migrated.
|
| SAFETY
|
|   - Gated by the token below. Without it: 404.
|   - Refuses to run twice once it has completed, unless &force=1.
|   - Prints nothing secret except the generated admin password, once.
|
| DELETE THIS FILE IMMEDIATELY AFTER USE. The repository is public at the
| moment, so the token is readable by anyone.
|--------------------------------------------------------------------------
*/

const SETUP_TOKEN = 'setup44e1c9a7b3f82d06';

if ((string) ($_GET['t'] ?? '') !== SETUP_TOKEN) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found.\n");
}

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

/*
 * EVERYTHING IS BUFFERED, AND THAT IS NOT COSMETIC.
 *
 * The moment a byte is written, PHP sends the response headers - and Laravel then
 * cannot set its own. Booting with output already sent produced
 * "http_response_code(): Cannot set response code - headers already sent" from
 * LoadEnvironmentVariables, and the boot died there.
 *
 * With the buffer open, nothing reaches the client until the very end, so the
 * framework can send headers normally and the whole report still arrives in one piece.
 */
ob_start();

/*
 * The boot is the risky part, so print the fatal rather than dying silently - a silent
 * death here would look exactly like the problem this page exists to diagnose.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();

    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo "\n\nFATAL: ".$e['message']."\nat ".$e['file'].':'.$e['line']."\n";
    }

    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

$root = dirname(__DIR__);
$envPath = $root.'/.env';
$dbPath = $root.'/database/database.sqlite';
$doneMarker = $root.'/storage/app/.setup-complete';

function say(string $s = ''): void
{
    echo $s, "\n";
}

function head(string $s): void
{
    say();
    say('=================================================================');
    say('== '.$s);
    say('=================================================================');
}

say('ONE-SHOT SETUP');
say('app root: '.$root);
say();

if (($_GET['run'] ?? '') !== '1') {
    head('NOTHING HAS BEEN CHANGED YET');

    say('This page will:');
    say('  1. generate APP_KEY');
    say('  2. switch the database to SQLite (no cPanel database needed)');
    say('  3. create the SQLite file');
    say('  4. migrate');
    say('  5. seed reference data');
    say('  6. create the first administrator');
    say();
    say('.env exists      : '.(is_file($envPath) ? 'YES' : 'NO'));
    say('setup completed  : '.(is_file($doneMarker) ? 'YES (already ran)' : 'NO'));
    say();
    say('To run it:');
    say('  ...'.basename(__FILE__).'?t='.SETUP_TOKEN.'&run=1');
    say();
    say('To delete it when finished:');
    say('  ...'.basename(__FILE__).'?t='.SETUP_TOKEN.'&selfdestruct=1');

    exit;
}

if (($_GET['selfdestruct'] ?? '') === '1') {
    @unlink($doneMarker);
    say('Deleting '.basename(__FILE__).' ...');
    say(@unlink(__FILE__) ? 'DELETED.' : 'COULD NOT DELETE - remove it in File Manager.');
    say('Revisit this URL and it MUST return 404.');

    exit;
}

if (is_file($doneMarker) && ($_GET['force'] ?? '') !== '1') {
    head('ALREADY COMPLETED - REFUSING TO RUN AGAIN');
    say('Delete '.$doneMarker.' first, or add &force=1, if you really mean to re-run.');
    say('Re-running would migrate over live data.');

    exit;
}

// ------------------------------------------------------------ 1. APP_KEY

head('1. APP_KEY');

$env = is_file($envPath) ? (string) file_get_contents($envPath) : '';

$hasKey = (bool) preg_match('/^APP_KEY=base64:.+$/m', $env);

if ($hasKey) {
    say('Already set. Leaving it alone - regenerating would invalidate every');
    say('session and make stored encrypted values permanently unreadable.');
} else {
    say('Missing, so generating one.');
    // 32 random bytes, base64 - exactly what `php artisan key:generate` produces.
    $key = 'base64:'.base64_encode(random_bytes(32));
    say('Generated (not printed).');
}

// ------------------------------------------------------- 2/3. environment

head('2. ENVIRONMENT');

/*
 * The whole file is rewritten rather than patched. It is small, it was hand-typed
 * from placeholders, and a half-substituted .env is exactly the fault being fixed.
 */
$newEnv = implode("\n", [
    'APP_NAME="IT Request Management"',
    'APP_ENV=production',
    'APP_KEY='.($hasKey ? trim((string) preg_replace('/^APP_KEY=/m', '', (string) preg_replace('/.*APP_KEY=([^\n]*).*/s', '$1', $env))) : $key),
    'APP_DEBUG=false',
    'APP_URL=https://itrequest.mwstay.com',
    '',
    'APP_LOCALE=en',
    'APP_FALLBACK_LOCALE=en',
    'APP_FAKER_LOCALE=en_US',
    '',
    /*
     * SQLITE FOR THE DEMO.
     *
     * No cPanel MySQL database or user has been created, and none has been
     * supplied, so MySQL cannot work tonight. SQLite needs no server, no
     * credentials and no privileges beyond a writable file.
     *
     * To move to MySQL later: set the four DB_ values, delete
     * database/database.sqlite, and re-run this installer or
     * `php artisan migrate --force` by cron. Nothing else changes.
     */
    'DB_CONNECTION=sqlite',
    /*
     * QUOTED, AND BACKSLASHES TURNED INTO FORWARD SLASHES. Neither is decoration:
     *
     *   - a backslash inside a double-quoted dotenv value is an ESCAPE sequence, so a
     *     Windows path makes the parser abort with "unexpected escape sequence";
     *   - an unquoted value containing a space aborts it with "unexpected whitespace".
     *
     * Either way the environment file is rejected, the application fails to boot, and
     * the message names the file rather than the value. On this server the path is a
     * plain Linux path and neither applies, but a path is data and must not be able to
     * break the boot.
     */
    'DB_DATABASE="'.str_replace('\\', '/', $dbPath).'"',
    '',
    /*
     * FILE, NOT DATABASE. With SESSION_DRIVER=database the framework reads a
     * session row on EVERY request - including the request that is supposed to
     * create that table. That is a deadlock, so these are changed before
     * anything is migrated.
     *
     * `sync` for the queue so a queued notification runs inline: with no worker
     * on this host, a database queue would simply never run.
     */
    'SESSION_DRIVER=file',
    'SESSION_LIFETIME=120',
    'SESSION_ENCRYPT=false',
    'SESSION_PATH=/',
    'SESSION_DOMAIN=null',
    '',
    'BROADCAST_CONNECTION=log',
    'FILESYSTEM_DISK=local',
    'QUEUE_CONNECTION=sync',
    'CACHE_STORE=file',
    '',
    'LOG_CHANNEL=stack',
    'LOG_STACK=single',
    'LOG_DEPRECATIONS_CHANNEL=null',
    'LOG_LEVEL=debug',
    '',
    // The email gate is still closed, exactly as the brief requires.
    'MAIL_MAILER=smtp',
    'ITREQUEST_MAIL_ENABLED=false',
    '',
]);

if (@file_put_contents($envPath, $newEnv) === false) {
    say('COULD NOT WRITE .env - check permissions. STOPPING.');
    exit;
}

say('.env written ('.strlen($newEnv).' bytes).');
say('  DB_CONNECTION  sqlite');
say('  DB_DATABASE    '.$dbPath);
say('  SESSION_DRIVER file       (not database - see the comments in this file)');
say('  CACHE_STORE    file');
say('  QUEUE_CONNECTION sync');

// Remove any cached config, or Laravel would keep using the OLD .env values.
foreach (['bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php', 'bootstrap/cache/events.php'] as $cached) {
    if (is_file($root.'/'.$cached)) {
        @unlink($root.'/'.$cached);
        say('  removed stale '.$cached);
    }
}

head('3. SQLITE FILE');

if (! is_dir(dirname($dbPath))) {
    @mkdir(dirname($dbPath), 0775, true);
}

if (is_file($dbPath)) {
    say('Already exists ('.filesize($dbPath).' bytes).');
} else {
    if (@touch($dbPath)) {
        @chmod($dbPath, 0664);
        say('Created '.$dbPath);
    } else {
        say('COULD NOT CREATE '.$dbPath.' - check that database/ is writable. STOPPING.');
        exit;
    }
}

say('writable: '.(is_writable($dbPath) ? 'YES' : 'NO'));

// ------------------------------------------------------------- the boot

head('4. BOOTING THE APPLICATION');

require $root.'/bootstrap/ensure-storage.php';
require $root.'/vendor/autoload.php';

try {
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    say('Booted. Laravel '.$app->version());
    say('Database : '.config('database.default'));
} catch (Throwable $e) {
    say('BOOT FAILED: '.$e->getMessage());
    say($e->getFile().':'.$e->getLine());
    exit;
}

// --------------------------------------------------------- 5. migrate

head('5. MIGRATIONS');

try {
    $code = $kernel->call('migrate', ['--force' => true]);
    say($kernel->output());
    say('exit code: '.$code);
} catch (Throwable $e) {
    say('MIGRATE FAILED: '.$e->getMessage());
    say($e->getFile().':'.$e->getLine());
    exit;
}

// --------------------------------------------------------- 6. seed

head('6. REFERENCE DATA');

try {
    $code = $kernel->call('db:seed', ['--force' => true]);
    say($kernel->output());
    say('exit code: '.$code);
} catch (Throwable $e) {
    say('SEED FAILED: '.$e->getMessage());
    say($e->getFile().':'.$e->getLine());
    exit;
}

// --------------------------------------------------------- 7. admin

head('7. FIRST ADMINISTRATOR');

$email = (string) ($_GET['admin'] ?? 'admin@mwstay.com');
$password = bin2hex(random_bytes(6)).'-'.bin2hex(random_bytes(3));

try {
    $code = $kernel->call('itrequest:make-user', [
        'email' => $email,
        '--password' => $password,
        // Lowercase, matching App\Enums\UserRole::Administrator - the command validates
        // against those values and rejects anything else.
        '--role' => ['administrator'],
    ]);

    say($kernel->output());
    say('exit code: '.$code);
} catch (Throwable $e) {
    say('MAKE-USER FAILED: '.$e->getMessage());
}

// --------------------------------------------------------- done

head('DONE - SIGN IN WITH THIS ONCE, THEN CHANGE IT');

say('  URL      https://itrequest.mwstay.com/login');
say('  Email    '.$email);
say('  Password '.$password);
say();
say('THIS PASSWORD IS SHOWN ONCE AND IS NOT STORED ANYWHERE ELSE.');
say('Write it down NOW. It is also the only administrator account.');

@file_put_contents($doneMarker, date('c')."\n".$email."\n");

head('DELETE THIS FILE NOW');
say('  ...'.basename(__FILE__).'?t='.SETUP_TOKEN.'&selfdestruct=1');
say();
say('Leaving it in place means anyone with the token in this file can reset the');
say('environment, and the repository is public at the moment.');
