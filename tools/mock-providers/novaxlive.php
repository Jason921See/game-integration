<?php
/**
 * NovaXLive mock provider — AES-128-CBC encrypted payload, FUMA-style.
 * Boot: php -S 0.0.0.0:9002 tools/mock-providers/novaxlive.php
 *
 * Request: application/x-www-form-urlencoded
 *   dc   = agent code
 *   x    = URL-safe base64(AES-128-CBC(plaintext_json_padded_to_16))
 *   uuid = player uuid (optional)
 *
 * Plaintext payload dispatches on `action` field (1,2,3,5,6,12).
 */

const CREDENTIALS = [
    'alpha-novax' => ['key' => 'alphaNovaXKey128', 'iv' => 'alphaNovaXIv_123'],
    'bravo-novax' => ['key' => 'bravoNovaXKey128', 'iv' => 'bravoNovaXIv_123'],
];

const STATE_FILE = __DIR__ . '/state/novaxlive.json';
const BETS_FIXTURE = __DIR__ . '/fixtures/novaxlive-bets.json';
const GAMES_FIXTURE = __DIR__ . '/fixtures/novaxlive-games.json';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
$scenario = $query['scenario'] ?? null;

// --- Scenario short-circuits (applied first) ---
if ($scenario === 'slow_5s') {
    sleep(5);
}
if ($scenario === 'timeout') {
    sleep(60);
}
if ($scenario === 'bad_sig_response') {
    $bodyTrap = substr($_POST['x'] ?? '', 0, 6);
    respondError('1001', "invalid dc / decrypt failure (x-prefix: $bodyTrap...)");
}

// --- Only POST is supported ---
if ($method !== 'POST') {
    http_response_code(405);
    respondError('9999', "method $method not allowed");
}

// --- Decrypt incoming request ---
$dc = $_POST['dc'] ?? '';
$x = $_POST['x'] ?? '';
$uuid = $_POST['uuid'] ?? '';

if (!isset(CREDENTIALS[$dc])) {
    respondError('1001', 'invalid dc / decrypt failure');
}

$payload = decryptPayload($x, CREDENTIALS[$dc]['key'], CREDENTIALS[$dc]['iv']);
if ($payload === null) {
    respondError('1001', 'invalid dc / decrypt failure');
}

$action = $payload['action'] ?? 0;

// --- Dispatch on endpoint + action ---
$routeKey = "$method $path:$action";
switch ($routeKey) {
    case 'POST /Tr_CreateUser:2':
        handleCreateUser($dc, $payload);
        break;
    case 'POST /Tr_GetToken:1':
        handleGetToken($dc, $payload);
        break;
    case 'POST /Tr_UserInfo:3':
        handleUserInfo($dc, $payload);
        break;
    case 'POST /Tr_ChangeV:5':
        handleChangeV($dc, $payload, $scenario);
        break;
    case 'POST /Tr_GameList:6':
        handleGameList($dc, $payload);
        break;
    case 'POST /Tr_QueryGameJsonResult:12':
        handleBetHistory($dc, $payload);
        break;
    default:
        http_response_code(404);
        respondError('9999', "unknown route $method $path with action $action");
}

// ============================================================
// Handlers
// ============================================================

function handleCreateUser(string $dc, array $payload): void
{
    $state = loadState();
    $key = $dc . ':' . ($payload['uid'] ?? '');

    if (isset($state['players'][$key])) {
        respondError('1005', 'player already exists');
    }

    $state['players'][$key] = [
        'balance' => '0.00',
        'token' => bin2hex(random_bytes(8)),
        'created_at' => gmdate('c'),
    ];
    saveState($state);

    respondOk([
        'uid' => $payload['uid'] ?? '',
        'balance' => (float) $state['players'][$key]['balance'],
    ]);
}

function handleGetToken(string $dc, array $payload): void
{
    $state = loadState();
    $key = $dc . ':' . ($payload['uid'] ?? '');

    if (!isset($state['players'][$key])) {
        respondError('1002', 'player not found');
    }

    $token = $state['players'][$key]['token'];
    $round = 'R-' . bin2hex(random_bytes(4));
    // FUMA returns { path: url } at the top-level alongside status
    echo json_encode([
        'status' => '0000',
        'path' => "http://mockprovider.novaxlive.local/play?token=$token&round=$round",
        'err_text' => '',
    ]);
    exit;
}

function handleUserInfo(string $dc, array $payload): void
{
    $state = loadState();
    $key = $dc . ':' . ($payload['uid'] ?? '');

    if (!isset($state['players'][$key])) {
        respondError('1002', 'player not found');
    }

    respondOk(['balance' => (float) $state['players'][$key]['balance']]);
}

function handleChangeV(string $dc, array $payload, ?string $scenario): void
{
    if ($scenario === 'duplicate_txn') {
        respondError('1004', 'duplicate serialNo');
    }

    $state = loadState();
    $key = $dc . ':' . ($payload['uid'] ?? '');
    $serialNo = (string) ($payload['serialNo'] ?? '');
    $flag = (int) ($payload['allCashOutFlag'] ?? 0);
    $amount = (float) ($payload['amount'] ?? 0);

    if (!isset($state['players'][$key])) {
        respondError('1002', 'player not found');
    }
    if (in_array($serialNo, $state['processed_txn_ids'], true)) {
        respondError('1004', 'duplicate serialNo');
    }

    $current = (float) $state['players'][$key]['balance'];

    if ($flag === 1) {
        // full cash-out (withdraw all)
        if ($scenario === 'insufficient' || $current <= 0) {
            respondError('1003', 'insufficient balance');
        }
        $state['players'][$key]['balance'] = '0.00';
        $returned = $current;
    } else {
        // deposit
        $state['players'][$key]['balance'] = number_format($current + $amount, 2, '.', '');
        $returned = $amount;
    }

    $state['processed_txn_ids'][] = $serialNo;
    saveState($state);

    respondOk([
        'balance' => (float) $state['players'][$key]['balance'],
        'amount' => $returned,
        'serialNo' => $serialNo,
    ]);
}

function handleGameList(string $dc, array $payload): void
{
    $games = json_decode(file_get_contents(GAMES_FIXTURE), true) ?? [];
    // FUMA puts `data` as a top-level array, not under `{games:[]}`
    echo json_encode([
        'status' => '0000',
        'data' => $games,
        'err_text' => '',
    ]);
    exit;
}

function handleBetHistory(string $dc, array $payload): void
{
    $rows = json_decode(file_get_contents(BETS_FIXTURE), true) ?? [];
    $uid = $payload['uid'] ?? '';
    $start = $payload['starttime'] ?? null;
    $end = $payload['endtime'] ?? null;

    $filtered = array_values(array_filter($rows, function ($r) use ($uid, $start, $end) {
        if ($uid !== '' && $r['uid'] !== $uid) return false;
        if ($start !== null && $r['time'] < $start) return false;
        if ($end !== null && $r['time'] > $end) return false;
        return true;
    }));

    echo json_encode([
        'status' => '0000',
        'data' => $filtered,
        'err_text' => '',
    ]);
    exit;
}

// ============================================================
// Crypto + helpers
// ============================================================

function decryptPayload(string $x, string $key, string $iv): ?array
{
    // URL-safe base64 → standard base64
    $b64 = strtr($x, ['-' => '+', '_' => '/']);
    $pad = 4 - (strlen($b64) % 4);
    if ($pad < 4) {
        $b64 .= str_repeat('=', $pad);
    }
    $cipher = base64_decode($b64, true);
    if ($cipher === false) {
        return null;
    }
    $plain = @openssl_decrypt($cipher, 'AES-128-CBC', $key, OPENSSL_NO_PADDING, $iv);
    if ($plain === false) {
        return null;
    }
    $trimmed = rtrim($plain, " \0");
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : null;
}

function loadState(): array
{
    if (!file_exists(STATE_FILE)) {
        return ['players' => [], 'processed_txn_ids' => []];
    }
    $raw = file_get_contents(STATE_FILE);
    $s = json_decode($raw, true) ?? [];
    return [
        'players' => $s['players'] ?? [],
        'processed_txn_ids' => $s['processed_txn_ids'] ?? [],
    ];
}

function saveState(array $state): void
{
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function respondOk(array $data): void
{
    echo json_encode([
        'status' => '0000',
        'data' => $data,
        'err_text' => '',
    ]);
    exit;
}

function respondError(string $status, string $errText): void
{
    echo json_encode([
        'status' => $status,
        'data' => null,
        'err_text' => $errText,
    ]);
    exit;
}
