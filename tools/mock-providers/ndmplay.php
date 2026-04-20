<?php
/**
 * NDMPlay mock provider — MD5 signing, enveloped JSON.
 * Boot: php -S 0.0.0.0:9001 tools/mock-providers/ndmplay.php
 */

const CREDENTIALS = [
    'alpha-ndmplay' => 'alpha-ndm-secret-123',
    'bravo-ndmplay' => 'bravo-ndm-secret-456',
];

const STATE_FILE = __DIR__ . '/state/ndmplay.json';
const BETS_FIXTURE = __DIR__ . '/fixtures/ndmplay-bets.json';
const GAMES_FIXTURE = __DIR__ . '/fixtures/ndmplay-games.json';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
$scenario = $query['scenario'] ?? null;

$rawBody = file_get_contents('php://input');
$body = ($method === 'POST' && $rawBody !== '')
    ? (json_decode($rawBody, true) ?? [])
    : [];
$input = array_merge($query, $body);

// --- Scenario short-circuits (applied before signature check) ---
if ($scenario === 'slow_5s') {
    sleep(5);
}
if ($scenario === 'timeout') {
    sleep(60);
}

// --- Signature validation (or bad_sig_response trap) ---
if ($scenario === 'bad_sig_response') {
    $provided = $input['sign'] ?? '';
    respondError(1001, "invalid signature (expected prefix: " . substr($provided, 0, 4) . "...)");
}

if (!in_array($path, ['/'], true)) {
    $agent = $input['agent_code'] ?? '';
    $ts = (string) ($input['timestamp'] ?? '');
    $sign = $input['sign'] ?? '';

    if (!isset(CREDENTIALS[$agent])) {
        respondError(1001, 'invalid signature');
    }
    $expected = md5($agent . $ts . CREDENTIALS[$agent]);
    if (!hash_equals($expected, (string) $sign)) {
        respondError(1001, 'invalid signature');
    }
}

// --- Route dispatch ---
switch ("$method $path") {
    case 'POST /create-player':
        handleCreatePlayer($input);
        break;
    case 'POST /deposit':
        handleDeposit($input, $scenario);
        break;
    case 'POST /withdraw':
        handleWithdraw($input, $scenario);
        break;
    case 'GET /balance':
        handleBalance($input);
        break;
    case 'GET /launch-url':
        handleLaunchUrl($input);
        break;
    case 'POST /bet-history':
        handleBetHistory($input);
        break;
    case 'GET /game-list':
        handleGameList($input);
        break;
    default:
        http_response_code(404);
        respondError(1500, "unknown route $method $path");
}

// ============================================================
// Handlers
// ============================================================

function handleCreatePlayer(array $input): void
{
    $state = loadState();
    $key = $input['agent_code'] . ':' . ($input['player_id'] ?? '');

    if (isset($state['players'][$key])) {
        respondError(1005, 'player already exists');
    }

    $state['players'][$key] = [
        'balance' => '0.00',
        'token' => bin2hex(random_bytes(8)),
        'created_at' => gmdate('c'),
    ];
    saveState($state);

    respondOk([
        'player_id' => $input['player_id'] ?? '',
        'token' => $state['players'][$key]['token'],
        'balance' => $state['players'][$key]['balance'],
    ]);
}

function handleDeposit(array $input, ?string $scenario): void
{
    if ($scenario === 'duplicate_txn') {
        respondError(1004, 'duplicate transaction');
    }

    $state = loadState();
    $key = findKeyByToken($state, (string) ($input['token'] ?? ''));
    $txnId = (string) ($input['txn_id'] ?? '');
    $amount = (float) ($input['amount'] ?? 0);

    if ($key === null) {
        respondError(1002, 'player not found');
    }
    if (in_array($txnId, $state['processed_txn_ids'], true)) {
        respondError(1004, 'duplicate transaction');
    }

    $state['players'][$key]['balance'] = number_format(
        (float) $state['players'][$key]['balance'] + $amount,
        2, '.', ''
    );
    $state['processed_txn_ids'][] = $txnId;
    saveState($state);

    respondOk(['balance' => $state['players'][$key]['balance']]);
}

function handleWithdraw(array $input, ?string $scenario): void
{
    if ($scenario === 'insufficient') {
        respondError(1003, 'insufficient balance');
    }
    if ($scenario === 'duplicate_txn') {
        respondError(1004, 'duplicate transaction');
    }

    $state = loadState();
    $key = findKeyByToken($state, (string) ($input['token'] ?? ''));
    $txnId = (string) ($input['txn_id'] ?? '');
    $amount = (float) ($input['amount'] ?? 0);

    if ($key === null) {
        respondError(1002, 'player not found');
    }
    if (in_array($txnId, $state['processed_txn_ids'], true)) {
        respondError(1004, 'duplicate transaction');
    }
    if ((float) $state['players'][$key]['balance'] < $amount) {
        respondError(1003, 'insufficient balance');
    }

    $state['players'][$key]['balance'] = number_format(
        (float) $state['players'][$key]['balance'] - $amount,
        2, '.', ''
    );
    $state['processed_txn_ids'][] = $txnId;
    saveState($state);

    respondOk(['balance' => $state['players'][$key]['balance']]);
}

function handleBalance(array $input): void
{
    $state = loadState();
    $key = findKeyByToken($state, (string) ($input['token'] ?? ''));

    if ($key === null) {
        respondError(1002, 'player not found');
    }

    respondOk(['balance' => $state['players'][$key]['balance']]);
}

function handleLaunchUrl(array $input): void
{
    $state = loadState();
    $token = (string) ($input['token'] ?? '');
    $key = findKeyByToken($state, $token);

    if ($key === null) {
        respondError(1002, 'player not found');
    }

    $round = 'R-' . bin2hex(random_bytes(4));
    respondOk([
        'url' => "http://mockprovider.ndmplay.local/play?token=$token&round=$round",
    ]);
}

function findKeyByToken(array $state, string $token): ?string
{
    if ($token === '') {
        return null;
    }
    foreach ($state['players'] as $k => $p) {
        if (($p['token'] ?? null) === $token) {
            return $k;
        }
    }
    return null;
}

function handleBetHistory(array $input): void
{
    $rows = json_decode(file_get_contents(BETS_FIXTURE), true) ?? [];
    $from = $input['from'] ?? null;
    $to = $input['to'] ?? null;
    $page = max(1, (int) ($input['page'] ?? 1));
    $size = max(1, (int) ($input['size'] ?? 50));

    $filtered = array_values(array_filter($rows, function ($r) use ($from, $to) {
        if ($from !== null && $r['bet_time'] < $from) return false;
        if ($to !== null && $r['bet_time'] > $to) return false;
        return true;
    }));

    $total = count($filtered);
    $paged = array_slice($filtered, ($page - 1) * $size, $size);

    respondOk([
        'total' => $total,
        'page' => $page,
        'size' => $size,
        'rows' => $paged,
    ]);
}

function handleGameList(array $input): void
{
    $games = json_decode(file_get_contents(GAMES_FIXTURE), true) ?? [];
    $category = $input['category'] ?? null;

    if ($category !== null) {
        $games = array_values(array_filter($games, fn($g) => $g['category'] === $category));
    }

    respondOk(['games' => $games]);
}

// ============================================================
// Helpers
// ============================================================

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
    echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => $data]);
    exit;
}

function respondError(int $code, string $msg): void
{
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => null]);
    exit;
}
