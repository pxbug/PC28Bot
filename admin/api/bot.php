<?php
/**
 * Bot API — endpoints called by the Python bot
 *
 * Endpoints:
 *   POST /api/bot/user/create       Create or get user by openid
 *   POST /api/bot/user/balance     Get user balance
 *   POST /api/bot/user/deposit     Add deposit (manual)
 *   POST /api/bot/user/withdraw    Add withdrawal request
 *   POST /api/bot/bet/place        Place a bet
 *   POST /api/bot/bet/settle       Settle bets for a period
 *   POST /api/bot/lottery/result   Record lottery result
 *   POST /api/bot/cashback/calc    Calculate cashback
 *   GET  /api/bot/lottery/latest   Get latest lottery result
 *
 * Security: all POST requests require app_id + secret_key verification.
 */

// Allow CORS from any origin (adjust for production)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Load config
$configFile = dirname(__DIR__, 2) . '/config/robot.config.json';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
$appId = $config['admin_api']['app_id'] ?? '';
$secretKey = $config['admin_api']['secret_key'] ?? '';
$allowedAppId = $config['admin_api']['app_id'] ?? 'pc28bot';

// Initialize DB
DB::init(dirname(__DIR__) . '/data/admin.db');

// ── Auth ─────────────────────────────────────────────────────────────
function authBot(): bool {
    global $appId, $secretKey, $allowedAppId;

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $givenAppId = $input['app_id'] ?? $_SERVER['HTTP_X_APP_ID'] ?? '';
    $givenKey = $input['secret_key'] ?? $_SERVER['HTTP_X_SECRET_KEY'] ?? '';

    // Also accept secret_key in body for simpler integration
    if (empty($givenKey) && isset($input['secret'])) {
        $givenKey = $input['secret'];
    }

    if ($givenAppId !== $allowedAppId) return false;
    if (!empty($secretKey) && $givenKey !== $secretKey) return false;
    return true;
}

function jsonResp(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireAuth(): void {
    if (!authBot()) {
        jsonResp(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

// ── Helpers ─────────────────────────────────────────────────────────
function getUser(int $userId): ?array {
    return DB::fetch("SELECT * FROM users WHERE id = ?", [$userId]);
}

function getUserByOpenid(string $openid): ?array {
    return DB::fetch("SELECT * FROM users WHERE openid = ?", [$openid]);
}

function getOrCreateUser(string $openid, ?string $nickname = null): array {
    $user = getUserByOpenid($openid);
    if (!$user) {
        $id = DB::insert('users', [
            'openid' => $openid,
            'nickname' => $nickname ?: 'Player_' . substr($openid, 0, 8),
            'balance' => 0,
            'status' => 'active',
        ]);
        $user = getUser($id);
    }
    return $user;
}

// ── Routing ──────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/api/bot/';
if (!str_starts_with($uri, $base)) {
    jsonResp(['ok' => false, 'error' => 'Not Found'], 404);
}

$endpoint = substr($uri, strlen($base));
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$_POST = array_merge($_POST, $input); // merge for compatibility

// ── /user/create ────────────────────────────────────────────────────
if ($endpoint === 'user/create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $openid = trim($input['openid'] ?? '');
    $nickname = trim($input['nickname'] ?? '');
    if (empty($openid)) jsonResp(['ok' => false, 'error' => 'openid required'], 400);

    $user = getOrCreateUser($openid, $nickname);
    jsonResp([
        'ok' => true,
        'user' => [
            'id' => $user['id'],
            'openid' => $user['openid'],
            'nickname' => $user['nickname'],
            'balance' => (float) $user['balance'],
            'status' => $user['status'],
        ]
    ]);
}

// ── /user/balance ───────────────────────────────────────────────────
if ($endpoint === 'user/balance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $userId = intval($input['user_id'] ?? 0);
    if (!$userId) jsonResp(['ok' => false, 'error' => 'user_id required'], 400);

    $user = getUser($userId);
    if (!$user) jsonResp(['ok' => false, 'error' => 'user not found'], 404);

    jsonResp([
        'ok' => true,
        'user_id' => $user['id'],
        'balance' => (float) $user['balance'],
        'status' => $user['status'],
    ]);
}

// ── /user/deposit ───────────────────────────────────────────────────
if ($endpoint === 'user/deposit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $userId = intval($input['user_id'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $method = trim($input['method'] ?? 'bot');
    $note = trim($input['note'] ?? '');
    if (!$userId || $amount <= 0) jsonResp(['ok' => false, 'error' => 'invalid params'], 400);

    $user = getUser($userId);
    if (!$user) jsonResp(['ok' => false, 'error' => 'user not found'], 404);

    // Insert deposit record
    $depId = DB::insert('deposits', [
        'user_id' => $userId,
        'amount' => $amount,
        'method' => $method,
        'status' => 'approved', // bot-triggered deposits are auto-approved
        'note' => $note ?: 'Bot自动充值',
        'processed_at' => time(),
    ]);

    // Update user balance
    $newBalance = $user['balance'] + $amount;
    DB::update('users', [
        'balance' => $newBalance,
        'total_deposit' => $user['total_deposit'] + $amount,
        'updated_at' => time(),
    ], 'id = ?', [$userId]);

    jsonResp([
        'ok' => true,
        'deposit_id' => $depId,
        'new_balance' => $newBalance,
    ]);
}

// ── /user/withdraw ─────────────────────────────────────────────────
if ($endpoint === 'user/withdraw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $userId = intval($input['user_id'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $method = trim($input['method'] ?? 'manual');
    $bankInfo = trim($input['bank_info'] ?? '');
    if (!$userId || $amount <= 0) jsonResp(['ok' => false, 'error' => 'invalid params'], 400);

    $user = getUser($userId);
    if (!$user) jsonResp(['ok' => false, 'error' => 'user not found'], 404);
    if ($user['balance'] < $amount) jsonResp(['ok' => false, 'error' => 'insufficient balance'], 400);

    $wid = DB::insert('withdrawals', [
        'user_id' => $userId,
        'amount' => $amount,
        'method' => $method,
        'bank_info' => $bankInfo,
        'status' => 'pending',
    ]);

    // Deduct balance immediately (or keep pending based on business logic)
    $newBalance = $user['balance'] - $amount;
    DB::update('users', ['balance' => $newBalance, 'updated_at' => time()], 'id=?', [$userId]);

    jsonResp([
        'ok' => true,
        'withdraw_id' => $wid,
        'new_balance' => $newBalance,
    ]);
}

// ── /bet/place ──────────────────────────────────────────────────────
if ($endpoint === 'bet/place' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $userId = intval($input['user_id'] ?? 0);
    $period = trim($input['period'] ?? '');
    $betValue = trim($input['bet_value'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $odds = floatval($input['odds'] ?? 1.0);

    if (!$userId || !$period || !$betValue || $amount <= 0) {
        jsonResp(['ok' => false, 'error' => 'invalid params'], 400);
    }

    $user = getUser($userId);
    if (!$user) jsonResp(['ok' => false, 'error' => 'user not found'], 404);
    if ($user['balance'] < $amount) jsonResp(['ok' => false, 'error' => 'insufficient balance'], 400);

    // Deduct balance
    $newBalance = $user['balance'] - $amount;
    DB::update('users', [
        'balance' => $newBalance,
        'total_bet' => $user['total_bet'] + 1,
        'last_bet' => time(),
        'updated_at' => time(),
    ], 'id=?', [$userId]);

    $betId = DB::insert('bets', [
        'user_id' => $userId,
        'period' => $period,
        'bet_type' => '',
        'bet_value' => $betValue,
        'amount' => $amount,
        'odds' => $odds,
        'result' => 'pending',
    ]);

    jsonResp([
        'ok' => true,
        'bet_id' => $betId,
        'new_balance' => $newBalance,
    ]);
}

// ── /bet/settle ────────────────────────────────────────────────────
if ($endpoint === 'bet/settle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $period = trim($input['period'] ?? '');
    $result = trim($input['result'] ?? ''); // e.g. "大", "小", "单", "双", "13", etc.
    if (!$period) jsonResp(['ok' => false, 'error' => 'period required'], 400);

    // Get all pending bets for this period
    $pendingBets = DB::fetchAll(
        "SELECT * FROM bets WHERE period = ? AND result = 'pending'",
        [$period]
    );

    $settled = 0;
    $totalPayout = 0.0;

    foreach ($pendingBets as $bet) {
        $winAmount = 0.0;
        $result_ = 'lose';

        // Determine if bet wins based on result type
        // bet_value like "大", "小", "单", "双", "13", "极大", etc.
        $betVal = trim($bet['bet_value']);

        // Simple win logic — bot can override via 'result' param if needed
        // Check if bet_value matches the lottery result
        $wins = false;

        // For numeric bets (0-27)
        if (is_numeric($betVal) && is_numeric($result)) {
            $wins = ((int)$betVal === (int)$result);
        }
        // For named bets — check exact match or via result field
        elseif (in_array($betVal, ['大', '小', '单', '双'])) {
            $wins = ($betVal === $result);
        }
        // For combo bets, the bot should pass explicit win/lose
        else {
            // If result param contains "win" marker, check if bet_value is in it
            // This is a fallback — actual odds calculation should be done by bot
            $wins = false;
        }

        if ($wins) {
            $winAmount = $bet['amount'] * $bet['odds'];
            $result_ = 'win';
            $totalPayout += $winAmount;
        }

        DB::update('bets', [
            'result' => $result_,
            'win_amount' => $winAmount,
            'lottery_result' => is_string($result) ? $result : (string)$result,
            'settled_at' => time(),
        ], 'id=?', [$bet['id']]);

        // Refund + winnings to user
        if ($result_ === 'win') {
            $user = getUser($bet['user_id']);
            if ($user) {
                DB::update('users', [
                    'balance' => $user['balance'] + $winAmount,
                    'total_win' => $user['total_win'] + $winAmount,
                    'updated_at' => time(),
                ], 'id=?', [$bet['user_id']]);
            }
        }

        $settled++;
    }

    jsonResp([
        'ok' => true,
        'period' => $period,
        'settled_count' => $settled,
        'total_payout' => $totalPayout,
    ]);
}

// ── /lottery/result ────────────────────────────────────────────────
if ($endpoint === 'lottery/result' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $period = trim($input['period'] ?? '');
    $numbersRaw = trim($input['numbers'] ?? ''); // comma-separated or JSON array

    if (!$period || !$numbersRaw) jsonResp(['ok' => false, 'error' => 'period and numbers required'], 400);

    // Parse numbers
    $nums = [];
    $raw = json_decode($numbersRaw, true);
    if (is_array($raw)) {
        $nums = $raw;
    } else {
        $nums = array_filter(array_map('intval', preg_split('/[,\s]+/', $numbersRaw)));
    }

    if (count($nums) < 3) jsonResp(['ok' => false, 'error' => 'need at least 3 numbers'], 400);
    $nums = array_slice($nums, 0, 3);
    $total = array_sum($nums);
    $size = $total >= 14 ? '大' : '小';
    $oddEven = $total % 2 === 0 ? '双' : '单';

    $existing = DB::fetch("SELECT id FROM lottery_history WHERE period=?", [$period]);
    if ($existing) {
        DB::update('lottery_history', [
            'numbers' => json_encode($nums),
            'total' => $total,
            'size' => $size,
            'odd_even' => $oddEven,
        ], 'period=?', [$period]);
        $lotId = $existing['id'];
    } else {
        $lotId = DB::insert('lottery_history', [
            'period' => $period,
            'numbers' => json_encode($nums),
            'total' => $total,
            'size' => $size,
            'odd_even' => $oddEven,
        ]);
    }

    jsonResp([
        'ok' => true,
        'lottery_id' => $lotId,
        'numbers' => $nums,
        'total' => $total,
        'size' => $size,
        'odd_even' => $oddEven,
    ]);
}

// ── /lottery/latest ────────────────────────────────────────────────
if ($endpoint === 'lottery/latest' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $latest = DB::fetch("SELECT * FROM lottery_history ORDER BY id DESC LIMIT 1");
    if (!$latest) jsonResp(['ok' => false, 'error' => 'no lottery data'], 404);
    jsonResp([
        'ok' => true,
        'period' => $latest['period'],
        'numbers' => json_decode($latest['numbers'], true),
        'total' => $latest['total'],
        'size' => $latest['size'],
        'odd_even' => $latest['odd_even'],
        'created_at' => $latest['created_at'],
    ]);
}

// ── /cashback/calc ─────────────────────────────────────────────────
if ($endpoint === 'cashback/calc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAuth();
    $userId = intval($input['user_id'] ?? 0);
    $period = trim($input['period'] ?? '');
    $betAmount = floatval($input['bet_amount'] ?? 0);

    if (!$userId || !$period || $betAmount <= 0) {
        jsonResp(['ok' => false, 'error' => 'invalid params'], 400);
    }

    $user = getUser($userId);
    if (!$user) jsonResp(['ok' => false, 'error' => 'user not found'], 404);

    // Cashback rate: 0.6% base, 0.1% penalty for high ratio bets, 0.4% penalty for hedging
    $rate = 0.006;
    // (Additional logic can be added based on bet type distribution)

    $cashback = round($betAmount * $rate, 2);
    if ($cashback < 0.01) $cashback = 0;

    if ($cashback > 0) {
        DB::insert('cashback_log', [
            'user_id' => $userId,
            'period' => $period,
            'bet_amount' => $betAmount,
            'cashback' => $cashback,
        ]);

        DB::update('users', [
            'balance' => $user['balance'] + $cashback,
            'updated_at' => time(),
        ], 'id=?', [$userId]);
    }

    jsonResp([
        'ok' => true,
        'cashback' => $cashback,
        'new_balance' => $user['balance'] + $cashback,
    ]);
}

// ── /stats/overview ───────────────────────────────────────────────
if ($endpoint === 'stats/overview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $totalUsers = DB::count("SELECT COUNT(*) FROM users");
    $totalBalance = DB::sum("SELECT SUM(balance) FROM users");
    $pendingDeposits = DB::count("SELECT COUNT(*) FROM deposits WHERE status='pending'");
    $pendingWithdrawals = DB::count("SELECT COUNT(*) FROM withdrawals WHERE status='pending'");
    $todayBetCount = DB::count("SELECT COUNT(*) FROM bets WHERE created_at >= ?", [strtotime('today')]);

    jsonResp([
        'ok' => true,
        'total_users' => $totalUsers,
        'total_balance' => $totalBalance,
        'pending_deposits' => $pendingDeposits,
        'pending_withdrawals' => $pendingWithdrawals,
        'today_bet_count' => $todayBetCount,
    ]);
}

// Fallback: 404
jsonResp(['ok' => false, 'error' => 'Unknown endpoint: ' . $endpoint], 404);
