<?php
declare(strict_types=1);

/**
 * Minimal fake ejabberd mod_http_api for integration tests.
 * Run: php -S 127.0.0.1:7283 tests/fake-ejabberd.php
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strtolower((string) $name) === 'authorization') {
            $auth = (string) $value;
            break;
        }
    }
}
$expected = 'Basic ' . base64_encode('eam@test.localhost:test');
if (!hash_equals($expected, $auth)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode('Unauthorized');
    exit;
}
$command = basename($path);
if ($command === '' || $command === 'api') {
    http_response_code(404);
    echo json_encode('unknown command');
    exit;
}

$stateFile = __DIR__ . '/tmp/fake-ejabberd.json';
$dir = dirname($stateFile);
if (!is_dir($dir)) {
    mkdir($dir, 0770, true);
}
$fp = fopen($stateFile, 'c+');
if ($fp === false) {
    http_response_code(500);
    echo json_encode('state error');
    exit;
}
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($state)) {
    $state = ['users' => [], 'messages' => []];
}
if (!isset($state['users']) || !is_array($state['users'])) {
    $state['users'] = [];
}
if (!isset($state['messages']) || !is_array($state['messages'])) {
    $state['messages'] = [];
}

$input = file_get_contents('php://input');
$args = is_string($input) && $input !== '' ? json_decode($input, true) : [];
if (!is_array($args)) {
    $args = [];
}

$status = 200;
$result = null;
$user = (string) ($args['user'] ?? '');
$host = (string) ($args['host'] ?? '');

switch ($command) {
    case 'registered_vhosts':
        $result = ['test.localhost'];
        break;

    case 'check_account':
        $result = isset($state['users'][$user]) ? 0 : 1;
        break;

    case 'check_password':
        $result = isset($state['users'][$user]) && hash_equals((string) $state['users'][$user]['password'], (string) ($args['password'] ?? '')) ? 0 : 1;
        break;

    case 'register':
        if (isset($state['users'][$user])) {
            $status = 409;
            $result = 'Conflict';
        } else {
            $state['users'][$user] = [
                'host' => $host,
                'password' => (string) ($args['password'] ?? ''),
                'old_password' => null,
                'email' => null,
                'banned' => null,
            ];
            $result = 'User ' . $user . '@' . $host . ' successfully registered';
        }
        break;

    case 'private_set':
        if (!isset($state['users'][$user])) {
            $result = 1;
            break;
        }
        $element = (string) ($args['element'] ?? '');
        if (preg_match('~<private[^>]*>(.*?)</private>~s', $element, $m)) {
            $state['users'][$user]['email'] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $result = 0;
        break;

    case 'private_get':
        if (!isset($state['users'][$user])) {
            $result = 1;
            break;
        }
        $email = $state['users'][$user]['email'];
        if ($email === null || $email === '') {
            $xml = "<query xmlns='jabber:iq:private'><private xmlns='email'/></query>";
        } else {
            $xml = "<query xmlns='jabber:iq:private'><private xmlns='email'>" . htmlspecialchars((string) $email, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</private></query>";
        }
        $result = $xml;
        break;

    case 'ban_account':
        if (!isset($state['users'][$user])) {
            $result = 1;
            break;
        }
        $state['users'][$user]['old_password'] = $state['users'][$user]['password'];
        $state['users'][$user]['password'] = 'BANNED_' . bin2hex(random_bytes(4));
        $state['users'][$user]['banned'] = [
            'reason' => (string) ($args['reason'] ?? ''),
            'bandate' => gmdate('Y-m-d\TH:i:s\Z'),
            'lastdate' => gmdate('Y-m-d\TH:i:s\Z'),
            'lastreason' => "Registered but didn't login",
        ];
        $result = 0;
        break;

    case 'get_ban_details':
        if (!isset($state['users'][$user]) || $state['users'][$user]['banned'] === null) {
            $result = new stdClass();
        } else {
            $result = $state['users'][$user]['banned'];
        }
        break;

    case 'unban_account':
        if (!isset($state['users'][$user])) {
            $result = 1;
            break;
        }
        if ($state['users'][$user]['old_password'] !== null) {
            $state['users'][$user]['password'] = $state['users'][$user]['old_password'];
            $state['users'][$user]['old_password'] = null;
        }
        $state['users'][$user]['banned'] = null;
        $result = 0;
        break;

    case 'change_password':
        if (!isset($state['users'][$user])) {
            $result = 1;
            break;
        }
        $state['users'][$user]['password'] = (string) ($args['newpass'] ?? '');
        $result = 0;
        break;

    case 'kick_user':
        $result = 'Kicked sessions: 0';
        break;

    case 'unban_ip':
        $result = 0;
        break;

    case 'send_message':
        $state['messages'][] = [
            'from' => (string) ($args['from'] ?? ''),
            'to' => (string) ($args['to'] ?? ''),
            'subject' => (string) ($args['subject'] ?? ''),
            'body' => (string) ($args['body'] ?? ''),
            'type' => (string) ($args['type'] ?? ''),
            'at' => gmdate('c'),
        ];
        $result = 0;
        break;

    case 'unregister':
        unset($state['users'][$user]);
        $result = '';
        break;

    case 'get_vcard':
        $status = 400;
        $result = 'error_no_vcard_found';
        break;

    default:
        $status = 404;
        $result = 'unknown command';
        break;
}

$json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (is_string($json)) {
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $json);
    fflush($fp);
}
flock($fp, LOCK_UN);
fclose($fp);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
