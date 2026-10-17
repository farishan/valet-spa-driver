<?php
/**
 * Test suite for SpaValetDriver.
 *
 * Self-contained: it builds its own fixture sites in a temp directory, so it
 * never touches -- or reveals -- the projects linked on the machine running it.
 * The only external requirement is a Valet Linux installation for the base
 * driver classes.
 *
 * Usage:
 *   php tests/run.php
 *   VALET_PATH=/path/to/valet-linux php tests/run.php
 *
 * Exit code is 0 when every assertion passes.
 */

$repoRoot = dirname(__DIR__);

/**
 * Locate Valet Linux's bundled driver classes.
 *
 * Order: explicit VALET_PATH, then the `valet` binary (resolved through its
 * symlink, since the real install usually lives in a composer vendor dir),
 * then the usual install locations.
 */
function locate_valet_drivers()
{
    $candidates = [];

    if (getenv('VALET_PATH')) {
        $candidates[] = rtrim(getenv('VALET_PATH'), '/') . '/cli/drivers';
    }

    $binary = trim((string) @shell_exec('command -v valet 2>/dev/null'));
    if ($binary !== '') {
        // /usr/local/bin/valet -> .../vendor/cpriego/valet-linux/valet
        $candidates[] = dirname(realpath($binary)) . '/cli/drivers';
    }

    $candidates[] = getenv('HOME') . '/.config/composer/vendor/cpriego/valet-linux/cli/drivers';
    $candidates[] = '/usr/local/valet/cli/drivers';

    foreach ($candidates as $path) {
        if (is_file($path . '/ValetDriver.php') && is_file($path . '/require.php')) {
            return realpath($path);
        }
    }

    fwrite(STDERR, "Could not locate Valet Linux's driver classes.\n");
    fwrite(STDERR, "Install Valet Linux, or set VALET_PATH to a checkout:\n");
    fwrite(STDERR, "  VALET_PATH=/path/to/valet-linux php tests/run.php\n");

    exit(2);
}

$valetDrivers = locate_valet_drivers();

// A hermetic VALET_HOME containing only our driver, so assign() cannot pick up
// anything installed on the machine running the tests.
$valetHome = sys_get_temp_dir() . '/valet-spa-driver-test-' . getmypid();
@mkdir($valetHome . '/Drivers', 0777, true);

define('VALET_HOME_PATH', $valetHome);
define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45/');

require $valetDrivers . '/require.php';

$driverSource = realpath($repoRoot . '/src/SpaValetDriver.php');

// Symlink rather than copy: assign() require_once's this path, and PHP dedupes
// included files by realpath, so the class is still declared exactly once.
symlink($driverSource, $valetHome . '/Drivers/SpaValetDriver.php');
require_once $driverSource;

$driver = new SpaValetDriver();

/* ------------------------------------------------------------------ */
/* Fixtures                                                            */
/* ------------------------------------------------------------------ */

$tmp = sys_get_temp_dir() . '/valet-spa-fixtures-' . getmypid();

/** Write a file, creating parent directories. */
function fixture($path, $contents = '')
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
}

// A compiled SPA with a PWA manifest -- the canonical case.
$spa = $tmp . '/spa';
fixture($spa . '/index.html', '<!doctype html><title>SPA</title>');
fixture($spa . '/manifest.webmanifest', '{"name":"SPA"}');
fixture($spa . '/sw.js', '// service worker');
fixture($spa . '/assets/app-abc123.js', 'console.log(1)');
fixture($spa . '/assets/app-abc123.css', 'body{}');
fixture($spa . '/dashboard/index.html', '<!doctype html><title>Dashboard</title>');

// A plain SPA build with no PWA manifest at all.
$plain = $tmp . '/plain';
fixture($plain . '/index.html', '<!doctype html><title>Plain</title>');
fixture($plain . '/main.js', 'console.log(1)');

// Laravel/Symfony layout.
$laravel = $tmp . '/laravel';
fixture($laravel . '/public/index.php', '<?php');
fixture($laravel . '/artisan', '');

// WordPress / flat PHP layout.
$wordpress = $tmp . '/wordpress';
fixture($wordpress . '/index.php', '<?php');
fixture($wordpress . '/wp-content/index.php', '<?php');

// Nothing at all.
$empty = $tmp . '/empty';

// Sibling of the SPA dir, used to prove traversal cannot escape it.
fixture($tmp . '/secret.txt', 'do not serve me');

/* ------------------------------------------------------------------ */
/* Harness                                                             */
/* ------------------------------------------------------------------ */

$passed = 0;
$failed = 0;

function check($label, $actual, $expected)
{
    global $passed, $failed;
    $ok = $actual === $expected;
    $ok ? $passed++ : $failed++;
    printf(
        "%s  %-46s %s\n",
        $ok ? 'PASS' : 'FAIL',
        $label,
        $ok ? '' : 'got ' . var_export($actual, true) . ', want ' . var_export($expected, true)
    );
}

function ok($label, $condition)
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    printf("%s  %-46s\n", $condition ? 'PASS' : 'FAIL', $label);
}

/**
 * Replicates server.php's dispatch order.
 *
 * The $uri !== '/' guard is the reason this helper exists: a test that calls
 * isStaticFile() directly reports the site root as working while the real site
 * 404s, because server.php never consults isStaticFile() for "/".
 *
 * @return string 'static:<path>', 'front:<path>' or '404'
 */
function dispatch($driver, $sitePath, $siteName, $uri)
{
    $uri = $driver->mutateUri($uri);
    $isPhpFile = pathinfo($uri, PATHINFO_EXTENSION) === 'php';

    if ($uri !== '/' && ! $isPhpFile && $staticFilePath = $driver->isStaticFile($sitePath, $siteName, $uri)) {
        return 'static:' . $staticFilePath;
    }

    $frontControllerPath = $driver->frontControllerPath($sitePath, $siteName, $uri);

    if (! $frontControllerPath) {
        return '404';
    }

    return 'front:' . $frontControllerPath;
}

/* ------------------------------------------------------------------ */
/* Driver selection                                                    */
/* ------------------------------------------------------------------ */

$assigned = ValetDriver::assign($spa, 'spa', '/');
ok('assign() selects SpaValetDriver for a SPA', $assigned instanceof SpaValetDriver);

$assignedPlain = ValetDriver::assign($plain, 'plain', '/');
ok('assign() selects SpaValetDriver without a manifest', $assignedPlain instanceof SpaValetDriver);

ok('assign() leaves a Laravel site to a PHP driver', ! (ValetDriver::assign($laravel, 'laravel', '/') instanceof SpaValetDriver));
ok('assign() leaves a WordPress site to a PHP driver', ! (ValetDriver::assign($wordpress, 'wordpress', '/') instanceof SpaValetDriver));
ok('assign() declines an empty directory', ! (ValetDriver::assign($empty, 'empty', '/') instanceof SpaValetDriver));

/* ------------------------------------------------------------------ */
/* The site root -- server.php skips isStaticFile() for "/"            */
/* ------------------------------------------------------------------ */

check('/ answers via front controller', dispatch($driver, $spa, 'spa', '/'), 'front:' . $spa . '/index.html');
check('front controller sets SCRIPT_FILENAME', $driver->frontControllerPath($spa, 'spa', '/'), $spa . '/index.html');

/* ------------------------------------------------------------------ */
/* Real files                                                          */
/* ------------------------------------------------------------------ */

check('/index.html is served statically', dispatch($driver, $spa, 'spa', '/index.html'), 'static:' . $spa . '/index.html');
check('/manifest.webmanifest is served statically', dispatch($driver, $spa, 'spa', '/manifest.webmanifest'), 'static:' . $spa . '/manifest.webmanifest');
check('/sw.js is served statically', dispatch($driver, $spa, 'spa', '/sw.js'), 'static:' . $spa . '/sw.js');
check('/assets/app-abc123.js is served statically', dispatch($driver, $spa, 'spa', '/assets/app-abc123.js'), 'static:' . $spa . '/assets/app-abc123.js');
check('/assets/app-abc123.css is served statically', dispatch($driver, $spa, 'spa', '/assets/app-abc123.css'), 'static:' . $spa . '/assets/app-abc123.css');

/* ------------------------------------------------------------------ */
/* SPA history fallback                                                */
/* ------------------------------------------------------------------ */

check('/hustles falls back to index.html', dispatch($driver, $spa, 'spa', '/hustles'), 'static:' . $spa . '/index.html');
check('/hustle/abc falls back to index.html', dispatch($driver, $spa, 'spa', '/hustle/abc'), 'static:' . $spa . '/index.html');
check('/hustle/abc/ deep falls back', dispatch($driver, $spa, 'spa', '/hustle/abc/'), 'static:' . $spa . '/index.html');
check('/dashboard resolves its own index.html', dispatch($driver, $spa, 'spa', '/dashboard'), 'static:' . $spa . '/dashboard/index.html');
check('/dashboard/ resolves its own index.html', dispatch($driver, $spa, 'spa', '/dashboard/'), 'static:' . $spa . '/dashboard/index.html');
check('plain SPA route falls back', dispatch($driver, $plain, 'plain', '/about'), 'static:' . $plain . '/index.html');

/* ------------------------------------------------------------------ */
/* Missing assets must 404, not receive HTML                           */
/* ------------------------------------------------------------------ */

check('/missing.js 404s', dispatch($driver, $spa, 'spa', '/missing.js'), '404');
check('/assets/missing.js 404s', dispatch($driver, $spa, 'spa', '/assets/missing.js'), '404');
check('/missing.css 404s', dispatch($driver, $spa, 'spa', '/missing.css'), '404');
check('/missing.png 404s', dispatch($driver, $spa, 'spa', '/missing.png'), '404');

/* ------------------------------------------------------------------ */
/* Path traversal                                                      */
/* ------------------------------------------------------------------ */

check('traversal cannot escape the site dir', $driver->isStaticFile($spa, 'spa', '/../secret.txt'), false);
check('encoded traversal is refused', $driver->isStaticFile($spa, 'spa', '/%2e%2e/secret.txt'), false);
ok('front controller stays inside the site', strpos(
    realpath($driver->frontControllerPath($spa, 'spa', '/')),
    realpath($spa)
) === 0);

/* ------------------------------------------------------------------ */
/* PHP sites are declined outright                                     */
/* ------------------------------------------------------------------ */

check('declines Laravel front controller', $driver->frontControllerPath($laravel, 'laravel', '/'), false);
check('declines WordPress front controller', $driver->frontControllerPath($wordpress, 'wordpress', '/'), false);
check('declines empty front controller', $driver->frontControllerPath($empty, 'empty', '/'), false);
check('Laravel /routes 404s', dispatch($driver, $laravel, 'laravel', '/routes'), '404');
check('WordPress /about 404s', dispatch($driver, $wordpress, 'wordpress', '/about'), '404');

/* ------------------------------------------------------------------ */

echo "\n$passed passed, $failed failed\n";

// Cleanup
foreach ([$tmp, $valetHome] as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

exit($failed === 0 ? 0 : 1);