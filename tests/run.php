<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Eam\Captcha\BuiltinCaptcha;
use Eam\Captcha\MathCaptcha;
use Eam\Config;
use Eam\Http\Request;
use Eam\Http\Response;
use Eam\Http\Router;
use Eam\Http\Session;
use Eam\I18n\Translator;
use Eam\Storage\FileStorage;
use Eam\Storage\SqliteStorage;
use Eam\Support\Logger;
use Eam\Support\PasswordStrength;
use Eam\Validation\DisposableEmailChecker;
use Eam\Validation\Validator;

$tests = 0;
$failures = 0;
$current = '';

function test(string $name, callable $fn): void
{
    global $tests, $failures, $current;
    $tests++;
    $current = $name;
    try {
        $fn();
        echo "[ok] {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

function assertTrue(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertNull(mixed $actual, string $message = 'expected null'): void
{
    if ($actual !== null) {
        throw new RuntimeException($message . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : 'missing substring: ' . $needle);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$base = __DIR__ . '/tmp';
if (!is_dir($base)) {
    mkdir($base, 0770, true);
}
$config = Config::load(__DIR__ . '/config.test.php');
$logger = new Logger($base . '/unit.log', 'debug', false);
$session = new Session($config);
$session->start();
$_SESSION = [];

test('password strength', function (): void {
    assertTrue(PasswordStrength::score('Strong-Test-Pass-2026!') >= 3, 'strong password should score >= 3');
    assertSame(0, PasswordStrength::score('password'), 'common password should score 0');
    assertSame(0, PasswordStrength::score('aaaaaaaa'), 'repeated password should score 0');
    assertSame(0, PasswordStrength::score(''), 'empty password should score 0');
    assertSame(0, PasswordStrength::score('12345678') <= 1 ? 0 : PasswordStrength::score('12345678'), 'digits-only should be weak');
});

test('validator username and jid', function () use ($config): void {
    assertSame('goodname', Validator::normalizeUsername('  GoodName  '));
    assertNull(Validator::validateUsername('goodname', $config));
    assertSame('username_invalid', Validator::validateUsername('ab', $config));
    assertSame('username_invalid', Validator::validateUsername('bad name', $config));
    assertSame('username_forbidden', Validator::validateUsername('admin', $config));
    assertSame(['user' => 'user', 'host' => 'test.localhost'], Validator::parseJid('User@Test.Localhost'));
    assertNull(Validator::parseJid('bad'));
    assertNull(Validator::parseJid('a@b@c'));
    assertNull(Validator::parseJid('user@host/resource'));
});

test('validator email and password', function () use ($config, $base, $logger): void {
    $checker = new DisposableEmailChecker($config, $logger, $base . '/cache');
    assertNull(Validator::validateEmail('test@example.com', $config, $checker));
    assertSame('email_invalid', Validator::validateEmail('bad', $config, $checker));
    assertNull(Validator::validatePassword('Strong-Test-Pass-2026!', $config));
    assertSame('password_weak', Validator::validatePassword('password', $config));
    assertSame('required', Validator::validatePassword('', $config));
});

test('translator and fallback', function (): void {
    $translator = new Translator(__DIR__ . '/../lang', 'ru', ['en', 'ru', 'uk', 'pl']);
    assertSame('Главная', $translator->get('nav.home'));
    assertSame('missing.key', $translator->get('missing.key'));
    $text = $translator->get('confirm.change_email.text', [':jid' => 'a@b', ':email' => 'c@d']);
    assertContains('a@b', $text);
    assertContains('c@d', $text);
    $en = new Translator(__DIR__ . '/../lang', 'en', ['en', 'ru']);
    assertSame('Registration', $en->get('nav.registration'));
});

test('builtin and math captcha', function () use ($config, $session): void {
    $translator = new Translator(__DIR__ . '/../lang', 'en', ['en', 'ru']);

    $captcha = new BuiltinCaptcha($config, $session, $translator);
    $html = $captcha->render();
    assertContains('data:image/png;base64,', $html);
    assertTrue(!$captcha->verify(['captcha' => 'WRONG'], '127.0.0.1'), 'wrong builtin captcha must fail');
    $_SESSION['captcha_builtin'] = ['hash' => hash('sha256', 'ABCDE'), 'expires' => time() + 60];
    assertTrue($captcha->verify(['captcha' => 'abcde'], '127.0.0.1'), 'valid builtin captcha must pass');
    $_SESSION['captcha_builtin'] = ['hash' => hash('sha256', 'ABCDE'), 'expires' => time() - 1];
    assertTrue(!$captcha->verify(['captcha' => 'abcde'], '127.0.0.1'), 'expired builtin captcha must fail');

    $math = new MathCaptcha($session, $translator);
    assertContains('name="captcha"', $math->render());
    $_SESSION['captcha_math'] = ['hash' => hash('sha256', '7'), 'expires' => time() + 60];
    assertTrue($math->verify(['captcha' => '7'], '127.0.0.1'), 'valid math captcha must pass');
    $session->destroy();
});

test('router dispatch and 404', function (): void {
    $router = new Router();
    $router->get('/x', fn (): Response => Response::text('ok'));
    $request = new Request('GET', '/x', [], [], [], [], [], []);
    $response = $router->dispatch($request);
    assertSame(200, $response->status());
    assertSame('ok', $response->body());
    $router->setNotFound(fn (): Response => Response::text('missing', 404));
    $missing = $router->dispatch(new Request('GET', '/nope', [], [], [], [], [], []));
    assertSame(404, $missing->status());
    assertSame('missing', $missing->body());
});

test('sqlite token storage', function () use ($config, $logger, $base): void {
    $path = $base . '/unit.sqlite';
    @unlink($path);
    $storage = new SqliteStorage($path, (string) $config->get('app.secret'), $logger);
    $token = $storage->createToken('unit', ['a' => 1], 60, '127.0.0.1');
    assertTrue($token !== '');
    assertSame(['a' => 1], $storage->peekToken('unit', $token));
    assertSame(['a' => 1], $storage->consumeToken('unit', $token));
    assertNull($storage->peekToken('unit', $token));
    assertNull($storage->consumeToken('unit', $token));
    $expired = $storage->createToken('unit', ['b' => 2], -1);
    assertNull($storage->peekToken('unit', $expired));
    assertTrue(!$storage->isRateLimited('k', 2));
    $storage->hitRate('k', 60);
    $storage->hitRate('k', 60);
    assertTrue($storage->isRateLimited('k', 2));
    $storage->clearRate('k');
    assertTrue(!$storage->isRateLimited('k', 2));
});

test('file token storage', function () use ($config, $logger, $base): void {
    $dir = $base . '/unit-files';
    rrmdir($dir);
    $storage = new FileStorage($dir, (string) $config->get('app.secret'), $logger);
    $token = $storage->createToken('unit', ['a' => 1], 60, '127.0.0.1');
    assertSame(['a' => 1], $storage->peekToken('unit', $token));
    assertSame(['a' => 1], $storage->consumeToken('unit', $token));
    assertNull($storage->peekToken('unit', $token));
    assertNull($storage->consumeToken('unit', $token));
    $expired = $storage->createToken('unit', ['b' => 2], -1);
    assertNull($storage->peekToken('unit', $expired));
    assertTrue(!$storage->isRateLimited('k', 1));
    $storage->hitRate('k', 60);
    assertTrue($storage->isRateLimited('k', 1));
    $storage->clearRate('k');
    assertTrue(!$storage->isRateLimited('k', 1));
});

echo "\n{$tests} tests, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
