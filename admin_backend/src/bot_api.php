<?php
/**
 * Bot API 主入口（供机器人调用）
 *
 * 路由（POST 请求，带签名验证）：
 * - /api/bot/register    注册/同步用户
 * - /api/bot/user_info   查询用户信息
 * - /api/bot/balance     查询余额
 * - /api/bot/bet         用户下注
 * - /api/bet/settle      开奖结算
 * - /api/bet/list        获取期号下注列表
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// 简单的路由
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/bot/?#', '', $path);
$path = trim($path, '/');

if ($path === '' || $path === 'index') {
    jsonResponse(0, 'PC28 Bot API', [
        'version' => '1.0.0',
        'endpoints' => ['register', 'user_info', 'balance', 'bet', 'settle', 'bet_list'],
    ]);
}

// 所有 API 都需要签名验证
verifySign();

switch ($path) {
    case 'register':
        handleRegister();
        break;
    case 'user_info':
        handleUserInfo();
        break;
    case 'balance':
        handleBalance();
        break;
    case 'bet':
        handleBet();
        break;
    case 'settle':
        handleSettle();
        break;
    case 'bet_list':
        handleBetList();
        break;
    default:
        jsonResponse(404, 'API not found: ' . $path);
}

// ==================== 用户相关 ====================

function handleRegister() {
    $input = getJsonInput();
    $uid = trim($input['uid'] ?? '');
    $nickname = trim($input['nickname'] ?? '');

    if (!$uid) jsonResponse(4001, 'uid 不能为空');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if ($user) {
        // 更新昵称
        if ($nickname && $nickname !== $user['nickname']) {
            $stmt = $pdo->prepare("UPDATE " . table('user') . " SET nickname = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$nickname, $user['id']]);
            $user['nickname'] = $nickname;
        }
        jsonResponse(0, '用户已存在', formatUser($user));
    }

    $stmt = $pdo->prepare("INSERT INTO " . table('user') . " (uid, nickname, balance, status, created_at, updated_at) VALUES (?, ?, 0.00, 1, NOW(), NOW())");
    $stmt->execute([$uid, $nickname]);
    $id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    jsonResponse(0, '注册成功', formatUser($user));
}

function handleUserInfo() {
    $input = getJsonInput();
    $uid = trim($input['uid'] ?? '');
    if (!$uid) jsonResponse(4001, 'uid 不能为空');

    $stmt = db()->prepare("SELECT * FROM " . table('user') . " WHERE uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(4041, '用户不存在');

    jsonResponse(0, 'success', formatUser($user));
}

function handleBalance() {
    $input = getJsonInput();
    $uid = trim($input['uid'] ?? '');
    if (!$uid) jsonResponse(4001, 'uid 不能为空');

    $stmt = db()->prepare("SELECT balance FROM " . table('user') . " WHERE uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(4041, '用户不存在');

    jsonResponse(0, 'success', [
        'uid' => $uid,
        'balance' => (float)$user['balance'],
    ]);
}

// ==================== 下注相关 ====================

function handleBet() {
    $input = getJsonInput();
    $uid = trim($input['uid'] ?? '');
    $issue = trim($input['issue'] ?? '');
    $betsJson = $input['bets'] ?? '[]';
    $bets = is_array($betsJson) ? $betsJson : json_decode($betsJson, true);

    if (!$uid || !$issue) jsonResponse(4001, 'uid 和 issue 不能为空');
    if (!is_array($bets) || empty($bets)) jsonResponse(4002, 'bets 格式错误或为空');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE uid = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(4041, '用户不存在，请先注册');
    if ($user['status'] != 1) jsonResponse(4031, '账户已被冻结');

    $totalAmount = 0;
    foreach ($bets as $bet) {
        $totalAmount += floatval($bet['amount'] ?? 0);
    }
    if ($totalAmount <= 0) jsonResponse(4003, '下注金额必须大于 0');

    if (bccomp($user['balance'], $totalAmount, 2) < 0) {
        jsonResponse(4004, '余额不足，当前余额: ' . $user['balance']);
    }

    $pdo->beginTransaction();
    try {
        $balanceBefore = $user['balance'];
        $newBalance = bcsub($user['balance'], $totalAmount, 2);
        $stmt = $pdo->prepare("UPDATE " . table('user') . " SET balance = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newBalance, $user['id']]);

        // 记录流水
        $stmt = $pdo->prepare("INSERT INTO " . table('balance_log') . " (uid, action, amount, balance_before, balance_after, operator_id, operator_name, note, issue, created_at) VALUES (?, 'bet', ?, ?, ?, 0, 'Bot', ?, ?, NOW())");
        $stmt->execute([$uid, -$totalAmount, $balanceBefore, $newBalance, '下注 ' . count($bets) . ' 注', $issue]);

        // 写入下注记录
        $stmt = $pdo->prepare("INSERT INTO " . table('bet') . " (uid, issue, bet_type, bet_content, amount, odds, status, settle_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0.00, NOW())");
        foreach ($bets as $bet) {
            $stmt->execute([
                $uid,
                $issue,
                $bet['type'] ?? '',
                $bet['content'] ?? '',
                floatval($bet['amount'] ?? 0),
                floatval($bet['odds'] ?? 1.0),
            ]);
        }

        $pdo->commit();
        jsonResponse(0, '下注成功', [
            'total_amount' => (float)$totalAmount,
            'balance' => (float)$newBalance,
            'bet_count' => count($bets),
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(5001, '下注失败: ' . $e->getMessage());
    }
}

function handleSettle() {
    $input = getJsonInput();
    $issue = trim($input['issue'] ?? '');
    $number = trim($input['number'] ?? '');
    $sum = intval($input['sum'] ?? -1);

    if (!$issue || $sum < 0 || $sum > 27) {
        jsonResponse(4001, 'issue 或 sum 参数错误');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM " . table('bet') . " WHERE issue = ? AND status = 0");
    $stmt->execute([$issue]);
    $bets = $stmt->fetchAll();

    if (empty($bets)) {
        jsonResponse(0, '该期无下注记录', [
            'settled_count' => 0,
            'settle_amount' => 0,
        ]);
    }

    $isBig = $sum >= 14;
    $isSmall = $sum <= 13;
    $isOdd = $sum % 2 == 1;
    $isEven = $sum % 2 == 0;

    $settleResults = [];
    $totalSettle = 0;

    $pdo->beginTransaction();
    try {
        foreach ($bets as $bet) {
            $win = checkWin($bet['bet_type'], $bet['bet_content'], $sum, $isBig, $isSmall, $isOdd, $isEven);

            if ($win) {
                $settleAmount = bcmul($bet['amount'], $bet['odds'], 2);
                $stmt = $pdo->prepare("UPDATE " . table('bet') . " SET status = 1, settle_amount = ?, settled_at = NOW() WHERE id = ?");
                $stmt->execute([$settleAmount, $bet['id']]);

                // 给用户加余额
                $stmt2 = $pdo->prepare("SELECT * FROM " . table('user') . " WHERE uid = ? LIMIT 1");
                $stmt2->execute([$bet['uid']]);
                $user = $stmt2->fetch();

                if ($user) {
                    $balanceBefore = $user['balance'];
                    $newBalance = bcadd($user['balance'], $settleAmount, 2);
                    $stmt3 = $pdo->prepare("UPDATE " . table('user') . " SET balance = ?, updated_at = NOW() WHERE id = ?");
                    $stmt3->execute([$newBalance, $user['id']]);

                    // 流水
                    $stmt4 = $pdo->prepare("INSERT INTO " . table('balance_log') . " (uid, action, amount, balance_before, balance_after, operator_id, operator_name, note, issue, created_at) VALUES (?, 'settle', ?, ?, ?, 0, 'Bot', ?, ?, NOW())");
                    $stmt4->execute([$bet['uid'], $settleAmount, $balanceBefore, $newBalance, '结算中奖: ' . $bet['bet_content'], $issue]);
                }

                $totalSettle = bcadd($totalSettle, $settleAmount, 2);
                $settleResults[] = [
                    'uid' => $bet['uid'],
                    'bet_content' => $bet['bet_content'],
                    'amount' => (float)$bet['amount'],
                    'odds' => (float)$bet['odds'],
                    'settle_amount' => (float)$settleAmount,
                ];
            } else {
                $stmt = $pdo->prepare("UPDATE " . table('bet') . " SET status = 2, settle_amount = 0.00, settled_at = NOW() WHERE id = ?");
                $stmt->execute([$bet['id']]);
            }
        }

        $pdo->commit();
        jsonResponse(0, '结算完成', [
            'issue' => $issue,
            'number' => $number,
            'sum' => $sum,
            'settled_count' => count($settleResults),
            'settle_amount' => (float)$totalSettle,
            'results' => $settleResults,
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(5001, '结算失败: ' . $e->getMessage());
    }
}

function checkWin($type, $content, $sum, $isBig, $isSmall, $isOdd, $isEven) {
    $content = mb_strtolower(trim($content));

    switch ($type) {
        case 'dx':
            if ($content === '大') return $isBig;
            if ($content === '小') return $isSmall;
            break;
        case 'dd':
            if ($content === '单') return $isOdd;
            if ($content === '双') return $isEven;
            break;
        case 'dxdd':
            if ($content === '大单') return $isBig && $isOdd;
            if ($content === '小单') return $isSmall && $isOdd;
            if ($content === '大双') return $isBig && $isEven;
            if ($content === '小双') return $isSmall && $isEven;
            break;
        case 'jd':
            return $sum >= 22;
        case 'jx':
            return $sum <= 5;
        case 'num':
            return intval($content) === $sum;
    }
    return false;
}

function handleBetList() {
    $input = getJsonInput();
    $issue = trim($input['issue'] ?? '');
    if (!$issue) jsonResponse(4001, 'issue 不能为空');

    $stmt = db()->prepare("SELECT * FROM " . table('bet') . " WHERE issue = ? ORDER BY created_at ASC");
    $stmt->execute([$issue]);
    $bets = $stmt->fetchAll();

    $list = [];
    $totalAmount = 0;
    foreach ($bets as $bet) {
        $list[] = [
            'uid' => $bet['uid'],
            'bet_type' => $bet['bet_type'],
            'bet_content' => $bet['bet_content'],
            'amount' => (float)$bet['amount'],
            'odds' => (float)$bet['odds'],
            'status' => intval($bet['status']),
            'created_at' => $bet['created_at'],
        ];
        $totalAmount = bcadd($totalAmount, $bet['amount'], 2);
    }

    jsonResponse(0, 'success', [
        'issue' => $issue,
        'total_amount' => (float)$totalAmount,
        'count' => count($bets),
        'list' => $list,
    ]);
}

// ==================== 工具 ====================

function formatUser($user) {
    return [
        'id' => intval($user['id']),
        'uid' => $user['uid'],
        'nickname' => $user['nickname'],
        'balance' => (float)$user['balance'],
        'status' => intval($user['status']),
        'remark' => $user['remark'] ?? '',
        'created_at' => $user['created_at'],
    ];
}
