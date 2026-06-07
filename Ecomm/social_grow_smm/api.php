<?php
/*
 * SocialGrow SMM Panel — Single file PHP + SQLite
 * Compatible: PHP 7.2+  |  PDO SQLite required
 *
 * Apache .htaccess fix for Authorization header:
 *   RewriteEngine On
 *   RewriteCond %{HTTP:Authorization} ^(.+)$
 *   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Output buffer stops any stray whitespace breaking JSON headers
ob_start();

// ── CORS & preflight ───────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code(204);
    ob_end_clean();
    exit;
}

// ── Global exception handler ───────────────────────────────────────────────
set_exception_handler(function($e) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'line'  => $e->getLine(),
        'file'  => basename($e->getFile())
    ]);
    exit;
});

// ── Helpers ────────────────────────────────────────────────────────────────
function respond($payload, $status = 200) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function nowIso() { return gmdate('c'); }

function nextId() { return strtoupper(bin2hex(random_bytes(5))); }

function tokenHash($t) { return hash('sha256', $t); }

function requireString($input, $key, $min = 1) {
    $v = trim((string)($input[$key] ?? ''));
    if (mb_strlen($v) < $min) respond(['ok' => false, 'error' => "Invalid {$key}"], 400);
    return $v;
}

function getJson() {
    $raw = file_get_contents('php://input');
    if (!$raw || !trim($raw)) return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) respond(['ok' => false, 'error' => 'Invalid JSON body'], 400);
    return $d;
}

// ── Database ───────────────────────────────────────────────────────────────
function openDb() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!extension_loaded('pdo_sqlite')) {
        respond(['ok' => false, 'error' => 'PDO SQLite extension not loaded on server'], 500);
    }

    $path = __DIR__ . '/socialgrow.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $ex) {
        respond(['ok' => false, 'error' => 'Database error: ' . $ex->getMessage()], 500);
    }

    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    migrateDb($pdo);
    return $pdo;
}

function migrateDb($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL COLLATE NOCASE UNIQUE,
            email TEXT NOT NULL UNIQUE,
            passwordHash TEXT NOT NULL,
            balance INTEGER NOT NULL DEFAULT 0,
            totalSpent INTEGER NOT NULL DEFAULT 0,
            createdAt TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS admins (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL COLLATE NOCASE UNIQUE,
            displayName TEXT NOT NULL,
            passwordHash TEXT NOT NULL,
            createdAt TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS categories (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT '📦',
            sortOrder INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS services (
            id TEXT PRIMARY KEY,
            categoryId TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            pricePerK INTEGER NOT NULL DEFAULT 100,
            minOrder INTEGER NOT NULL DEFAULT 100,
            maxOrder INTEGER NOT NULL DEFAULT 10000,
            avgDelivery TEXT NOT NULL DEFAULT '1-3 days',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS orders (
            id TEXT PRIMARY KEY,
            userId TEXT NOT NULL,
            serviceId TEXT NOT NULL,
            serviceName TEXT NOT NULL,
            link TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            charge INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            startCount INTEGER NOT NULL DEFAULT 0,
            remains INTEGER NOT NULL DEFAULT 0,
            adminNote TEXT NOT NULL DEFAULT '',
            createdAt TEXT NOT NULL,
            updatedAt TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS transactions (
            id TEXT PRIMARY KEY,
            userId TEXT NOT NULL,
            type TEXT NOT NULL,
            amount INTEGER NOT NULL,
            description TEXT NOT NULL,
            refId TEXT NOT NULL DEFAULT '',
            createdAt TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS deposit_requests (
            id TEXT PRIMARY KEY,
            userId TEXT NOT NULL,
            amount INTEGER NOT NULL,
            transactionId TEXT NOT NULL,
            utrNote TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            adminNote TEXT NOT NULL DEFAULT '',
            createdAt TEXT NOT NULL,
            updatedAt TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS tokens (
            hash TEXT PRIMARY KEY,
            role TEXT NOT NULL,
            subjectId TEXT NOT NULL,
            issuedAt TEXT NOT NULL,
            expiresAt TEXT NOT NULL
        );
    ");

    // Seed categories & services only once
    $count = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($count === 0) {
        $cats = [
            ['CAT1','Instagram','📸',1],['CAT2','YouTube','▶️',2],
            ['CAT3','Facebook','👍',3],['CAT4','Twitter / X','🐦',4],
            ['CAT5','TikTok','🎵',5],['CAT6','Telegram','✈️',6],
        ];
        $s = $pdo->prepare("INSERT INTO categories (id,name,icon,sortOrder) VALUES (?,?,?,?)");
        foreach ($cats as $c) $s->execute($c);

        $svcs = [
            ['SVC001','CAT1','Instagram Followers – Real Looking','High retention, mixed profiles',         80, 100,50000,'1-3 days'],
            ['SVC002','CAT1','Instagram Likes – Fast','Auto-refill, instant start',                        20, 100,100000,'0-1 hour'],
            ['SVC003','CAT1','Instagram Views – Reel','Reel + post views combined',                        10, 500,500000,'0-2 hours'],
            ['SVC004','CAT1','Instagram Story Views','Story views, fast delivery',                         15, 100,50000,'0-1 hour'],
            ['SVC005','CAT1','Instagram Comments – Custom','Real-looking custom comments',                150, 10,1000,'1-24 hours'],
            ['SVC006','CAT2','YouTube Views – High Retention','Watch time counted, HQ traffic',            50, 500,1000000,'1-5 days'],
            ['SVC007','CAT2','YouTube Subscribers','Slow drip, stable, non-drop',                        200, 100,10000,'3-7 days'],
            ['SVC008','CAT2','YouTube Likes','Fast delivery, mixed accounts',                              30, 100,50000,'0-6 hours'],
            ['SVC009','CAT3','Facebook Page Likes','Real-looking, worldwide',                              60, 100,50000,'1-3 days'],
            ['SVC010','CAT3','Facebook Post Likes','Instant start',                                        25, 100,20000,'0-1 hour'],
            ['SVC011','CAT3','Facebook Video Views','3-second views, fast',                                12, 1000,500000,'0-2 hours'],
            ['SVC012','CAT4','Twitter Followers','Mixed quality, drip feed',                              100, 100,20000,'1-5 days'],
            ['SVC013','CAT4','Twitter Likes','Fast real-looking likes',                                    30, 100,50000,'0-1 hour'],
            ['SVC014','CAT4','Twitter Retweets','Boost reach instantly',                                   40, 100,10000,'0-6 hours'],
            ['SVC015','CAT5','TikTok Followers','Stable, real profiles',                                   90, 100,50000,'1-3 days'],
            ['SVC016','CAT5','TikTok Views','Viral push, instant',                                         8, 1000,1000000,'0-1 hour'],
            ['SVC017','CAT5','TikTok Likes','Fast drip-feed likes',                                       18, 100,100000,'0-2 hours'],
            ['SVC018','CAT5','TikTok Shares','Boost virality',                                             50, 100,10000,'0-6 hours'],
            ['SVC019','CAT6','Telegram Members','Real-looking channel members',                            70, 100,50000,'1-3 days'],
            ['SVC020','CAT6','Telegram Post Views','Auto views on all posts',                              10, 1000,500000,'0-1 hour'],
        ];
        $s2 = $pdo->prepare("INSERT INTO services (id,categoryId,name,description,pricePerK,minOrder,maxOrder,avgDelivery) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($svcs as $sv) $s2->execute($sv);
    }

    // Default settings
    $defaults = [
        'upiId'        => 'socialgrow@upi',
        'qrBase64'     => '',
        'siteName'     => 'SocialGrow',
        'minDeposit'   => '50',
        'welcomeBonus' => '0',
        'notice'       => '',
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
}

function db() { return openDb(); }

function cfg($key, $def = '') {
    $r = db()->prepare("SELECT value FROM settings WHERE key=?");
    $r->execute([$key]);
    $row = $r->fetch();
    return $row ? (string)$row['value'] : $def;
}

// ── Auth ───────────────────────────────────────────────────────────────────
function issueToken($role, $subjectId) {
    $plain = bin2hex(random_bytes(32));
    $hash  = tokenHash($plain);
    db()->prepare("DELETE FROM tokens WHERE expiresAt < ?")->execute([nowIso()]);
    db()->prepare("INSERT OR REPLACE INTO tokens (hash,role,subjectId,issuedAt,expiresAt) VALUES (?,?,?,?,?)")
       ->execute([$hash, $role, $subjectId, nowIso(), gmdate('c', time() + 86400)]);
    return $plain;
}

function getBearer() {
    $h = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $all = getallheaders();
        $h = isset($all['Authorization']) ? $all['Authorization']
           : (isset($all['authorization']) ? $all['authorization'] : '');
    } elseif (function_exists('apache_request_headers')) {
        $all = apache_request_headers();
        $h = isset($all['Authorization']) ? $all['Authorization']
           : (isset($all['authorization']) ? $all['authorization'] : '');
    }
    if (preg_match('/Bearer\s+(.+)/i', (string)$h, $m)) return trim($m[1]);
    return null;
}

function resolveAuth() {
    $b = getBearer();
    if (!$b) return null;
    $stmt = db()->prepare("SELECT * FROM tokens WHERE hash=? AND expiresAt>?");
    $stmt->execute([tokenHash($b), nowIso()]);
    $tok = $stmt->fetch();
    if (!$tok) return null;
    $table = ($tok['role'] === 'admin') ? 'admins' : 'users';
    $s2 = db()->prepare("SELECT * FROM {$table} WHERE id=?");
    $s2->execute([$tok['subjectId']]);
    $rec = $s2->fetch();
    if (!$rec) return null;
    return ['role' => $tok['role'], 'record' => $rec];
}

function requireAuth() {
    $a = resolveAuth();
    if (!$a) respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    return $a;
}

function requireAdmin() {
    $a = requireAuth();
    if ($a['role'] !== 'admin') respond(['ok' => false, 'error' => 'Forbidden'], 403);
    return $a;
}

function safeUser($u) {
    return [
        'id'         => $u['id'],
        'username'   => $u['username'],
        'email'      => $u['email'],
        'balance'    => (int)$u['balance'],
        'totalSpent' => (int)$u['totalSpent'],
        'createdAt'  => $u['createdAt'],
    ];
}

function safeAdmin($a) {
    return [
        'id'          => $a['id'],
        'username'    => $a['username'],
        'displayName' => $a['displayName'],
        'createdAt'   => $a['createdAt'],
    ];
}

// ── Router ─────────────────────────────────────────────────────────────────
if (!isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo getHtml();
    exit;
}

// All API responses from here
$action = (string)($_GET['action'] ?? '');
$method = (string)$_SERVER['REQUEST_METHOD'];

try {
    $db = db();

    // ── boot ──────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'boot') {
        $cats = $db->query("SELECT * FROM categories ORDER BY sortOrder")->fetchAll();
        $svcs = $db->query("SELECT * FROM services WHERE active=1 ORDER BY categoryId,id")->fetchAll();
        $settings = [];
        foreach ($db->query("SELECT key,value FROM settings")->fetchAll() as $r) {
            $settings[$r['key']] = $r['value'];
        }
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        respond(['ok' => true, 'categories' => $cats, 'services' => $svcs,
                 'settings' => $settings, 'adminCount' => $adminCount]);
    }

    // ── register ──────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'register') {
        $in   = getJson();
        $user = requireString($in, 'username', 3);
        $em   = requireString($in, 'email', 5);
        $pw   = requireString($in, 'password', 6);
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) respond(['ok' => false, 'error' => 'Invalid email'], 400);
        $chk = $db->prepare("SELECT 1 FROM users WHERE username=? COLLATE NOCASE OR email=?");
        $chk->execute([$user, $em]);
        if ($chk->fetch()) respond(['ok' => false, 'error' => 'Username or email already exists'], 409);
        $uid   = 'U' . nextId();
        $bonus = max(0, (int)cfg('welcomeBonus', '0'));  // stored in paise
        $db->prepare("INSERT INTO users (id,username,email,passwordHash,balance,createdAt) VALUES (?,?,?,?,?,?)")
           ->execute([$uid, $user, $em, password_hash($pw, PASSWORD_DEFAULT), $bonus, nowIso()]);
        if ($bonus > 0) {
            $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,createdAt) VALUES (?,?,?,?,?,?)")
               ->execute(['T' . nextId(), $uid, 'credit', $bonus, 'Welcome bonus', nowIso()]);
        }
        respond(['ok' => true]);
    }

    // ── login ─────────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'login') {
        $in   = getJson();
        $user = requireString($in, 'username', 1);
        $pw   = requireString($in, 'password', 1);
        $stmt = $db->prepare("SELECT * FROM users WHERE username=? COLLATE NOCASE");
        $stmt->execute([$user]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($pw, $u['passwordHash'])) {
            respond(['ok' => false, 'error' => 'Invalid username or password'], 401);
        }
        respond(['ok' => true, 'token' => issueToken('user', $u['id']), 'user' => safeUser($u)]);
    }

    // ── setup_admin ───────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'setup_admin') {
        if ((int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0) {
            respond(['ok' => false, 'error' => 'Admin already exists'], 409);
        }
        $in = getJson();
        $dn = requireString($in, 'displayName', 2);
        $un = requireString($in, 'username', 3);
        $pw = requireString($in, 'password', 8);
        $db->prepare("INSERT INTO admins (id,username,displayName,passwordHash,createdAt) VALUES (?,?,?,?,?)")
           ->execute(['A' . nextId(), $un, $dn, password_hash($pw, PASSWORD_DEFAULT), nowIso()]);
        respond(['ok' => true]);
    }

    // ── login_admin ───────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'login_admin') {
        $in = getJson();
        $un = requireString($in, 'username', 1);
        $pw = requireString($in, 'password', 1);
        $stmt = $db->prepare("SELECT * FROM admins WHERE username=? COLLATE NOCASE");
        $stmt->execute([$un]);
        $a = $stmt->fetch();
        if (!$a || !password_verify($pw, $a['passwordHash'])) {
            respond(['ok' => false, 'error' => 'Invalid admin credentials'], 401);
        }
        respond(['ok' => true, 'token' => issueToken('admin', $a['id']), 'admin' => safeAdmin($a)]);
    }

    // ── me ────────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'me') {
        $auth = requireAuth();
        $out  = ($auth['role'] === 'admin') ? safeAdmin($auth['record']) : safeUser($auth['record']);
        $out['role'] = $auth['role'];
        respond(['ok' => true, 'profile' => $out]);
    }

    // ── place_order ───────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'place_order') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User only'], 403);
        $in   = getJson();
        $sid  = requireString($in, 'serviceId', 1);
        $link = requireString($in, 'link', 5);
        $qty  = max(1, (int)($in['quantity'] ?? 0));
        $stmt = $db->prepare("SELECT * FROM services WHERE id=? AND active=1");
        $stmt->execute([$sid]);
        $svc = $stmt->fetch();
        if (!$svc) respond(['ok' => false, 'error' => 'Service not found'], 404);
        if ($qty < (int)$svc['minOrder']) respond(['ok' => false, 'error' => 'Minimum order: ' . $svc['minOrder']], 400);
        if ($qty > (int)$svc['maxOrder']) respond(['ok' => false, 'error' => 'Maximum order: ' . $svc['maxOrder']], 400);
        $charge = (int)ceil($qty * $svc['pricePerK'] / 1000);
        $user   = $auth['record'];
        if ((int)$user['balance'] < $charge) {
            respond(['ok' => false, 'error' => 'Insufficient balance. Please add funds.'], 402);
        }
        $oid = 'ORD' . nextId();
        $db->beginTransaction();
        $db->prepare("UPDATE users SET balance=balance-?, totalSpent=totalSpent+? WHERE id=?")
           ->execute([$charge, $charge, $user['id']]);
        $db->prepare("INSERT INTO orders (id,userId,serviceId,serviceName,link,quantity,charge,status,remains,createdAt,updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$oid, $user['id'], $sid, $svc['name'], $link, $qty, $charge, 'pending', $qty, nowIso(), nowIso()]);
        $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
           ->execute(['T' . nextId(), $user['id'], 'debit', $charge, 'Order ' . $oid, $oid, nowIso()]);
        $db->commit();
        respond(['ok' => true, 'orderId' => $oid, 'charge' => $charge]);
    }

    // ── my_orders ─────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'my_orders') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User only'], 403);
        $stmt = $db->prepare("SELECT o.*, s.avgDelivery FROM orders o LEFT JOIN services s ON o.serviceId=s.id WHERE o.userId=? ORDER BY o.createdAt DESC LIMIT 100");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok' => true, 'orders' => $stmt->fetchAll()]);
    }

    // ── my_transactions ───────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'my_transactions') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User only'], 403);
        $stmt = $db->prepare("SELECT * FROM transactions WHERE userId=? ORDER BY createdAt DESC LIMIT 100");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok' => true, 'transactions' => $stmt->fetchAll()]);
    }

    // ── deposit_request ───────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'deposit_request') {
        $auth  = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User only'], 403);
        $in    = getJson();
        $amt   = (int)($in['amount'] ?? 0);  // amount in paise from frontend
        $txnId = requireString($in, 'transactionId', 4);
        $note  = trim((string)($in['note'] ?? ''));
        $minRupees = max(1, (int)cfg('minDeposit', '50'));  // stored as rupees
        if ($amt < $minRupees * 100) {
            respond(['ok' => false, 'error' => 'Minimum deposit is Rs.' . $minRupees], 400);
        }
        $did = 'DEP' . nextId();
        $db->prepare("INSERT INTO deposit_requests (id,userId,amount,transactionId,utrNote,status,createdAt,updatedAt) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$did, $auth['record']['id'], $amt, $txnId, $note, 'pending', nowIso(), nowIso()]);
        respond(['ok' => true, 'depositId' => $did]);
    }

    // ── my_deposits ───────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'my_deposits') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User only'], 403);
        $stmt = $db->prepare("SELECT * FROM deposit_requests WHERE userId=? ORDER BY createdAt DESC LIMIT 50");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok' => true, 'deposits' => $stmt->fetchAll()]);
    }

    // ── admin_data ────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'admin_data') {
        requireAdmin();
        $users    = $db->query("SELECT id,username,email,balance,totalSpent,createdAt FROM users ORDER BY createdAt DESC")->fetchAll();
        $orders   = $db->query("SELECT o.*,u.username FROM orders o JOIN users u ON o.userId=u.id ORDER BY o.createdAt DESC LIMIT 300")->fetchAll();
        $deposits = $db->query("SELECT d.*,u.username FROM deposit_requests d JOIN users u ON d.userId=u.id ORDER BY d.createdAt DESC LIMIT 200")->fetchAll();
        $cats     = $db->query("SELECT * FROM categories ORDER BY sortOrder")->fetchAll();
        $svcs     = $db->query("SELECT * FROM services ORDER BY categoryId,id")->fetchAll();
        $settings = [];
        foreach ($db->query("SELECT key,value FROM settings")->fetchAll() as $r) {
            $settings[$r['key']] = $r['value'];
        }
        $rev = $db->query("SELECT COALESCE(SUM(charge),0) FROM orders WHERE status!='cancelled'")->fetchColumn();
        $stats = [
            'totalUsers'    => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'totalOrders'   => (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'pendingOrders' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'totalRevenue'  => (int)$rev,
            'pendingDeps'   => (int)$db->query("SELECT COUNT(*) FROM deposit_requests WHERE status='pending'")->fetchColumn(),
        ];
        respond(['ok' => true, 'users' => $users, 'orders' => $orders, 'deposits' => $deposits,
                 'categories' => $cats, 'services' => $svcs, 'settings' => $settings, 'stats' => $stats]);
    }

    // ── update_order ──────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'update_order') {
        requireAdmin();
        $in     = getJson();
        $id     = requireString($in, 'id', 1);
        $status = requireString($in, 'status', 1);
        $valid  = ['pending', 'processing', 'completed', 'partial', 'cancelled'];
        if (!in_array($status, $valid, true)) respond(['ok' => false, 'error' => 'Invalid status'], 400);
        $note = trim((string)($in['adminNote'] ?? ''));
        $sc   = (int)($in['startCount'] ?? 0);
        $rem  = (int)($in['remains'] ?? 0);
        $db->prepare("UPDATE orders SET status=?,adminNote=?,startCount=?,remains=?,updatedAt=? WHERE id=?")
           ->execute([$status, $note, $sc, $rem, nowIso(), $id]);
        if ($status === 'cancelled') {
            $ord = $db->prepare("SELECT * FROM orders WHERE id=?");
            $ord->execute([$id]);
            $o = $ord->fetch();
            if ($o) {
                $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$o['charge'], $o['userId']]);
                $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['T' . nextId(), $o['userId'], 'refund', $o['charge'], 'Refund for order ' . $id, $id, nowIso()]);
            }
        }
        respond(['ok' => true]);
    }

    // ── approve_deposit ───────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'approve_deposit') {
        requireAdmin();
        $in   = getJson();
        $id   = requireString($in, 'id', 1);
        $act  = requireString($in, 'action', 1);
        $note = trim((string)($in['note'] ?? ''));
        $dep  = $db->prepare("SELECT * FROM deposit_requests WHERE id=?");
        $dep->execute([$id]);
        $d = $dep->fetch();
        if (!$d) respond(['ok' => false, 'error' => 'Deposit not found'], 404);
        if ($d['status'] !== 'pending') respond(['ok' => false, 'error' => 'Already processed'], 400);
        if ($act === 'approve') {
            $db->beginTransaction();
            $db->prepare("UPDATE deposit_requests SET status='approved',adminNote=?,updatedAt=? WHERE id=?")->execute([$note, nowIso(), $id]);
            $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$d['amount'], $d['userId']]);
            $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
               ->execute(['T' . nextId(), $d['userId'], 'credit', $d['amount'], 'Deposit approved', $id, nowIso()]);
            $db->commit();
        } else {
            $db->prepare("UPDATE deposit_requests SET status='rejected',adminNote=?,updatedAt=? WHERE id=?")->execute([$note, nowIso(), $id]);
        }
        respond(['ok' => true]);
    }

    // ── add_funds ─────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'add_funds') {
        requireAdmin();
        $in  = getJson();
        $uid = requireString($in, 'userId', 1);
        $amt = (int)($in['amount'] ?? 0);
        if ($amt <= 0) respond(['ok' => false, 'error' => 'Invalid amount'], 400);
        $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$amt, $uid]);
        $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,createdAt) VALUES (?,?,?,?,?,?)")
           ->execute(['T' . nextId(), $uid, 'credit', $amt, 'Manual credit by admin', nowIso()]);
        respond(['ok' => true]);
    }

    // ── save_service ──────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'save_service') {
        requireAdmin();
        $in  = getJson();
        $sid = trim((string)($in['id'] ?? ''));
        $cat = requireString($in, 'categoryId', 1);
        $nm  = requireString($in, 'name', 3);
        $dsc = trim((string)($in['description'] ?? ''));
        $ppk = max(1, (int)($in['pricePerK'] ?? 100));
        $mn  = max(1, (int)($in['minOrder'] ?? 100));
        $mx  = max($mn, (int)($in['maxOrder'] ?? 10000));
        $del = trim((string)($in['avgDelivery'] ?? '1-3 days'));
        if (!$del) $del = '1-3 days';
        $act = isset($in['active']) ? (int)(bool)$in['active'] : 1;
        if ($sid) {
            $db->prepare("UPDATE services SET categoryId=?,name=?,description=?,pricePerK=?,minOrder=?,maxOrder=?,avgDelivery=?,active=? WHERE id=?")
               ->execute([$cat, $nm, $dsc, $ppk, $mn, $mx, $del, $act, $sid]);
        } else {
            $db->prepare("INSERT INTO services (id,categoryId,name,description,pricePerK,minOrder,maxOrder,avgDelivery,active) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute(['SVC' . nextId(), $cat, $nm, $dsc, $ppk, $mn, $mx, $del, $act]);
        }
        respond(['ok' => true]);
    }

    // ── delete_service ────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'delete_service') {
        requireAdmin();
        $in = getJson();
        $id = requireString($in, 'id', 1);
        $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }

    // ── save_category ─────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'save_category') {
        requireAdmin();
        $in   = getJson();
        $cid  = trim((string)($in['id'] ?? ''));
        $nm   = requireString($in, 'name', 2);
        $icon = trim((string)($in['icon'] ?? '📦'));
        if (!$icon) $icon = '📦';
        $sort = (int)($in['sortOrder'] ?? 0);
        if ($cid) {
            $db->prepare("UPDATE categories SET name=?,icon=?,sortOrder=? WHERE id=?")->execute([$nm, $icon, $sort, $cid]);
        } else {
            $db->prepare("INSERT INTO categories (id,name,icon,sortOrder) VALUES (?,?,?,?)")
               ->execute(['CAT' . nextId(), $nm, $icon, $sort]);
        }
        respond(['ok' => true]);
    }

    // ── save_settings ─────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'save_settings') {
        requireAdmin();
        $in   = getJson();
        $keys = ['upiId', 'qrBase64', 'siteName', 'minDeposit', 'welcomeBonus', 'notice'];
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)");
        foreach ($keys as $k) {
            if (array_key_exists($k, $in)) {
                $stmt->execute([$k, trim((string)$in[$k])]);
            }
        }
        respond(['ok' => true]);
    }

    // ── delete_user_admin ─────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'delete_user_admin') {
        requireAdmin();
        $in = getJson();
        $id = requireString($in, 'id', 1);
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }

    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);

} catch (Exception $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

// ── HTML ───────────────────────────────────────────────────────────────────
function getHtml() {
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title>SocialGrow</title>
<meta http-equiv="Cache-Control" content="no-cache,no-store,must-revalidate">
<meta name="theme-color" content="#050810">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#050810;--surface:#0c1220;--surface2:#111827;--border:rgba(255,255,255,0.06);
  --border2:rgba(255,255,255,0.12);--text:#f0f4ff;--muted:#6b7fa0;--subtle:#2a3a56;
  --pink:#ff2d7e;--cyan:#00e5d4;--violet:#8b5cf6;--amber:#f59e0b;
  --green:#10b981;--red:#ef4444;--blue:#3b82f6;
  --grad:linear-gradient(135deg,var(--pink),var(--violet));
  --grad2:linear-gradient(135deg,var(--cyan),var(--blue));
  --r:10px;--r-lg:16px;--r-xl:22px;
  --font-head:'Clash Display',sans-serif;--font-body:'Cabinet Grotesk',sans-serif;
  --nav-h:64px;--bar-h:52px;--safe:env(safe-area-inset-bottom,0px);
}
html{-webkit-tap-highlight-color:transparent;scroll-behavior:smooth}
body{min-height:100vh;font-family:var(--font-body);background:var(--bg);color:var(--text);overflow-x:hidden;font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
button,input,select,textarea{font:inherit;outline:none}
button{border:0;cursor:pointer;background:none;color:inherit}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
.hidden{display:none!important}
input,textarea,select{width:100%;padding:12px 15px;border-radius:var(--r);border:1px solid var(--border2);background:rgba(0,0,0,0.3);color:var(--text);-webkit-appearance:none;font-size:0.92rem;transition:border-color .2s,box-shadow .2s}
input:focus,textarea:focus,select:focus{border-color:var(--pink);box-shadow:0 0 0 3px rgba(255,45,126,.1)}
select option{background:var(--surface2)}
textarea{min-height:80px;resize:vertical}
#app{max-width:520px;margin:0 auto;min-height:100vh;display:flex;flex-direction:column;background:var(--bg)}
#topbar{height:var(--bar-h);display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid var(--border);background:rgba(5,8,16,.92);backdrop-filter:blur(16px);position:sticky;top:0;z-index:50;gap:10px}
.logo{display:flex;align-items:center;gap:8px}
.logo-mark{width:30px;height:30px;border-radius:8px;background:var(--grad);display:grid;place-items:center;font-size:15px;flex-shrink:0}
.logo-text{font-family:var(--font-head);font-weight:700;font-size:1rem;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.topbar-right{display:flex;align-items:center;gap:8px}
.balance-chip{background:rgba(0,229,212,.1);border:1px solid rgba(0,229,212,.2);color:var(--cyan);padding:5px 12px;border-radius:999px;font-family:var(--font-head);font-weight:700;font-size:.82rem;white-space:nowrap}
.btn-sm{padding:7px 12px;font-size:.78rem;border-radius:8px;font-weight:700;transition:all .18s}
.btn-ghost{background:rgba(255,255,255,.06);color:var(--text);border:1px solid var(--border2)}
.btn-grad{background:var(--grad);color:#fff;box-shadow:0 6px 20px rgba(255,45,126,.25)}
.btn-grad:hover{box-shadow:0 10px 28px rgba(255,45,126,.4);transform:translateY(-1px)}
.btn-cyan{background:var(--grad2);color:#000;font-weight:800}
.btn-success{background:linear-gradient(135deg,var(--green),#047857);color:#fff}
.btn-danger{background:linear-gradient(135deg,var(--red),#991b1b);color:#fff}
.btn-warn{background:linear-gradient(135deg,var(--amber),#b45309);color:#fff}
.btn-xs{padding:5px 10px;font-size:.75rem;border-radius:6px;font-weight:700}
.btn-full{width:100%;display:flex;align-items:center;justify-content:center;gap:6px;padding:13px;font-size:.9rem;border-radius:var(--r);font-weight:700}
#content{flex:1;overflow-y:auto;padding-bottom:calc(var(--nav-h) + var(--safe) + 16px);-webkit-overflow-scrolling:touch}
.page{padding:16px;animation:fadeUp .2s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
#bottomNav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:520px;height:calc(var(--nav-h) + var(--safe));padding-bottom:var(--safe);background:rgba(12,18,32,.97);backdrop-filter:blur(20px);border-top:1px solid var(--border);display:flex;z-index:60}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;color:var(--muted);font-size:.68rem;font-weight:600;transition:color .18s;position:relative;padding:4px 2px}
.nav-icon{font-size:1.25rem}
.nav-item.active{color:var(--pink)}
.nav-item.active::after{content:'';position:absolute;top:-1px;left:50%;transform:translateX(-50%);width:24px;height:3px;background:var(--pink);border-radius:0 0 4px 4px}
.section-title{font-family:var(--font-head);font-size:1.1rem;font-weight:700;margin-bottom:12px}
.cat-scroll{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:16px;scrollbar-width:none}
.cat-scroll::-webkit-scrollbar{display:none}
.cat-pill{flex-shrink:0;padding:8px 16px;border-radius:999px;font-size:.82rem;font-weight:700;color:var(--muted);border:1px solid var(--border);transition:all .18s;white-space:nowrap;cursor:pointer}
.cat-pill.active{background:rgba(255,45,126,.12);color:var(--pink);border-color:rgba(255,45,126,.35)}
.svc-grid{display:grid;gap:10px}
.svc-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;cursor:pointer;transition:all .2s}
.svc-card:active{transform:scale(.98);border-color:var(--pink)}
.svc-cat{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pink)}
.svc-name{font-family:var(--font-head);font-size:.95rem;font-weight:700;line-height:1.3;margin-top:2px}
.svc-desc{font-size:.8rem;color:var(--muted);line-height:1.5;margin-top:4px}
.svc-footer{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:8px}
.svc-price{font-family:var(--font-head);font-size:1rem;font-weight:700;color:var(--cyan)}
.svc-meta{font-size:.72rem;color:var(--muted)}
#overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:70;opacity:0;pointer-events:none;transition:opacity .25s}
#overlay.show{opacity:1;pointer-events:auto}
#sheet{position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(100%);width:100%;max-width:520px;max-height:88vh;background:var(--surface);border-radius:var(--r-xl) var(--r-xl) 0 0;z-index:75;transition:transform .3s cubic-bezier(.32,.72,0,1);display:flex;flex-direction:column}
#sheet.show{transform:translateX(-50%) translateY(0)}
.sh-handle{width:32px;height:4px;background:var(--subtle);border-radius:2px;margin:10px auto 6px;flex-shrink:0}
.sh-head{padding:8px 20px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.sh-title{font-family:var(--font-head);font-size:1.05rem;font-weight:700}
.sh-body{flex:1;overflow-y:auto;padding:16px 20px 28px;-webkit-overflow-scrolling:touch}
.qty-row{display:flex;align-items:center;gap:10px;background:var(--surface2);border-radius:var(--r);padding:6px;border:1px solid var(--border)}
.qty-row button{width:40px;height:40px;border-radius:var(--r);background:rgba(255,255,255,.05);font-size:1.3rem;font-weight:700;display:grid;place-items:center;transition:background .15s}
.qty-row button:active{background:var(--pink);color:#fff}
.qty-row input{flex:1;text-align:center;font-size:1.1rem;font-weight:700;border:0;background:transparent;padding:8px;min-width:0}
.qty-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.qty-chip{padding:6px 14px;border-radius:999px;font-size:.76rem;font-weight:700;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--muted);transition:all .15s}
.qty-chip:active{background:rgba(0,229,212,.1);border-color:var(--cyan);color:var(--cyan)}
.charge-box{background:rgba(0,229,212,.06);border:1px solid rgba(0,229,212,.15);border-radius:var(--r);padding:14px;display:grid;gap:6px;margin-top:14px}
.charge-row{display:flex;justify-content:space-between;font-size:.85rem}
.charge-total{font-family:var(--font-head);font-size:1.15rem;font-weight:800;color:var(--cyan)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}
.card2{background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:.72rem;font-weight:700}
.b-pending{background:rgba(245,158,11,.15);color:#fbbf24;border:1px solid rgba(245,158,11,.3)}
.b-processing{background:rgba(59,130,246,.15);color:#60a5fa;border:1px solid rgba(59,130,246,.3)}
.b-completed{background:rgba(16,185,129,.15);color:#34d399;border:1px solid rgba(16,185,129,.3)}
.b-partial{background:rgba(139,92,246,.15);color:#a78bfa;border:1px solid rgba(139,92,246,.3)}
.b-cancelled{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.b-approved{background:rgba(16,185,129,.15);color:#34d399;border:1px solid rgba(16,185,129,.3)}
.b-rejected{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.notice{padding:12px 14px;border-radius:var(--r);font-size:.84rem;line-height:1.6}
.n-info{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#93c5fd}
.n-warn{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:#fcd34d}
.n-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);color:#6ee7b7}
.n-danger{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#fca5a5}
.mono{font-family:monospace;background:rgba(255,255,255,.05);padding:2px 7px;border-radius:4px;font-size:.8rem;word-break:break-all}
.meta{color:var(--muted);font-size:.8rem;line-height:1.5}
.pay-block{background:rgba(0,229,212,.04);border:1px solid rgba(0,229,212,.12);border-radius:var(--r-lg);padding:16px;display:grid;gap:10px}
.upi-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.upi-val{font-family:monospace;background:rgba(0,229,212,.08);border:1px solid rgba(0,229,212,.15);padding:8px 14px;border-radius:var(--r);flex:1;min-width:0;word-break:break-all;font-size:.88rem}
.qr-wrap{display:flex;justify-content:center}
.qr-wrap img{max-width:150px;border-radius:var(--r)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.stat-card{border-radius:var(--r-lg);padding:14px;border:1px solid var(--border);background:var(--surface)}
.stat-icon{font-size:1.4rem;margin-bottom:6px}
.stat-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:2px}
.stat-value{font-family:var(--font-head);font-size:1.5rem;font-weight:700}
.tbl-wrap{overflow-x:auto;border-radius:var(--r);border:1px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:.8rem}
th{padding:8px 10px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.03);vertical-align:middle}
tr:last-child td{border-bottom:0}
.subtabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;margin-bottom:14px;scrollbar-width:none}
.subtabs::-webkit-scrollbar{display:none}
.subtab{flex-shrink:0;padding:7px 14px;border-radius:999px;font-size:.78rem;font-weight:700;color:var(--muted);border:1px solid var(--border);transition:all .15s;cursor:pointer}
.subtab.active{background:rgba(255,45,126,.12);color:var(--pink);border-color:rgba(255,45,126,.3)}
.list{display:grid;gap:8px}
.li{border-radius:var(--r);border:1px solid var(--border);background:rgba(255,255,255,.02);padding:12px 14px}
.li-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.li-head strong{font-weight:700;font-size:.9rem}
.btn-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px}
.form-stack{display:grid;gap:12px}
.field{display:grid;gap:4px}
.field label{font-size:.78rem;font-weight:700;color:var(--muted)}
.fn{font-size:.76rem;color:var(--muted);text-align:center}
.divider{height:1px;background:var(--border);margin:12px 0}
.empty{text-align:center;padding:32px 16px;color:var(--muted)}
.empty .ei{font-size:2.5rem;margin-bottom:10px}
.auth-wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;gap:20px}
.auth-hero{text-align:center}
.auth-hero h1{font-family:var(--font-head);font-size:2.4rem;line-height:1.05;font-weight:700}
.auth-hero h1 em{font-style:normal;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.auth-hero p{color:var(--muted);font-size:.9rem;margin-top:8px}
.auth-card{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:24px}
.auth-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;background:rgba(0,0,0,.3);border-radius:var(--r);padding:4px;margin-bottom:18px;border:1px solid var(--border)}
.auth-tab{padding:8px;border-radius:7px;font-size:.78rem;font-weight:700;color:var(--muted);transition:all .15s}
.auth-tab.active{background:var(--grad);color:#fff}
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
#toastWrap{position:fixed;bottom:calc(var(--nav-h) + var(--safe) + 12px);right:12px;z-index:200;display:grid;gap:8px;pointer-events:none}
.toast{padding:10px 16px;border-radius:var(--r);font-weight:700;font-size:.84rem;box-shadow:0 12px 32px rgba(0,0,0,.45);animation:toastIn .22s ease;max-width:300px;color:#fff}
@media(min-width:521px){#app{border-left:1px solid var(--border);border-right:1px solid var(--border)}}
</style>
</head>
<body>
<div id="app">
  <header id="topbar">
    <div class="logo">
      <div class="logo-mark">🚀</div>
      <span class="logo-text" id="siteName">SocialGrow</span>
    </div>
    <div class="topbar-right">
      <span id="balChip" class="balance-chip hidden"></span>
      <button class="btn-sm btn-ghost hidden" id="logoutBtn" onclick="doLogout()">Sign out</button>
    </div>
  </header>

  <main id="content"></main>

  <nav id="bottomNav" style="display:none">
    <button class="nav-item active" data-v="home"    onclick="nav('home')"><span class="nav-icon">🏠</span>Home</button>
    <button class="nav-item"        data-v="wallet"  onclick="nav('wallet')"><span class="nav-icon">💰</span>Wallet</button>
    <button class="nav-item"        data-v="orders"  onclick="nav('orders')"><span class="nav-icon">📦</span>Orders</button>
    <button class="nav-item"        data-v="profile" onclick="nav('profile')"><span class="nav-icon">👤</span>Profile</button>
    <button class="nav-item hidden" data-v="admin"   id="navAdmin" onclick="nav('admin')"><span class="nav-icon">⚙️</span>Admin</button>
  </nav>

  <div id="overlay" onclick="closeSheet()"></div>
  <div id="sheet">
    <div class="sh-handle"></div>
    <div class="sh-head">
      <span class="sh-title" id="shTitle"></span>
      <button class="btn-sm btn-ghost" onclick="closeSheet()">✕</button>
    </div>
    <div class="sh-body" id="shBody"></div>
  </div>
</div>
<div id="toastWrap"></div>

<script>
'use strict';

// ── State ────────────────────────────────────────────────────────────────
var A = '?action=';
var SK = 'sg_v3';
var S = { token:'', role:'', profile:null, boot:null, view:'home', sub:'overview',
          adminMode:false, adminData:null, cat:null, svc:null, qrPend:null };

// ── Utils ────────────────────────────────────────────────────────────────
function esc(v){ return String(v||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];}); }
function dt(s){ return s ? new Date(s).toLocaleString('en-IN',{dateStyle:'medium',timeStyle:'short'}) : ''; }
function paise(v){ return '&#8377;'+(Number(v||0)/100).toFixed(2); }

function toast(msg,type){
  var colors={info:'#3b82f6',success:'#10b981',danger:'#ef4444',warn:'#f59e0b'};
  var w=document.getElementById('toastWrap');
  var t=document.createElement('div');
  t.className='toast';
  t.style.background=colors[type]||colors.info;
  t.innerHTML=esc(msg);
  w.appendChild(t);
  setTimeout(function(){if(t.parentNode)t.remove();},3200);
}

function api(action,opts){
  opts=opts||{};
  var method=opts.method||'POST';
  var headers={'Cache-Control':'no-store'};
  if(S.token) headers['Authorization']='Bearer '+S.token;
  var body;
  if(opts.body!==undefined){headers['Content-Type']='application/json';body=JSON.stringify(opts.body);}
  return fetch(A+encodeURIComponent(action),{method:method,headers:headers,body:body,cache:'no-store'})
    .then(function(r){
      return r.json().then(function(p){
        if(!p.ok) throw new Error(p.error||'Request failed ('+r.status+')');
        return p;
      });
    })
    .catch(function(e){
      if(e && e.message) throw e;
      throw new Error('Network error');
    });
}

function compressImg(file,maxW,q){
  maxW=maxW||500; q=q||0.85;
  return new Promise(function(res,rej){
    var rd=new FileReader();
    rd.onload=function(e){
      var img=new Image();
      img.onload=function(){
        var w=img.width,h=img.height;
        if(w>maxW){h=Math.round(h*maxW/w);w=maxW;}
        var c=document.createElement('canvas');
        c.width=w;c.height=h;
        c.getContext('2d').drawImage(img,0,0,w,h);
        res(c.toDataURL('image/jpeg',q));
      };
      img.onerror=rej;
      img.src=e.target.result;
    };
    rd.onerror=rej;
    rd.readAsDataURL(file);
  });
}

// ── Session ──────────────────────────────────────────────────────────────
function saveSess(){ try{sessionStorage.setItem(SK,JSON.stringify({token:S.token,role:S.role}));}catch(e){} }
function clearSess(){
  try{sessionStorage.removeItem(SK);}catch(e){}
  S.token='';S.role='';S.profile=null;S.adminMode=false;S.adminData=null;
}

function restoreSess(){
  try{
    var raw=sessionStorage.getItem(SK); if(!raw) return Promise.resolve();
    var d=JSON.parse(raw); if(!d||!d.token) return Promise.resolve();
    S.token=d.token; S.role=d.role||'user';
    return api('me',{method:'GET'}).then(function(r){
      S.profile=r.profile;
      return enterApp();
    }).catch(function(){clearSess();});
  }catch(e){clearSess();return Promise.resolve();}
}

// ── Boot ─────────────────────────────────────────────────────────────────
function boot(){
  return api('boot',{method:'GET'}).then(function(r){
    S.boot=r;
    var name=(r.settings&&r.settings.siteName)||'SocialGrow';
    document.title=name+' – SMM Panel';
    document.getElementById('siteName').textContent=name;
  }).catch(function(){
    S.boot={categories:[],services:[],settings:{},adminCount:0};
    toast('Cannot reach server. Check server setup.','warn');
  });
}

// ── Nav ──────────────────────────────────────────────────────────────────
function nav(v){
  S.view=v;
  if(v==='admin') S.sub='overview';
  document.querySelectorAll('#bottomNav .nav-item').forEach(function(el){
    el.classList.toggle('active',el.getAttribute('data-v')===v);
  });
  render();
  document.getElementById('content').scrollTop=0;
}

function updateBal(){
  var chip=document.getElementById('balChip');
  if(S.role==='user'&&S.profile){
    chip.classList.remove('hidden');
    chip.innerHTML=paise(S.profile.balance);
  } else {
    chip.classList.add('hidden');
  }
}

// ── Sheet ────────────────────────────────────────────────────────────────
function openSheet(title,html){
  document.getElementById('shTitle').textContent=title;
  document.getElementById('shBody').innerHTML=html;
  document.getElementById('sheet').classList.add('show');
  document.getElementById('overlay').classList.add('show');
}
function closeSheet(){
  document.getElementById('sheet').classList.remove('show');
  document.getElementById('overlay').classList.remove('show');
  S.svc=null;
}

// ── Render ───────────────────────────────────────────────────────────────
function render(){
  var c=document.getElementById('content');
  var html='';
  if(S.adminMode&&S.view==='admin'){
    html=renderAdmin();
  } else {
    if(S.view==='home')    html=renderHome();
    else if(S.view==='wallet')  html=renderWallet();
    else if(S.view==='orders')  html=renderOrders();
    else if(S.view==='profile') html=renderProfile();
    else html=renderHome();
  }
  c.innerHTML='<div class="page">'+html+'</div>';
  if(S.view==='wallet') loadDeps();
  if(S.view==='orders') loadOrders();
}

// ── Home ─────────────────────────────────────────────────────────────────
function renderHome(){
  var cats=(S.boot&&S.boot.categories)||[];
  var all=(S.boot&&S.boot.services)||[];
  var svcs=S.cat?all.filter(function(s){return s.categoryId===S.cat;}):all;
  var notice=(S.boot&&S.boot.settings&&S.boot.settings.notice)||'';
  var h='';
  if(notice) h+='<div class="notice n-info" style="margin-bottom:14px">&#128226; '+esc(notice)+'</div>';
  h+='<div class="section-title">&#128293; Services</div>';
  h+='<div class="cat-scroll">';
  h+='<button class="cat-pill'+(S.cat?'':' active')+'" onclick="setCat(null)">All &#128230;</button>';
  cats.forEach(function(c){
    h+='<button class="cat-pill'+(S.cat===c.id?' active':'')+'" onclick="setCat(\''+c.id+'\')">'+c.icon+' '+esc(c.name)+'</button>';
  });
  h+='</div><div class="svc-grid">';
  if(!svcs.length){
    h+='<div class="empty"><div class="ei">&#128237;</div><p>No services here.</p></div>';
  } else {
    svcs.forEach(function(svc){
      var cat=cats.find(function(c){return c.id===svc.categoryId;})||{};
      h+='<div class="svc-card" onclick="openSvc(\''+svc.id+'\')">'
        +'<div class="svc-cat">'+(cat.icon||'')+' '+esc(cat.name||'')+'</div>'
        +'<div class="svc-name">'+esc(svc.name)+'</div>'
        +'<div class="svc-desc">'+esc(svc.description)+'</div>'
        +'<div class="svc-footer">'
        +'<span class="svc-price">&#8377;'+(Number(svc.pricePerK)/100).toFixed(2)+'<small style="font-size:.7rem;color:var(--muted)">/1K</small></span>'
        +'<span class="svc-meta">Min '+Number(svc.minOrder).toLocaleString()+' &#183; '+esc(svc.avgDelivery)+'</span>'
        +'</div></div>';
    });
  }
  h+='</div>';
  return h;
}

function setCat(id){S.cat=id;render();}

// ── Service Sheet ─────────────────────────────────────────────────────────
function openSvc(id){
  var svc=(S.boot&&S.boot.services||[]).find(function(s){return s.id===id;}); if(!svc) return;
  S.svc=svc;
  var cats=(S.boot&&S.boot.categories)||[];
  var cat=cats.find(function(c){return c.id===svc.categoryId;})||{};
  var bal=Number(S.profile&&S.profile.balance||0);
  var ch0=Math.ceil(svc.minOrder*svc.pricePerK/1000);
  var ok=bal>=ch0;
  var qqs=[Number(svc.minOrder)];
  [1000,5000,10000,50000].forEach(function(q){if(q>svc.minOrder&&q<svc.maxOrder&&qqs.indexOf(q)<0)qqs.push(q);});
  if(qqs.indexOf(Number(svc.maxOrder))<0) qqs.push(Number(svc.maxOrder));

  var h='<div style="margin-bottom:10px"><span class="badge" style="background:rgba(255,45,126,.12);color:var(--pink);border:1px solid rgba(255,45,126,.2)">'+(cat.icon||'')+' '+esc(cat.name||'')+'</span></div>';
  h+='<div style="font-family:var(--font-head);font-size:1.15rem;font-weight:700;margin-bottom:6px">'+esc(svc.name)+'</div>';
  h+='<div class="meta" style="margin-bottom:14px">'+esc(svc.description)+'</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">'
    +'<div class="card2"><div class="meta">Price/1K</div><div style="font-family:var(--font-head);font-weight:700;color:var(--cyan)">&#8377;'+(Number(svc.pricePerK)/100).toFixed(2)+'</div></div>'
    +'<div class="card2"><div class="meta">Delivery</div><div style="font-weight:700">'+esc(svc.avgDelivery)+'</div></div>'
    +'<div class="card2"><div class="meta">Min Order</div><div style="font-weight:700">'+Number(svc.minOrder).toLocaleString()+'</div></div>'
    +'<div class="card2"><div class="meta">Max Order</div><div style="font-weight:700">'+Number(svc.maxOrder).toLocaleString()+'</div></div>'
    +'</div>';
  h+='<div class="field" style="margin-bottom:10px"><label>Your Link / Username</label><input id="bsLink" placeholder="https://instagram.com/yourprofile"></div>';
  h+='<label class="field" style="font-size:.78rem;font-weight:700;color:var(--muted);display:block;margin-bottom:4px">Quantity</label>';
  h+='<div class="qty-row"><button onclick="qtyAdj(-1)">&#8722;</button><input id="bsQty" type="number" min="'+svc.minOrder+'" max="'+svc.maxOrder+'" value="'+svc.minOrder+'" oninput="qtyCalc()"><button onclick="qtyAdj(1)">+</button></div>';
  h+='<div class="qty-chips">'+qqs.map(function(q){return '<button class="qty-chip" onclick="setQty('+q+')">'+( q>=1000?(q/1000)+'K':q)+'</button>';}).join('')+'</div>';
  h+='<div id="chBox" class="charge-box"></div>';
  h+='<button class="btn-full btn-grad" id="orderBtn" onclick="placeOrder()" style="margin-top:14px">&#128640; Place Order</button>';
  if(!ok) h+='<div class="notice n-warn" style="margin-top:8px">&#9888;&#65039; Balance '+paise(bal)+' — need more funds. <button class="btn-sm btn-cyan" onclick="closeSheet();nav(\'wallet\')">Add Funds &#8594;</button></div>';

  openSheet('Order Service',h);
  setTimeout(qtyCalc,40);
}

function setQty(q){var el=document.getElementById('bsQty');if(el){el.value=q;qtyCalc();}}
function qtyAdj(d){
  var el=document.getElementById('bsQty'); if(!el||!S.svc) return;
  var step=Math.max(1,Math.floor(S.svc.minOrder/10));
  var v=(parseInt(el.value)||S.svc.minOrder)+d*step;
  v=Math.max(S.svc.minOrder,Math.min(S.svc.maxOrder,v));
  el.value=v; qtyCalc();
}
function qtyCalc(){
  if(!S.svc) return;
  var qty=Math.max(S.svc.minOrder,parseInt(document.getElementById('bsQty').value)||S.svc.minOrder);
  var ch=Math.ceil(qty*S.svc.pricePerK/1000);
  var bal=Number(S.profile&&S.profile.balance||0);
  var ok=bal>=ch;
  var box=document.getElementById('chBox');
  if(box) box.innerHTML='<div class="charge-row"><span>Quantity</span><span>'+qty.toLocaleString()+'</span></div>'
    +'<div class="charge-row"><span>Rate</span><span>&#8377;'+(S.svc.pricePerK/100).toFixed(2)+'/1K</span></div>'
    +'<div class="charge-row"><span>Balance</span><span style="color:'+(ok?'var(--green)':'var(--red)')+'">'+paise(bal)+'</span></div>'
    +'<div class="charge-total">Total: '+paise(ch)+'</div>';
  var btn=document.getElementById('orderBtn');
  if(btn){btn.style.opacity=ok?'1':'0.55';}
}

function placeOrder(){
  if(!S.svc) return;
  var link=(document.getElementById('bsLink').value||'').trim();
  var qty=parseInt(document.getElementById('bsQty').value)||S.svc.minOrder;
  if(!link||link.length<5){toast('Enter a valid link','warn');return;}
  if(qty<S.svc.minOrder||qty>S.svc.maxOrder){toast('Quantity out of range','warn');return;}
  var btn=document.getElementById('orderBtn');
  if(btn){btn.disabled=true;btn.textContent='Placing…';}
  api('place_order',{body:{serviceId:S.svc.id,link:link,quantity:qty}}).then(function(r){
    toast('Order #'+r.orderId+' placed! &#127881;','success');
    closeSheet();
    api('me',{method:'GET'}).then(function(m){S.profile=m.profile;updateBal();}).catch(function(){});
    nav('orders');
  }).catch(function(ex){
    toast(ex.message,'danger');
    if(btn){btn.disabled=false;qtyCalc();}
  });
}

// ── Wallet ────────────────────────────────────────────────────────────────
function renderWallet(){
  var s=(S.boot&&S.boot.settings)||{};
  var minR=Number(s.minDeposit||50);
  var upi=s.upiId||'';
  var qr=s.qrBase64||'';
  var h='<div class="section-title">&#128176; My Wallet</div>';
  h+='<div class="card" style="text-align:center;margin-bottom:16px">'
    +'<div class="meta">Available Balance</div>'
    +'<div style="font-family:var(--font-head);font-size:2.4rem;font-weight:800;color:var(--cyan)">'+paise(S.profile&&S.profile.balance||0)+'</div>'
    +'<div class="meta">Total Spent: '+paise(S.profile&&S.profile.totalSpent||0)+'</div></div>';
  h+='<div class="pay-block" style="margin-bottom:16px">'
    +'<div style="font-family:var(--font-head);font-weight:700;color:var(--cyan)">&#128179; UPI Payment</div>';
  if(upi){
    h+='<div class="upi-row"><span class="upi-val">'+esc(upi)+'</span>'
      +'<button class="btn-sm btn-ghost" onclick="navigator.clipboard.writeText(\''+esc(upi)+'\').then(function(){toast(\'Copied!\',\'success\');})">Copy</button></div>';
  } else {
    h+='<div class="meta">UPI ID not configured.</div>';
  }
  if(qr) h+='<div class="qr-wrap"><img src="'+qr+'" alt="QR Code"></div>';
  h+='<div class="fn">Pay via UPI &rarr; note the UTR &rarr; submit below</div></div>';
  h+='<div class="card" style="margin-bottom:16px">'
    +'<div style="font-family:var(--font-head);font-weight:700;margin-bottom:12px">Submit Deposit</div>'
    +'<div class="form-stack">'
    +'<div class="field"><label>Amount (&#8377;) &mdash; Min &#8377;'+minR+'</label><input id="dAmt" type="number" min="'+minR+'" placeholder="e.g. 500"></div>'
    +'<div class="field"><label>UTR / Transaction ID</label><input id="dTxn" placeholder="12-digit UTR from bank"></div>'
    +'<div class="field"><label>Note (optional)</label><textarea id="dNote" placeholder="Any extra info"></textarea></div>'
    +'<button class="btn-full btn-cyan" onclick="submitDep()">Submit Deposit Request</button>'
    +'</div></div>';
  h+='<div class="section-title">Recent Deposits</div><div id="depList"><div class="notice n-info">Loading&#8230;</div></div>';
  return h;
}

function loadDeps(){
  api('my_deposits',{method:'GET'}).then(function(r){
    var el=document.getElementById('depList'); if(!el) return;
    var deps=r.deposits||[];
    el.innerHTML=deps.length
      ?'<div class="list">'+deps.map(function(d){
          return '<div class="li"><div class="li-head"><strong>'+paise(d.amount)+'</strong><span class="badge b-'+d.status+'">'+d.status+'</span></div>'
            +'<div class="meta">UTR: <span class="mono">'+esc(d.transactionId)+'</span></div>'
            +(d.adminNote?'<div class="meta">Note: '+esc(d.adminNote)+'</div>':'')
            +'<div class="meta">'+dt(d.createdAt)+'</div></div>';
        }).join('')+'</div>'
      :'<div class="empty"><div class="ei">&#128237;</div><p>No deposits yet.</p></div>';
  }).catch(function(){});
}

function submitDep(){
  var amt=parseFloat(document.getElementById('dAmt').value||'0');
  var txn=(document.getElementById('dTxn').value||'').trim();
  var note=(document.getElementById('dNote').value||'').trim();
  var minR=Number((S.boot&&S.boot.settings&&S.boot.settings.minDeposit)||50);
  if(!amt||isNaN(amt)||amt<minR){toast('Minimum deposit is &#8377;'+minR,'warn');return;}
  if(!txn||txn.length<4){toast('Enter UTR / Transaction ID','warn');return;}
  api('deposit_request',{body:{amount:Math.round(amt*100),transactionId:txn,note:note}}).then(function(){
    toast('Deposit submitted! &#9989;','success');
    document.getElementById('dAmt').value='';
    document.getElementById('dTxn').value='';
    document.getElementById('dNote').value='';
    loadDeps();
  }).catch(function(ex){toast(ex.message,'danger');});
}

// ── Orders ────────────────────────────────────────────────────────────────
function renderOrders(){
  return '<div class="section-title">&#128230; My Orders</div>'
    +'<button class="btn-sm btn-ghost" style="margin-bottom:12px" onclick="loadOrders()">&#128260; Refresh</button>'
    +'<div id="ordList"><div class="notice n-info">Loading&#8230;</div></div>';
}

function loadOrders(){
  var el=document.getElementById('ordList'); if(!el) return;
  api('my_orders',{method:'GET'}).then(function(r){
    var orders=r.orders||[];
    el.innerHTML=orders.length
      ?'<div class="list">'+orders.map(function(o){
          return '<div class="li"><div class="li-head"><strong>'+esc(o.serviceName)+'</strong><span class="badge b-'+o.status+'">'+o.status+'</span></div>'
            +'<div class="meta">#'+esc(o.id)+' &middot; Qty: '+Number(o.quantity).toLocaleString()+' &middot; '+paise(o.charge)+'</div>'
            +'<div class="meta">'+dt(o.createdAt)+'</div>'
            +(o.adminNote?'<div class="meta">Note: '+esc(o.adminNote)+'</div>':'')
            +'</div>';
        }).join('')+'</div>'
      :'<div class="empty"><div class="ei">&#128230;</div><p>No orders yet.</p><button class="btn-sm btn-grad" style="margin-top:10px" onclick="nav(\'home\')">Browse Services</button></div>';
  }).catch(function(ex){
    el.innerHTML='<div class="notice n-danger">'+esc(ex.message)+'</div>';
  });
}

// ── Profile ───────────────────────────────────────────────────────────────
function renderProfile(){
  var u=S.profile||{};
  var rows='';
  if(S.role==='admin'){
    rows='<div class="li"><div class="li-head"><strong>Display Name</strong></div><div class="meta">'+esc(u.displayName||'')+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Username</strong></div><div class="meta">@'+esc(u.username||'')+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Member Since</strong></div><div class="meta">'+dt(u.createdAt)+'</div></div>';
  } else {
    rows='<div class="li"><div class="li-head"><strong>Username</strong></div><div class="meta">@'+esc(u.username||'')+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Email</strong></div><div class="meta">'+esc(u.email||'')+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Balance</strong></div><div style="font-family:var(--font-head);font-size:1.5rem;color:var(--cyan);font-weight:700">'+paise(u.balance)+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Total Spent</strong></div><div class="meta">'+paise(u.totalSpent)+'</div></div>'
       +'<div class="li"><div class="li-head"><strong>Member Since</strong></div><div class="meta">'+dt(u.createdAt)+'</div></div>';
  }
  return '<div class="section-title">&#128100; Profile</div><div class="card"><div class="list">'+rows+'</div></div>';
}

// ── Admin ─────────────────────────────────────────────────────────────────
function renderAdmin(){
  var tabs=[
    {k:'overview',l:'&#128202; Overview'},{k:'orders',l:'&#128203; Orders'},
    {k:'deposits',l:'&#128176; Deposits'},{k:'users',l:'&#128101; Users'},
    {k:'services',l:'&#9881;&#65039; Services'},{k:'settings',l:'&#128295; Settings'},
  ];
  var h='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
    +'<span style="font-family:var(--font-head);font-weight:700">Admin Panel</span>'
    +'<button class="btn-xs btn-ghost" onclick="adminRefresh()">&#128260; Refresh</button></div>';
  h+='<div class="subtabs">'+tabs.map(function(t){
    return '<button class="subtab'+(S.sub===t.k?' active':'')+'" onclick="setSub(\''+t.k+'\')">'+t.l+'</button>';
  }).join('')+'</div>';
  if(S.sub==='overview') h+=adminOverview();
  else if(S.sub==='orders')   h+=adminOrders();
  else if(S.sub==='deposits') h+=adminDeposits();
  else if(S.sub==='users')    h+=adminUsers();
  else if(S.sub==='services') h+=adminServices();
  else if(S.sub==='settings') h+=adminSettings();
  return h;
}

function setSub(s){S.sub=s;render();}

function adminOverview(){
  var st=(S.adminData&&S.adminData.stats)||{};
  var orders=(S.adminData&&S.adminData.orders)||[];
  var h='<div class="stats-grid">'
    +'<div class="stat-card"><div class="stat-icon">&#128101;</div><div class="stat-label">Users</div><div class="stat-value">'+(st.totalUsers||0)+'</div></div>'
    +'<div class="stat-card"><div class="stat-icon">&#128230;</div><div class="stat-label">Orders</div><div class="stat-value">'+(st.totalOrders||0)+'</div></div>'
    +'<div class="stat-card"><div class="stat-icon">&#8987;</div><div class="stat-label">Pending</div><div class="stat-value" style="color:var(--amber)">'+(st.pendingOrders||0)+'</div></div>'
    +'<div class="stat-card"><div class="stat-icon">&#128176;</div><div class="stat-label">Revenue</div><div class="stat-value" style="color:var(--cyan)">&#8377;'+(Number(st.totalRevenue||0)/100).toFixed(0)+'</div></div>'
    +'<div class="stat-card"><div class="stat-icon">&#128179;</div><div class="stat-label">Pend.Deps</div><div class="stat-value" style="color:var(--pink)">'+(st.pendingDeps||0)+'</div></div>'
    +'</div>';
  h+='<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Recent Orders</div>'
    +'<div class="tbl-wrap"><table><thead><tr><th>ID</th><th>User</th><th>Service</th><th>Status</th></tr></thead><tbody>';
  h+=orders.slice(0,10).map(function(o){
    return '<tr><td><span class="mono">'+esc(o.id)+'</span></td><td>@'+esc(o.username)+'</td>'
      +'<td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(o.serviceName)+'</td>'
      +'<td><span class="badge b-'+o.status+'">'+o.status+'</span></td></tr>';
  }).join('')||'<tr><td colspan="4" style="text-align:center;color:var(--muted);padding:16px">No orders.</td></tr>';
  h+='</tbody></table></div></div>';
  return h;
}

function adminOrders(){
  var orders=(S.adminData&&S.adminData.orders)||[];
  var h='<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Orders ('+orders.length+')</div>'
    +'<div class="tbl-wrap"><table><thead><tr><th>ID</th><th>User</th><th>&#8377;</th><th>Status</th><th></th></tr></thead><tbody>';
  h+=orders.map(function(o){
    return '<tr><td><span class="mono">'+esc(o.id)+'</span></td><td>@'+esc(o.username)+'</td>'
      +'<td>'+paise(o.charge)+'</td>'
      +'<td><span class="badge b-'+o.status+'">'+o.status+'</span></td>'
      +'<td><button class="btn-xs btn-ghost" onclick="editOrder(\''+o.id+'\')">Edit</button></td></tr>';
  }).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:16px">No orders.</td></tr>';
  h+='</tbody></table></div></div>';
  return h;
}

function adminDeposits(){
  var deps=(S.adminData&&S.adminData.deposits)||[];
  var h='<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Deposits ('+deps.length+')</div>'
    +'<div class="list">';
  if(!deps.length) h+='<div class="empty"><p>No deposits.</p></div>';
  else h+=deps.map(function(d){
    var actions=d.status==='pending'
      ?'<div class="btn-row"><button class="btn-xs btn-success" onclick="procDep(\''+d.id+'\',\'approve\')">&#9989; Approve</button><button class="btn-xs btn-danger" onclick="procDep(\''+d.id+'\',\'reject\')">&#10060; Reject</button></div>'
      :'';
    return '<div class="li"><div class="li-head"><strong>'+paise(d.amount)+'</strong> <span class="meta">@'+esc(d.username)+'</span><span class="badge b-'+d.status+'">'+d.status+'</span></div>'
      +'<div class="meta">UTR: <span class="mono">'+esc(d.transactionId)+'</span></div>'
      +(d.utrNote?'<div class="meta">'+esc(d.utrNote)+'</div>':'')
      +'<div class="meta">'+dt(d.createdAt)+'</div>'+actions+'</div>';
  }).join('');
  h+='</div></div>';
  return h;
}

function adminUsers(){
  var users=(S.adminData&&S.adminData.users)||[];
  var h='<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Users ('+users.length+')</div>'
    +'<div class="tbl-wrap"><table><thead><tr><th>Username</th><th>Balance</th><th>Spent</th><th></th></tr></thead><tbody>';
  h+=users.map(function(u){
    return '<tr><td><strong>@'+esc(u.username)+'</strong><br><span class="meta">'+esc(u.email)+'</span></td>'
      +'<td style="color:var(--cyan);font-weight:700">'+paise(u.balance)+'</td>'
      +'<td>'+paise(u.totalSpent)+'</td>'
      +'<td><div class="btn-row"><button class="btn-xs btn-success" onclick="addFunds(\''+u.id+'\',\''+esc(u.username)+'\')">+&#8377;</button>'
      +'<button class="btn-xs btn-danger" onclick="delUser(\''+u.id+'\',\''+esc(u.username)+'\')">Del</button></div></td></tr>';
  }).join('')||'<tr><td colspan="4" style="text-align:center;color:var(--muted);padding:16px">No users.</td></tr>';
  h+='</tbody></table></div></div>';
  return h;
}

function adminServices(){
  var svcs=(S.adminData&&S.adminData.services)||[];
  var cats=(S.adminData&&S.adminData.categories)||[];
  var h='<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
    +'<span style="font-family:var(--font-head);font-weight:700">Services ('+svcs.length+')</span>'
    +'<button class="btn-xs btn-grad" onclick="editSvc(null)">+ Add</button></div>'
    +'<div class="list">';
  h+=svcs.map(function(s){
    return '<div class="li"><div class="li-head"><strong>'+esc(s.name)+'</strong><span class="badge '+(s.active?'b-completed':'b-cancelled')+'">'+(s.active?'Active':'Off')+'</span></div>'
      +'<div class="meta">&#8377;'+(Number(s.pricePerK)/100).toFixed(2)+'/1K &middot; Min '+s.minOrder+' &middot; Max '+s.maxOrder+'</div>'
      +'<div class="btn-row"><button class="btn-xs btn-ghost" onclick="editSvc(\''+s.id+'\')">Edit</button><button class="btn-xs btn-danger" onclick="delSvc(\''+s.id+'\')">Delete</button></div>'
      +'</div>';
  }).join('')||'<div class="empty"><p>No services.</p></div>';
  h+='</div></div>';
  h+='<div class="card" style="margin-top:12px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
    +'<span style="font-family:var(--font-head);font-weight:700">Categories</span>'
    +'<button class="btn-xs btn-ghost" onclick="editCat(null)">+ Add</button></div>'
    +'<div class="list">'+cats.map(function(c){
      return '<div class="li"><div class="li-head"><strong>'+c.icon+' '+esc(c.name)+'</strong><button class="btn-xs btn-ghost" onclick="editCat(\''+c.id+'\')">Edit</button></div></div>';
    }).join('')+'</div></div>';
  return h;
}

function adminSettings(){
  var s=(S.adminData&&S.adminData.settings)||{};
  var qr=s.qrBase64||'';
  var bonusRupees=(Number(s.welcomeBonus||0)/100).toFixed(0);
  var h='<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:12px">&#9881;&#65039; Site Settings</div>'
    +'<div class="form-stack">'
    +'<div class="field"><label>Site Name</label><input id="sSite" value="'+esc(s.siteName||'SocialGrow')+'"></div>'
    +'<div class="field"><label>UPI ID</label><input id="sUpi" value="'+esc(s.upiId||'')+'" placeholder="example@upi"></div>'
    +'<div class="field"><label>Minimum Deposit (&#8377; rupees)</label><input id="sMin" type="number" min="1" value="'+Number(s.minDeposit||50)+'"></div>'
    +'<div class="field"><label>Welcome Bonus (&#8377; rupees)</label><input id="sBonus" type="number" min="0" value="'+bonusRupees+'"></div>'
    +'<div class="field"><label>Announcement</label><textarea id="sNotice">'+esc(s.notice||'')+'</textarea></div>'
    +'<div class="field"><label>QR Code Image</label>'
    +(qr?'<img src="'+qr+'" style="max-width:120px;border-radius:8px;margin-bottom:6px"><br>':'')
    +'<input type="file" id="sQr" accept="image/*" onchange="pickQr(event)">'
    +'<img id="sQrPrev" style="max-width:120px;display:none;border-radius:8px;margin-top:6px"></div>'
    +'<button class="btn-full btn-grad" onclick="saveSettings()">&#128190; Save Settings</button>'
    +'</div></div>';
  return h;
}

// ── Admin Actions ─────────────────────────────────────────────────────────
function adminRefresh(){
  Promise.all([
    api('admin_data',{method:'GET'}).then(function(r){S.adminData=r;}),
    api('boot',{method:'GET'}).then(function(r){S.boot=r;}).catch(function(){})
  ]).then(function(){render();toast('Refreshed','success');}).catch(function(ex){toast(ex.message,'danger');});
}

function editOrder(id){
  var o=(S.adminData&&S.adminData.orders||[]).find(function(x){return x.id===id;}); if(!o) return;
  var h='<div class="notice n-info" style="margin-bottom:12px"><strong>'+esc(o.id)+'</strong><br>'+esc(o.serviceName)+'<br>@'+esc(o.username)+'</div>'
    +'<div class="form-stack">'
    +'<div class="field"><label>Status</label><select id="oSt">'
    +['pending','processing','completed','partial','cancelled'].map(function(st){
        return '<option value="'+st+'"'+(o.status===st?' selected':'')+'>'+st+'</option>';
      }).join('')
    +'</select></div>'
    +'<div class="field"><label>Start Count</label><input id="oSc" type="number" value="'+(o.startCount||0)+'"></div>'
    +'<div class="field"><label>Remains</label><input id="oRem" type="number" value="'+(o.remains||0)+'"></div>'
    +'<div class="field"><label>Admin Note</label><textarea id="oNote">'+esc(o.adminNote||'')+'</textarea></div>'
    +'<button class="btn-full btn-grad" onclick="doEditOrder(\''+id+'\')">Update Order</button></div>';
  openSheet('Edit Order',h);
}

function doEditOrder(id){
  api('update_order',{body:{id:id,status:document.getElementById('oSt').value,
    startCount:parseInt(document.getElementById('oSc').value||'0'),
    remains:parseInt(document.getElementById('oRem').value||'0'),
    adminNote:document.getElementById('oNote').value.trim()}})
  .then(function(){toast('Updated','success');closeSheet();adminRefresh();})
  .catch(function(ex){toast(ex.message,'danger');});
}

function procDep(id,action){
  var note=action==='reject'?(prompt('Rejection reason (optional):')||''):'';
  api('approve_deposit',{body:{id:id,action:action,note:note}}).then(function(){
    toast(action==='approve'?'Approved! &#9989;':'Rejected',action==='approve'?'success':'danger');
    adminRefresh();
  }).catch(function(ex){toast(ex.message,'danger');});
}

function addFunds(uid,uname){
  var h='<div class="notice n-info" style="margin-bottom:12px">Add funds to: <strong>@'+esc(uname)+'</strong></div>'
    +'<div class="form-stack">'
    +'<div class="field"><label>Amount (&#8377; rupees)</label><input id="afA" type="number" min="1" placeholder="e.g. 100"></div>'
    +'<button class="btn-full btn-success" onclick="doAddFunds(\''+uid+'\')">&#9989; Add Funds</button></div>';
  openSheet('Add Funds',h);
}

function doAddFunds(uid){
  var amt=parseFloat(document.getElementById('afA').value||'0');
  if(!amt||amt<=0){toast('Invalid amount','warn');return;}
  api('add_funds',{body:{userId:uid,amount:Math.round(amt*100)}}).then(function(){
    toast('Funds added!','success');closeSheet();adminRefresh();
  }).catch(function(ex){toast(ex.message,'danger');});
}

function delUser(id,uname){
  if(!confirm('Delete @'+uname+' permanently?')) return;
  api('delete_user_admin',{body:{id:id}}).then(function(){
    toast('Deleted','success');adminRefresh();
  }).catch(function(ex){toast(ex.message,'danger');});
}

function editSvc(id){
  var svc=id?(S.adminData&&S.adminData.services||[]).find(function(s){return s.id===id;}):null;
  var cats=(S.adminData&&S.adminData.categories)||[];
  var h='<div class="form-stack">'
    +'<div class="field"><label>Category</label><select id="svC">'+cats.map(function(c){return '<option value="'+c.id+'"'+(svc&&svc.categoryId===c.id?' selected':'')+'>'+c.icon+' '+esc(c.name)+'</option>';}).join('')+'</select></div>'
    +'<div class="field"><label>Service Name</label><input id="svN" value="'+esc(svc&&svc.name||'')+'" placeholder="e.g. Instagram Followers"></div>'
    +'<div class="field"><label>Description</label><textarea id="svD">'+esc(svc&&svc.description||'')+'</textarea></div>'
    +'<div class="field"><label>Price per 1000 (in paise — &#8377;1 = 100 paise)</label><input id="svP" type="number" min="1" value="'+(svc&&svc.pricePerK||100)+'"></div>'
    +'<div class="field"><label>Min Order</label><input id="svMn" type="number" min="1" value="'+(svc&&svc.minOrder||100)+'"></div>'
    +'<div class="field"><label>Max Order</label><input id="svMx" type="number" min="1" value="'+(svc&&svc.maxOrder||10000)+'"></div>'
    +'<div class="field"><label>Avg Delivery</label><input id="svDl" value="'+esc(svc&&svc.avgDelivery||'1-3 days')+'"></div>'
    +'<div class="field"><label>Active</label><select id="svA"><option value="1"'+(svc&&svc.active!=0?' selected':'')+'>Yes</option><option value="0"'+(svc&&svc.active==0?' selected':'')+'>No</option></select></div>'
    +'<button class="btn-full btn-grad" onclick="doEditSvc(\''+esc(id||'')+'\')">'+(svc?'&#128190; Save':'&#10133; Add Service')+'</button></div>';
  openSheet(svc?'Edit Service':'Add Service',h);
}

function doEditSvc(id){
  var nm=(document.getElementById('svN').value||'').trim();
  if(!nm||nm.length<2){toast('Name too short','warn');return;}
  api('save_service',{body:{
    id:id||undefined,
    categoryId:document.getElementById('svC').value,
    name:nm,
    description:document.getElementById('svD').value.trim(),
    pricePerK:parseInt(document.getElementById('svP').value||'100'),
    minOrder:parseInt(document.getElementById('svMn').value||'100'),
    maxOrder:parseInt(document.getElementById('svMx').value||'10000'),
    avgDelivery:document.getElementById('svDl').value.trim()||'1-3 days',
    active:parseInt(document.getElementById('svA').value)
  }}).then(function(){toast('Saved','success');closeSheet();adminRefresh();})
    .catch(function(ex){toast(ex.message,'danger');});
}

function delSvc(id){
  if(!confirm('Delete this service?')) return;
  api('delete_service',{body:{id:id}}).then(function(){toast('Deleted','success');adminRefresh();})
    .catch(function(ex){toast(ex.message,'danger');});
}

function editCat(id){
  var cat=id?(S.adminData&&S.adminData.categories||[]).find(function(c){return c.id===id;}):null;
  var h='<div class="form-stack">'
    +'<div class="field"><label>Name</label><input id="cN" value="'+esc(cat&&cat.name||'')+'" placeholder="e.g. Instagram"></div>'
    +'<div class="field"><label>Icon (emoji)</label><input id="cI" value="'+esc(cat&&cat.icon||'📦')+'"></div>'
    +'<div class="field"><label>Sort Order</label><input id="cS" type="number" value="'+(cat&&cat.sortOrder||0)+'"></div>'
    +'<button class="btn-full btn-grad" onclick="doEditCat(\''+esc(id||'')+'\')">'+(cat?'Save':'Add Category')+'</button></div>';
  openSheet(cat?'Edit Category':'Add Category',h);
}

function doEditCat(id){
  var nm=(document.getElementById('cN').value||'').trim();
  if(!nm||nm.length<1){toast('Enter a name','warn');return;}
  api('save_category',{body:{
    id:id||undefined,
    name:nm,
    icon:document.getElementById('cI').value.trim()||'📦',
    sortOrder:parseInt(document.getElementById('cS').value||'0')
  }}).then(function(){toast('Saved','success');closeSheet();adminRefresh();})
    .catch(function(ex){toast(ex.message,'danger');});
}

function pickQr(e){
  var file=e.target.files[0]; if(!file) return;
  compressImg(file,500,.85).then(function(d){
    S.qrPend=d;
    var p=document.getElementById('sQrPrev');
    if(p){p.src=d;p.style.display='block';}
    toast('QR ready — save to apply','info');
  }).catch(function(){toast('Image error','danger');});
}

function saveSettings(){
  var bonus=parseFloat(document.getElementById('sBonus').value||'0');
  var qr=S.qrPend!==null?S.qrPend:((S.adminData&&S.adminData.settings&&S.adminData.settings.qrBase64)||'');
  api('save_settings',{body:{
    siteName:document.getElementById('sSite').value.trim(),
    upiId:document.getElementById('sUpi').value.trim(),
    minDeposit:parseInt(document.getElementById('sMin').value||'50'),
    welcomeBonus:Math.round(bonus*100),
    notice:document.getElementById('sNotice').value.trim(),
    qrBase64:qr
  }}).then(function(){
    S.qrPend=null;
    toast('Settings saved! &#9989;','success');
    adminRefresh();
  }).catch(function(ex){toast(ex.message,'danger');});
}

// ── Auth ──────────────────────────────────────────────────────────────────
var authTab='login';

function renderAuth(){
  var c=document.getElementById('content');
  var noAdmin=(S.boot&&S.boot.adminCount||0)===0;
  var labels={login:'Login',register:'Register',admin:'Admin'};
  var h='<div class="auth-wrap">'
    +'<div class="auth-hero"><div style="font-size:3rem;margin-bottom:8px">&#128640;</div>'
    +'<h1>Grow Your<br><em>Social Media</em></h1>'
    +'<p>Fast SMM panel for Instagram, YouTube, TikTok &amp; more.</p></div>'
    +'<div class="auth-card">'
    +'<div class="auth-tabs">'
    +['login','register','admin'].map(function(k){
        return '<button class="auth-tab'+(authTab===k?' active':'')+'" onclick="setAuthTab(\''+k+'\')">'+labels[k]+'</button>';
      }).join('')
    +'</div>';

  if(authTab==='register'){
    h+='<div class="form-stack">'
      +'<div class="field"><label>Username</label><input id="ru" placeholder="Min 3 chars"></div>'
      +'<div class="field"><label>Email</label><input id="re" type="email" placeholder="your@email.com"></div>'
      +'<div class="field"><label>Password</label><input id="rp" type="password" placeholder="Min 6 chars"></div>'
      +'<button class="btn-full btn-grad" onclick="doRegister()">Create Free Account</button>'
      +'<p class="fn">&#127873; Bonus balance on signup!</p></div>';
  } else if(authTab==='admin'){
    h+='<div class="form-stack">'
      +'<div class="field"><label>Admin Username</label><input id="au" placeholder="Username"></div>'
      +'<div class="field"><label>Password</label><input id="ap" type="password" placeholder="Password"></div>'
      +'<button class="btn-full btn-grad" onclick="doAdminLogin()">Admin Login</button></div>';
    if(noAdmin){
      h+='<div class="divider"></div><div class="notice n-warn" style="margin-bottom:10px">&#9888;&#65039; No admin yet — create one below.</div>'
        +'<div class="form-stack">'
        +'<div class="field"><label>Display Name</label><input id="dn" placeholder="Your name"></div>'
        +'<div class="field"><label>Username</label><input id="un" placeholder="Admin username"></div>'
        +'<div class="field"><label>Password (min 8)</label><input id="pw" type="password" placeholder="Strong password"></div>'
        +'<button class="btn-full btn-warn" onclick="doSetupAdmin()">Create Admin Account</button></div>';
    }
  } else {
    h+='<div class="form-stack">'
      +'<div class="field"><label>Username</label><input id="lu" placeholder="Your username"></div>'
      +'<div class="field"><label>Password</label><input id="lp" type="password" placeholder="Your password"></div>'
      +'<button class="btn-full btn-grad" onclick="doLogin()">Login</button>'
      +'<p class="fn">New here? Switch to Register &#8593;</p></div>';
  }
  h+='</div></div>';
  c.innerHTML=h;
}

function setAuthTab(t){authTab=t;renderAuth();}

function doRegister(){
  var u=(document.getElementById('ru').value||'').trim();
  var e=(document.getElementById('re').value||'').trim();
  var p=document.getElementById('rp').value||'';
  if(u.length<3){toast('Username min 3 chars','warn');return;}
  if(!e||e.indexOf('@')<0){toast('Enter valid email','warn');return;}
  if(p.length<6){toast('Password min 6 chars','warn');return;}
  api('register',{body:{username:u,email:e,password:p}}).then(function(){
    toast('Account created! Login now &#127881;','success');
    setAuthTab('login');
  }).catch(function(ex){toast(ex.message,'danger');});
}

function doLogin(){
  var u=(document.getElementById('lu').value||'').trim();
  var p=document.getElementById('lp').value||'';
  if(!u){toast('Enter username','warn');return;}
  if(!p){toast('Enter password','warn');return;}
  api('login',{body:{username:u,password:p}}).then(function(r){
    S.token=r.token;S.role='user';S.profile=r.user;
    saveSess();
    return enterApp();
  }).then(function(){
    toast('Welcome, '+S.profile.username+'! &#128075;','success');
  }).catch(function(ex){toast(ex.message,'danger');});
}

function doAdminLogin(){
  var u=(document.getElementById('au').value||'').trim();
  var p=document.getElementById('ap').value||'';
  if(!u||!p){toast('Enter credentials','warn');return;}
  api('login_admin',{body:{username:u,password:p}}).then(function(r){
    S.token=r.token;S.role='admin';S.profile=r.admin;
    saveSess();
    return enterApp();
  }).then(function(){
    toast('Admin panel ready &#9881;&#65039;','success');
  }).catch(function(ex){toast(ex.message,'danger');});
}

function doSetupAdmin(){
  var dn=(document.getElementById('dn').value||'').trim();
  var un=(document.getElementById('un').value||'').trim();
  var pw=document.getElementById('pw').value||'';
  if(!dn||!un){toast('Fill all fields','warn');return;}
  if(pw.length<8){toast('Password min 8 chars','warn');return;}
  api('setup_admin',{body:{displayName:dn,username:un,password:pw}}).then(function(){
    toast('Admin created!','success');
    return boot();
  }).then(function(){renderAuth();})
    .catch(function(ex){toast(ex.message,'danger');});
}

// ── Enter App ─────────────────────────────────────────────────────────────
function enterApp(){
  S.adminMode=(S.role==='admin');
  S.view='home';
  document.getElementById('bottomNav').style.display='flex';
  document.getElementById('logoutBtn').classList.remove('hidden');
  document.getElementById('navAdmin').classList[S.role==='admin'?'remove':'add']('hidden');
  return Promise.all([
    api('boot',{method:'GET'}).then(function(r){
      S.boot=r;
      var name=(r.settings&&r.settings.siteName)||'SocialGrow';
      document.getElementById('siteName').textContent=name;
      document.title=name+' – SMM Panel';
    }).catch(function(){}),
    S.adminMode?api('admin_data',{method:'GET'}).then(function(r){S.adminData=r;}).catch(function(){}):Promise.resolve(),
    S.role==='user'?api('me',{method:'GET'}).then(function(r){S.profile=r.profile;}).catch(function(){}):Promise.resolve()
  ]).then(function(){
    updateBal();
    nav(S.adminMode?'admin':'home');
  });
}

function doLogout(){
  clearSess();
  document.getElementById('bottomNav').style.display='none';
  document.getElementById('logoutBtn').classList.add('hidden');
  document.getElementById('balChip').classList.add('hidden');
  closeSheet();
  renderAuth();
}

// ── Init ──────────────────────────────────────────────────────────────────
boot().then(function(){
  return restoreSess();
}).then(function(){
  if(!S.token) renderAuth();
}).catch(function(){renderAuth();});
</script>
</body>
</html>
<?php
return ob_get_clean();
}
