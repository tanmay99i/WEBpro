<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

/*
 * 🛡️ IMPORTANT for live servers (Apache):
 * If you still get 401 errors, add this to your .htaccess:
 *
 *   RewriteEngine On
 *   RewriteCond %{HTTP:Authorization} ^(.+)$
 *   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 *
 * This ensures PHP can always read the Authorization header.
 */

// ========== CORS & preflight fix ==========
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Server error: ' . $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
});

// ─── Helpers ──────────────────────────────────────────────────────────────────
function respond(array $payload, int $status = 200): never
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function nowIso(): string { return gmdate('c'); }
function nextId(): string { return strtoupper(bin2hex(random_bytes(5))); }
function tokenHash(string $t): string { return hash('sha256', $t); }
function requireString(array $input, string $key, int $min = 1): string
{
    $v = trim((string)($input[$key] ?? ''));
    if (mb_strlen($v) < $min) respond(['ok' => false, 'error' => "Invalid {$key}"], 400);
    return $v;
}
function getJson(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw || !trim($raw)) return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
    return $d;
}

// ─── Database ─────────────────────────────────────────────────────────────────
function openDb(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = __DIR__ . '/socialgrow.sqlite';
    $pdo  = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
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
            active INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY(categoryId) REFERENCES categories(id)
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
            updatedAt TEXT NOT NULL DEFAULT '',
            FOREIGN KEY(userId) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS transactions (
            id TEXT PRIMARY KEY,
            userId TEXT NOT NULL,
            type TEXT NOT NULL,
            amount INTEGER NOT NULL,
            description TEXT NOT NULL,
            refId TEXT NOT NULL DEFAULT '',
            createdAt TEXT NOT NULL,
            FOREIGN KEY(userId) REFERENCES users(id)
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
            updatedAt TEXT NOT NULL DEFAULT '',
            FOREIGN KEY(userId) REFERENCES users(id)
        );
        CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS tokens (
            hash TEXT PRIMARY KEY, role TEXT NOT NULL, subjectId TEXT NOT NULL,
            issuedAt TEXT NOT NULL, expiresAt TEXT NOT NULL
        );
    ");

    // Seed default data
    if ((int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
        $cats = [
            ['id'=>'CAT1','name'=>'Instagram','icon'=>'📸','sort'=>1],
            ['id'=>'CAT2','name'=>'YouTube','icon'=>'▶️','sort'=>2],
            ['id'=>'CAT3','name'=>'Facebook','icon'=>'👍','sort'=>3],
            ['id'=>'CAT4','name'=>'Twitter / X','icon'=>'🐦','sort'=>4],
            ['id'=>'CAT5','name'=>'TikTok','icon'=>'🎵','sort'=>5],
            ['id'=>'CAT6','name'=>'Telegram','icon'=>'✈️','sort'=>6],
        ];
        $stmt = $pdo->prepare("INSERT INTO categories (id,name,icon,sortOrder) VALUES (?,?,?,?)");
        foreach ($cats as $c) $stmt->execute([$c['id'],$c['name'],$c['icon'],$c['sort']]);

        $svcs = [
            ['SVC001','CAT1','Instagram Followers – Real Looking','High retention followers, mixed profiles',  80, 100,50000,'1-3 days'],
            ['SVC002','CAT1','Instagram Likes – Fast','Auto-refill, instant start',                         20, 100,100000,'0-1 hour'],
            ['SVC003','CAT1','Instagram Views – Reel','Reel + post views combined',                         10, 500,500000,'0-2 hours'],
            ['SVC004','CAT1','Instagram Story Views','Story views, fast delivery',                           15, 100,50000,'0-1 hour'],
            ['SVC005','CAT1','Instagram Comments – Custom','Real-looking custom comments',                  150, 10,1000,'1-24 hours'],
            ['SVC006','CAT2','YouTube Views – High Retention','Watch time counted, HQ traffic',             50, 500,1000000,'1-5 days'],
            ['SVC007','CAT2','YouTube Subscribers','Slow drip, stable, non-drop',                          200, 100,10000,'3-7 days'],
            ['SVC008','CAT2','YouTube Likes','Fast delivery, mixed accounts',                               30, 100,50000,'0-6 hours'],
            ['SVC009','CAT3','Facebook Page Likes','Real-looking, worldwide',                               60, 100,50000,'1-3 days'],
            ['SVC010','CAT3','Facebook Post Likes','Instant start',                                         25, 100,20000,'0-1 hour'],
            ['SVC011','CAT3','Facebook Video Views','3-second views, fast',                                 12, 1000,500000,'0-2 hours'],
            ['SVC012','CAT4','Twitter Followers','Mixed quality, drip feed',                               100, 100,20000,'1-5 days'],
            ['SVC013','CAT4','Twitter Likes','Fast real-looking likes',                                     30, 100,50000,'0-1 hour'],
            ['SVC014','CAT4','Twitter Retweets','Boost reach instantly',                                    40, 100,10000,'0-6 hours'],
            ['SVC015','CAT5','TikTok Followers','Stable, real profiles',                                    90, 100,50000,'1-3 days'],
            ['SVC016','CAT5','TikTok Views','Viral push, instant',                                           8, 1000,1000000,'0-1 hour'],
            ['SVC017','CAT5','TikTok Likes','Fast drip-feed likes',                                        18, 100,100000,'0-2 hours'],
            ['SVC018','CAT5','TikTok Shares','Boost virality',                                              50, 100,10000,'0-6 hours'],
            ['SVC019','CAT6','Telegram Members','Real-looking channel members',                             70, 100,50000,'1-3 days'],
            ['SVC020','CAT6','Telegram Post Views','Auto views on all posts',                               10, 1000,500000,'0-1 hour'],
        ];
        $stmt2 = $pdo->prepare("INSERT INTO services (id,categoryId,name,description,pricePerK,minOrder,maxOrder,avgDelivery) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($svcs as $s) $stmt2->execute($s);
    }

    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('upiId','socialgrow@upi')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('qrBase64','')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('siteName','SocialGrow')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('minDeposit','50')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('welcomeBonus','0')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('notice','')");
}

function db(): PDO { return openDb(); }
function cfg(string $key, string $def = ''): string
{
    $r = db()->prepare("SELECT value FROM settings WHERE key=?");
    $r->execute([$key]);
    $row = $r->fetch();
    return $row ? $row['value'] : $def;
}

// ─── Auth ─────────────────────────────────────────────────────────────────────
function issueToken(string $role, string $subjectId): string
{
    $plain = bin2hex(random_bytes(32));
    $hash  = tokenHash($plain);
    db()->prepare("DELETE FROM tokens WHERE expiresAt < ?")->execute([nowIso()]);
    db()->prepare("INSERT OR REPLACE INTO tokens (hash,role,subjectId,issuedAt,expiresAt) VALUES (?,?,?,?,?)")
        ->execute([$hash, $role, $subjectId, nowIso(), gmdate('c', time() + 86400)]);
    return $plain;
}

/**
 * 🔧 FIXED: Reads the Authorization header from every possible source
 * that Apache / Nginx / CGI might put it.
 */
function bearer(): ?string
{
    $h = '';

    // Standard CGI variable
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
    }
    // Apache's rewrite module sometimes stores it here
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    // FastCGI may not set HTTP_AUTHORIZATION, try getallheaders()
    elseif (function_exists('getallheaders')) {
        $hs = getallheaders();
        $h = $hs['Authorization'] ?? $hs['authorization'] ?? '';
    }
    // Last resort: apache_request_headers()
    elseif (function_exists('apache_request_headers')) {
        $hs = apache_request_headers();
        $h = $hs['Authorization'] ?? $hs['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', (string)$h, $m)) {
        return trim($m[1]);
    }
    return null;
}

function resolveAuth(): ?array
{
    $b = bearer(); if (!$b) return null;
    $hash = tokenHash($b);
    $stmt = db()->prepare("SELECT * FROM tokens WHERE hash=? AND expiresAt>?");
    $stmt->execute([$hash, nowIso()]);
    $tok = $stmt->fetch(); if (!$tok) return null;
    $table = $tok['role'] === 'admin' ? 'admins' : 'users';
    $s2 = db()->prepare("SELECT * FROM {$table} WHERE id=?");
    $s2->execute([$tok['subjectId']]);
    $rec = $s2->fetch(); if (!$rec) return null;
    return ['role' => $tok['role'], 'record' => $rec];
}
function requireAuth(): array { $a = resolveAuth(); if (!$a) respond(['ok'=>false,'error'=>'Unauthorized'],401); return $a; }
function requireAdmin(): array { $a = requireAuth(); if ($a['role']!=='admin') respond(['ok'=>false,'error'=>'Forbidden'],403); return $a; }

// ─── Routing ──────────────────────────────────────────────────────────────────
if (!isset($_GET['action'])) { header('Content-Type: text/html; charset=utf-8'); serveHtml(); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db     = db();
    $action = (string)($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'];

    // ── Public ────────────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'boot') {
        $cats = $db->query("SELECT * FROM categories ORDER BY sortOrder")->fetchAll();
        $svcs = $db->query("SELECT * FROM services WHERE active=1 ORDER BY categoryId,id")->fetchAll();
        $settings = [];
        foreach ($db->query("SELECT key,value FROM settings")->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        respond(['ok'=>true,'categories'=>$cats,'services'=>$svcs,'settings'=>$settings,'adminCount'=>$adminCount]);
    }

    if ($method === 'POST' && $action === 'register') {
        $in   = getJson();
        $user = requireString($in, 'username', 3);
        $em   = requireString($in, 'email', 5);
        $pw   = requireString($in, 'password', 3);
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) respond(['ok'=>false,'error'=>'Invalid email'],400);
        $ex = $db->prepare("SELECT 1 FROM users WHERE username=? COLLATE NOCASE OR email=?");
        $ex->execute([$user,$em]);
        if ($ex->fetch()) respond(['ok'=>false,'error'=>'Username or email already exists'],409);
        $uid     = 'U' . nextId();
        $bonus   = max(0,(int)cfg('welcomeBonus'));
        $db->prepare("INSERT INTO users (id,username,email,passwordHash,balance,createdAt) VALUES (?,?,?,?,?,?)")
           ->execute([$uid,$user,$em,password_hash($pw,PASSWORD_DEFAULT),$bonus,nowIso()]);
        if ($bonus > 0) {
            $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,createdAt) VALUES (?,?,?,?,?,?)")
               ->execute(['T'.nextId(),$uid,'credit',$bonus,'Welcome bonus',nowIso()]);
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'login') {
        $in   = getJson();
        $user = requireString($in, 'username', 1);
        $pw   = requireString($in, 'password', 1);
        $stmt = $db->prepare("SELECT * FROM users WHERE username=? COLLATE NOCASE");
        $stmt->execute([$user]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($pw, $u['passwordHash'])) respond(['ok'=>false,'error'=>'Invalid credentials'],401);
        respond(['ok'=>true,'token'=>issueToken('user',$u['id']),'user'=>safeUser($u)]);
    }

    if ($method === 'POST' && $action === 'setup_admin') {
        if ((int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0)
            respond(['ok'=>false,'error'=>'Admin already exists'],409);
        $in = getJson();
        $dn = requireString($in,'displayName',2);
        $un = requireString($in,'username',3);
        $pw = requireString($in,'password',8);
        $db->prepare("INSERT INTO admins (id,username,displayName,passwordHash,createdAt) VALUES (?,?,?,?,?)")
           ->execute(['A'.nextId(),$un,$dn,password_hash($pw,PASSWORD_DEFAULT),nowIso()]);
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'login_admin') {
        $in   = getJson();
        $un   = requireString($in,'username',1);
        $pw   = requireString($in,'password',1);
        $stmt = $db->prepare("SELECT * FROM admins WHERE username=? COLLATE NOCASE");
        $stmt->execute([$un]);
        $a = $stmt->fetch();
        if (!$a || !password_verify($pw,$a['passwordHash'])) respond(['ok'=>false,'error'=>'Invalid admin credentials'],401);
        respond(['ok'=>true,'token'=>issueToken('admin',$a['id']),'admin'=>safeAdmin($a)]);
    }

    if ($method === 'GET' && $action === 'me') {
        $auth = requireAuth();
        $out  = $auth['role']==='admin' ? safeAdmin($auth['record']) : safeUser($auth['record']);
        respond(['ok'=>true,'profile'=>$out+['role'=>$auth['role']]]);
    }

    // ── User routes ───────────────────────────────────────────────────────────
    if ($method === 'POST' && $action === 'place_order') {
        $auth = requireAuth();
        if ($auth['role']!=='user') respond(['ok'=>false,'error'=>'User only'],403);
        $in   = getJson();
        $sid  = requireString($in,'serviceId',1);
        $link = requireString($in,'link',5);
        $qty  = max(1,(int)($in['quantity']??0));
        $stmt = $db->prepare("SELECT * FROM services WHERE id=? AND active=1");
        $stmt->execute([$sid]);
        $svc = $stmt->fetch();
        if (!$svc) respond(['ok'=>false,'error'=>'Service not found'],404);
        if ($qty < $svc['minOrder']) respond(['ok'=>false,'error'=>'Quantity below minimum ('.$svc['minOrder'].')'],400);
        if ($qty > $svc['maxOrder']) respond(['ok'=>false,'error'=>'Quantity above maximum ('.$svc['maxOrder'].')'],400);
        $charge = (int)ceil($qty * $svc['pricePerK'] / 1000);
        $user   = $auth['record'];
        if ((int)$user['balance'] < $charge) respond(['ok'=>false,'error'=>'Insufficient balance. Please add funds.'],402);
        $oid = 'ORD' . nextId();
        $db->beginTransaction();
        $db->prepare("UPDATE users SET balance=balance-?,totalSpent=totalSpent+? WHERE id=?")
           ->execute([$charge,$charge,$user['id']]);
        $db->prepare("INSERT INTO orders (id,userId,serviceId,serviceName,link,quantity,charge,status,remains,createdAt,updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$oid,$user['id'],$sid,$svc['name'],$link,$qty,$charge,'pending',$qty,nowIso(),nowIso()]);
        $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
           ->execute(['T'.nextId(),$user['id'],'debit',$charge,'Order '.$oid,$oid,nowIso()]);
        $db->commit();
        respond(['ok'=>true,'orderId'=>$oid,'charge'=>$charge]);
    }

    if ($method === 'GET' && $action === 'my_orders') {
        $auth = requireAuth();
        if ($auth['role']!=='user') respond(['ok'=>false,'error'=>'User only'],403);
        $stmt = $db->prepare("SELECT o.*,s.avgDelivery FROM orders o LEFT JOIN services s ON o.serviceId=s.id WHERE o.userId=? ORDER BY o.createdAt DESC LIMIT 100");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok'=>true,'orders'=>$stmt->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'my_transactions') {
        $auth = requireAuth();
        if ($auth['role']!=='user') respond(['ok'=>false,'error'=>'User only'],403);
        $stmt = $db->prepare("SELECT * FROM transactions WHERE userId=? ORDER BY createdAt DESC LIMIT 100");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok'=>true,'transactions'=>$stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'deposit_request') {
        $auth  = requireAuth();
        if ($auth['role']!=='user') respond(['ok'=>false,'error'=>'User only'],403);
        $in    = getJson();
        $amt   = (int)($in['amount']??0);
        $txnId = requireString($in,'transactionId',4);
        $note  = trim((string)($in['note']??''));
        $minD  = max(1,(int)cfg('minDeposit','50'));
        if ($amt < $minD) respond(['ok'=>false,'error'=>'Minimum deposit is ₹'.$minD],400);
        $did = 'DEP' . nextId();
        $db->prepare("INSERT INTO deposit_requests (id,userId,amount,transactionId,utrNote,status,createdAt,updatedAt) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$did,$auth['record']['id'],$amt,$txnId,$note,'pending',nowIso(),nowIso()]);
        respond(['ok'=>true,'depositId'=>$did]);
    }

    if ($method === 'GET' && $action === 'my_deposits') {
        $auth = requireAuth();
        if ($auth['role']!=='user') respond(['ok'=>false,'error'=>'User only'],403);
        $stmt = $db->prepare("SELECT * FROM deposit_requests WHERE userId=? ORDER BY createdAt DESC LIMIT 50");
        $stmt->execute([$auth['record']['id']]);
        respond(['ok'=>true,'deposits'=>$stmt->fetchAll()]);
    }

    // ── Admin routes ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $action === 'admin_data') {
        requireAdmin();
        $users    = $db->query("SELECT id,username,email,balance,totalSpent,createdAt FROM users ORDER BY createdAt DESC")->fetchAll();
        $orders   = $db->query("SELECT o.*,u.username FROM orders o JOIN users u ON o.userId=u.id ORDER BY o.createdAt DESC LIMIT 300")->fetchAll();
        $deposits = $db->query("SELECT d.*,u.username FROM deposit_requests d JOIN users u ON d.userId=u.id ORDER BY d.createdAt DESC LIMIT 200")->fetchAll();
        $cats     = $db->query("SELECT * FROM categories ORDER BY sortOrder")->fetchAll();
        $svcs     = $db->query("SELECT * FROM services ORDER BY categoryId,id")->fetchAll();
        $settings = [];
        foreach ($db->query("SELECT key,value FROM settings")->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        $stats = [
            'totalUsers'    => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'totalOrders'   => (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
            'pendingOrders' => (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
            'totalRevenue'  => (int)$db->query("SELECT SUM(charge) FROM orders WHERE status!='cancelled'")->fetchColumn(),
            'pendingDeps'   => (int)$db->query("SELECT COUNT(*) FROM deposit_requests WHERE status='pending'")->fetchColumn(),
        ];
        respond(['ok'=>true,'users'=>$users,'orders'=>$orders,'deposits'=>$deposits,'categories'=>$cats,'services'=>$svcs,'settings'=>$settings,'stats'=>$stats]);
    }

    if ($method === 'POST' && $action === 'update_order') {
        requireAdmin();
        $in     = getJson();
        $id     = requireString($in,'id',1);
        $status = requireString($in,'status',1);
        $valid  = ['pending','processing','completed','partial','cancelled'];
        if (!in_array($status,$valid,true)) respond(['ok'=>false,'error'=>'Invalid status'],400);
        $note   = trim((string)($in['adminNote']??''));
        $sc     = (int)($in['startCount']??0);
        $rem    = (int)($in['remains']??0);
        $db->prepare("UPDATE orders SET status=?,adminNote=?,startCount=?,remains=?,updatedAt=? WHERE id=?")
           ->execute([$status,$note,$sc,$rem,nowIso(),$id]);
        if ($status === 'cancelled') {
            $o = $db->prepare("SELECT * FROM orders WHERE id=?"); $o->execute([$id]); $ord = $o->fetch();
            if ($ord) {
                $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$ord['charge'],$ord['userId']]);
                $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
                   ->execute(['T'.nextId(),$ord['userId'],'refund',$ord['charge'],'Refund for cancelled order '.$id,$id,nowIso()]);
            }
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'approve_deposit') {
        requireAdmin();
        $in  = getJson();
        $id  = requireString($in,'id',1);
        $act = requireString($in,'action',1);
        $note = trim((string)($in['note']??''));
        $stmt = $db->prepare("SELECT * FROM deposit_requests WHERE id=?");
        $stmt->execute([$id]);
        $dep = $stmt->fetch();
        if (!$dep) respond(['ok'=>false,'error'=>'Deposit not found'],404);
        if ($dep['status']!=='pending') respond(['ok'=>false,'error'=>'Already processed'],400);
        if ($act === 'approve') {
            $db->beginTransaction();
            $db->prepare("UPDATE deposit_requests SET status='approved',adminNote=?,updatedAt=? WHERE id=?")->execute([$note,nowIso(),$id]);
            $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$dep['amount'],$dep['userId']]);
            $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,refId,createdAt) VALUES (?,?,?,?,?,?,?)")
               ->execute(['T'.nextId(),$dep['userId'],'credit',$dep['amount'],'Deposit approved',$id,nowIso()]);
            $db->commit();
        } else {
            $db->prepare("UPDATE deposit_requests SET status='rejected',adminNote=?,updatedAt=? WHERE id=?")->execute([$note,nowIso(),$id]);
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'add_funds') {
        requireAdmin();
        $in  = getJson();
        $uid = requireString($in,'userId',1);
        $amt = (int)($in['amount']??0);
        if ($amt <= 0) respond(['ok'=>false,'error'=>'Invalid amount'],400);
        $db->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$amt,$uid]);
        $db->prepare("INSERT INTO transactions (id,userId,type,amount,description,createdAt) VALUES (?,?,?,?,?,?)")
           ->execute(['T'.nextId(),$uid,'credit',$amt,'Manual credit by admin',nowIso()]);
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'save_service') {
        requireAdmin();
        $in  = getJson();
        $sid = trim((string)($in['id']??''));
        $cat = requireString($in,'categoryId',1);
        $nm  = requireString($in,'name',3);
        $dsc = trim((string)($in['description']??''));
        $ppk = max(1,(int)($in['pricePerK']??0));
        $mn  = max(1,(int)($in['minOrder']??100));
        $mx  = max($mn,(int)($in['maxOrder']??10000));
        $del = trim((string)($in['avgDelivery']??'1-3 days'));
        $act = (int)(bool)($in['active']??true);
        if ($sid) {
            $db->prepare("UPDATE services SET categoryId=?,name=?,description=?,pricePerK=?,minOrder=?,maxOrder=?,avgDelivery=?,active=? WHERE id=?")
               ->execute([$cat,$nm,$dsc,$ppk,$mn,$mx,$del,$act,$sid]);
        } else {
            $db->prepare("INSERT INTO services (id,categoryId,name,description,pricePerK,minOrder,maxOrder,avgDelivery,active) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute(['SVC'.nextId(),$cat,$nm,$dsc,$ppk,$mn,$mx,$del,$act]);
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'delete_service') {
        requireAdmin();
        $in = getJson();
        $id = requireString($in,'id',1);
        $db->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'save_category') {
        requireAdmin();
        $in   = getJson();
        $cid  = trim((string)($in['id']??''));
        $nm   = requireString($in,'name',2);
        $icon = trim((string)($in['icon']??'📦'));
        $sort = (int)($in['sortOrder']??0);
        if ($cid) {
            $db->prepare("UPDATE categories SET name=?,icon=?,sortOrder=? WHERE id=?")->execute([$nm,$icon,$sort,$cid]);
        } else {
            $db->prepare("INSERT INTO categories (id,name,icon,sortOrder) VALUES (?,?,?,?)")
               ->execute(['CAT'.nextId(),$nm,$icon,$sort]);
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'save_settings') {
        requireAdmin();
        $in   = getJson();
        $keys = ['upiId','qrBase64','siteName','minDeposit','welcomeBonus','notice'];
        foreach ($keys as $k) {
            if (isset($in[$k])) $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)")->execute([$k,trim((string)$in[$k])]);
        }
        respond(['ok'=>true]);
    }

    if ($method === 'POST' && $action === 'delete_user_admin') {
        requireAdmin();
        $in = getJson();
        $id = requireString($in,'id',1);
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        respond(['ok'=>true]);
    }

    respond(['ok'=>false,'error'=>'Unknown action'],400);
} catch (Throwable $e) {
    respond(['ok'=>false,'error'=>$e->getMessage()],500);
}

function safeUser(array $u): array {
    return ['id'=>$u['id'],'username'=>$u['username'],'email'=>$u['email'],'balance'=>(int)$u['balance'],'totalSpent'=>(int)$u['totalSpent'],'createdAt'=>$u['createdAt']];
}
function safeAdmin(array $a): array {
    return ['id'=>$a['id'],'username'=>$a['username'],'displayName'=>$a['displayName'],'createdAt'=>$a['createdAt']];
}

// ─── HTML (unchanged – same as previous app-style version) ─────────────────
function serveHtml(): void { echo getHtml(); }
function getHtml(): string
{
return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<title>SocialGrow – SMM Panel</title>
<meta http-equiv="Cache-Control" content="no-cache,no-store,must-revalidate">
<meta name="theme-color" content="#050810">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Cabinet+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#050810;--surface:#0c1220;--surface2:#111827;--surface3:#1a2535;
  --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.12);
  --text:#f0f4ff;--muted:#6b7fa0;--subtle:#2a3a56;
  --pink:#ff2d7e;--cyan:#00e5d4;--violet:#8b5cf6;--amber:#f59e0b;
  --green:#10b981;--red:#ef4444;--blue:#3b82f6;
  --grad:linear-gradient(135deg,var(--pink),var(--violet));
  --grad2:linear-gradient(135deg,var(--cyan),var(--blue));
  --r:10px;--r-lg:16px;--r-xl:22px;
  --font-head:'Clash Display',sans-serif;
  --font-body:'Cabinet Grotesk',sans-serif;
  --shadow:0 20px 50px rgba(0,0,0,0.5);
  --bottom-nav-h:64px;--topbar-h:52px;
  --safe-bottom:env(safe-area-inset-bottom,0px);
}
html{scroll-behavior:smooth;-webkit-tap-highlight-color:transparent}
body{min-height:100vh;min-height:-webkit-fill-available;font-family:var(--font-body);background:var(--bg);color:var(--text);overflow-x:hidden;font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
button,input,select,textarea{font:inherit;outline:none}
button{border:0;cursor:pointer;background:none;color:inherit}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
.hidden{display:none!important}
input,textarea,select{width:100%;padding:12px 15px;border-radius:var(--r);border:1px solid var(--border2);background:rgba(0,0,0,0.35);color:var(--text);transition:border-color 0.2s,box-shadow 0.2s;-webkit-appearance:none;font-size:0.92rem}
input:focus,textarea:focus,select:focus{border-color:var(--pink);box-shadow:0 0 0 3px rgba(255,45,126,0.1)}
select option{background:var(--surface2);color:var(--text)}
textarea{min-height:80px;resize:vertical}

/* ── App Shell ── */
#app{max-width:520px;margin:0 auto;min-height:100vh;min-height:-webkit-fill-available;display:flex;flex-direction:column;position:relative;background:var(--bg)}
#topbar{height:var(--topbar-h);display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid var(--border);background:rgba(5,8,16,0.92);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);position:sticky;top:0;z-index:50;gap:10px;flex-shrink:0}
#topbar .logo{display:flex;align-items:center;gap:8px}
#topbar .logo-mark{width:30px;height:30px;border-radius:8px;background:var(--grad);display:grid;place-items:center;font-size:15px;flex-shrink:0}
#topbar .logo-text{font-family:var(--font-head);font-weight:700;font-size:1rem;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
#topbar .topbar-right{display:flex;align-items:center;gap:8px}
.balance-chip{background:rgba(0,229,212,0.1);border:1px solid rgba(0,229,212,0.2);color:var(--cyan);padding:5px 12px;border-radius:999px;font-family:var(--font-head);font-weight:700;font-size:0.82rem;white-space:nowrap}
.btn-sm{padding:7px 12px;font-size:0.78rem;border-radius:8px;font-weight:700;transition:all 0.18s}
.btn-ghost{background:rgba(255,255,255,0.06);color:var(--text);border:1px solid var(--border2)}
.btn-ghost:hover{background:rgba(255,255,255,0.1)}
.btn-grad{background:var(--grad);color:#fff;box-shadow:0 6px 20px rgba(255,45,126,0.25)}
.btn-grad:hover{box-shadow:0 10px 28px rgba(255,45,126,0.4);transform:translateY(-1px)}
.btn-cyan{background:var(--grad2);color:#000;font-weight:800}
.btn-success{background:linear-gradient(135deg,var(--green),#047857);color:#fff}
.btn-danger{background:linear-gradient(135deg,var(--red),#991b1b);color:#fff}
.btn-warn{background:linear-gradient(135deg,var(--amber),#b45309);color:#fff}
.btn-xs{padding:5px 10px;font-size:0.75rem;border-radius:6px}
.btn-full{width:100%;justify-content:center;display:flex;align-items:center;gap:6px;padding:13px;font-size:0.9rem;border-radius:var(--r);font-weight:700}

/* ── Content Area ── */
#content{flex:1;overflow-y:auto;padding:0 0 calc(var(--bottom-nav-h) + var(--safe-bottom) + 16px) 0;-webkit-overflow-scrolling:touch}
#content .page{padding:16px;animation:fadeIn 0.2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── Bottom Nav ── */
#bottomNav{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:520px;height:calc(var(--bottom-nav-h) + var(--safe-bottom));padding-bottom:var(--safe-bottom);background:rgba(12,18,32,0.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-top:1px solid var(--border);display:flex;z-index:60;flex-shrink:0}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;color:var(--muted);font-size:0.68rem;font-weight:600;transition:all 0.18s;position:relative;padding:4px 2px}
.nav-item .nav-icon{font-size:1.25rem;transition:transform 0.18s}
.nav-item.active{color:var(--pink)}
.nav-item.active .nav-icon{transform:scale(1.1)}
.nav-item.active::after{content:'';position:absolute;top:-1px;left:50%;transform:translateX(-50%);width:24px;height:3px;background:var(--pink);border-radius:0 0 4px 4px}
.nav-badge{position:absolute;top:2px;right:calc(50% - 18px);min-width:16px;height:16px;border-radius:999px;background:var(--pink);color:#fff;font-size:0.6rem;font-weight:800;display:grid;place-items:center;padding:0 4px}

/* ── Service Cards ── */
.section-title{font-family:var(--font-head);font-size:1.1rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.cat-scroll{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:16px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.cat-scroll::-webkit-scrollbar{display:none}
.cat-pill{flex-shrink:0;padding:8px 16px;border-radius:999px;font-size:0.82rem;font-weight:700;color:var(--muted);border:1px solid var(--border);transition:all 0.18s;white-space:nowrap;cursor:pointer}
.cat-pill.active{background:rgba(255,45,126,0.12);color:var(--pink);border-color:rgba(255,45,126,0.35)}
.service-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;cursor:pointer;transition:all 0.2s;display:grid;gap:10px}
.service-card:active{transform:scale(0.98);border-color:var(--pink)}
.service-card .svc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.service-card .svc-cat{font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--pink)}
.service-card .svc-name{font-family:var(--font-head);font-size:0.95rem;font-weight:700;line-height:1.3}
.service-card .svc-desc{font-size:0.8rem;color:var(--muted);line-height:1.5}
.service-card .svc-footer{display:flex;justify-content:space-between;align-items:center;gap:8px}
.service-card .svc-price{font-family:var(--font-head);font-size:1rem;font-weight:700;color:var(--cyan)}
.service-card .svc-meta-row{display:flex;gap:10px;font-size:0.72rem;color:var(--muted)}
.service-grid{display:grid;gap:10px}

/* ── Bottom Sheet ── */
#overlay{position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:70;opacity:0;pointer-events:none;transition:opacity 0.25s}
#overlay.show{opacity:1;pointer-events:auto}
#bottomSheet{position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(100%);width:100%;max-width:520px;max-height:85vh;background:var(--surface);border-radius:var(--r-xl) var(--r-xl) 0 0;z-index:75;transition:transform 0.3s cubic-bezier(0.32,0.72,0,1);display:flex;flex-direction:column;box-shadow:0 -20px 50px rgba(0,0,0,0.5)}
#bottomSheet.show{transform:translateX(-50%) translateY(0)}
#bottomSheet .sheet-handle{width:32px;height:4px;background:var(--subtle);border-radius:2px;margin:10px auto 6px;flex-shrink:0}
#bottomSheet .sheet-header{padding:8px 20px 12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
#bottomSheet .sheet-body{flex:1;overflow-y:auto;padding:16px 20px 24px;-webkit-overflow-scrolling:touch}
#bottomSheet .sheet-title{font-family:var(--font-head);font-size:1.05rem;font-weight:700}

/* ── Qty Selector ── */
.qty-box{display:flex;align-items:center;gap:12px;background:var(--surface2);border-radius:var(--r);padding:6px;border:1px solid var(--border)}
.qty-box button{width:40px;height:40px;border-radius:var(--r);background:rgba(255,255,255,0.05);font-size:1.3rem;font-weight:700;display:grid;place-items:center;color:var(--text);transition:all 0.15s}
.qty-box button:active{background:var(--pink);color:#fff}
.qty-box input{flex:1;text-align:center;font-size:1.1rem;font-weight:700;border:0;background:transparent;padding:8px;min-width:0}
.qty-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.qty-chip{padding:6px 14px;border-radius:999px;font-size:0.76rem;font-weight:700;background:rgba(255,255,255,0.04);border:1px solid var(--border);color:var(--muted);transition:all 0.15s}
.qty-chip:active,.qty-chip.active{background:rgba(0,229,212,0.1);border-color:var(--cyan);color:var(--cyan)}

/* ── Charge Preview ── */
.charge-box{background:rgba(0,229,212,0.06);border:1px solid rgba(0,229,212,0.15);border-radius:var(--r);padding:14px;display:grid;gap:6px}
.charge-row{display:flex;justify-content:space-between;font-size:0.85rem}
.charge-total{font-family:var(--font-head);font-size:1.15rem;font-weight:800;color:var(--cyan)}

/* ── Cards & Misc ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px}
.card-inner{background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-lg);padding:14px}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:0.72rem;font-weight:700;letter-spacing:0.02em}
.b-pending{background:rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.3)}
.b-processing{background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3)}
.b-completed{background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3)}
.b-partial{background:rgba(139,92,246,0.15);color:#a78bfa;border:1px solid rgba(139,92,246,0.3)}
.b-cancelled{background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3)}
.b-approved{background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3)}
.b-rejected{background:rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.3)}
.b-credit{background:rgba(16,185,129,0.12);color:#6ee7b7}
.b-debit{background:rgba(239,68,68,0.12);color:#fca5a5}
.b-refund{background:rgba(139,92,246,0.12);color:#c4b5fd}
.notice{padding:12px 14px;border-radius:var(--r);font-size:0.84rem;line-height:1.6}
.n-info{background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);color:#93c5fd}
.n-warn{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.2);color:#fcd34d}
.n-success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);color:#6ee7b7}
.n-danger{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);color:#fca5a5}
.mono{font-family:monospace;background:rgba(255,255,255,0.05);padding:2px 7px;border-radius:4px;font-size:0.8rem;word-break:break-all}
.meta{color:var(--muted);font-size:0.8rem;line-height:1.5}
.pay-block{background:rgba(0,229,212,0.04);border:1px solid rgba(0,229,212,0.12);border-radius:var(--r-lg);padding:16px;display:grid;gap:10px}
.upi-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.upi-val{font-family:monospace;background:rgba(0,229,212,0.08);border:1px solid rgba(0,229,212,0.15);padding:8px 14px;border-radius:var(--r);flex:1;min-width:0;word-break:break-all;font-size:0.88rem}
.qr-wrap{display:flex;justify-content:center;padding:8px}
.qr-wrap img{max-width:140px;border-radius:var(--r);border:1px solid var(--border)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:16px}
.stat-card{border-radius:var(--r-lg);padding:14px;border:1px solid var(--border);background:var(--surface)}
.stat-icon{font-size:1.4rem;margin-bottom:6px}
.stat-label{font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted);margin-bottom:2px}
.stat-value{font-family:var(--font-head);font-size:1.5rem;font-weight:700}
.tbl-wrap{overflow-x:auto;border-radius:var(--r);border:1px solid var(--border);-webkit-overflow-scrolling:touch}
.tbl{width:100%;border-collapse:collapse;font-size:0.8rem}
.tbl th{padding:8px 10px;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,0.03);vertical-align:middle}
.tbl tr:last-child td{border-bottom:0}
.admin-subtabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;margin-bottom:14px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
.admin-subtabs::-webkit-scrollbar{display:none}
.admin-subtab{flex-shrink:0;padding:7px 14px;border-radius:999px;font-size:0.78rem;font-weight:700;color:var(--muted);border:1px solid var(--border);transition:all 0.15s;cursor:pointer}
.admin-subtab.active{background:rgba(255,45,126,0.12);color:var(--pink);border-color:rgba(255,45,126,0.3)}
.list-stack{display:grid;gap:8px}
.list-item{border-radius:var(--r);border:1px solid var(--border);background:rgba(255,255,255,0.02);padding:12px 14px}
.list-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap}
.list-head strong{font-weight:700;font-size:0.9rem}
.btn-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── Toast ── */
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
#toastWrap{position:fixed;bottom:calc(var(--bottom-nav-h) + var(--safe-bottom) + 12px);right:12px;z-index:200;display:grid;gap:8px;pointer-events:none}
.toast{padding:10px 16px;border-radius:var(--r);font-weight:700;font-size:0.84rem;box-shadow:0 12px 32px rgba(0,0,0,0.45);animation:toastIn 0.22s ease;max-width:300px;pointer-events:auto}

/* ── Auth ── */
.auth-screen{min-height:100vh;min-height:-webkit-fill-available;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;gap:20px}
.auth-hero{text-align:center;max-width:360px}
.auth-hero h1{font-family:var(--font-head);font-size:2.4rem;line-height:1.05;font-weight:700;letter-spacing:-0.02em}
.auth-hero h1 em{font-style:normal;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.auth-hero p{color:var(--muted);font-size:0.9rem;margin-top:8px;line-height:1.6}
.auth-card{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-xl);padding:24px}
.auth-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;background:rgba(0,0,0,0.3);border-radius:var(--r);padding:4px;margin-bottom:18px;border:1px solid var(--border)}
.auth-tab{padding:8px;border-radius:7px;font-size:0.78rem;font-weight:700;color:var(--muted);transition:all 0.15s;text-align:center}
.auth-tab.active{background:var(--grad);color:#fff}
.form-stack{display:grid;gap:12px}
.field{display:grid;gap:4px}
.field label{font-size:0.78rem;font-weight:700;color:var(--muted)}
.field-note{font-size:0.76rem;color:var(--muted);text-align:center}
.divider{height:1px;background:var(--border);margin:12px 0}

/* ── Empty State ── */
.empty-state{text-align:center;padding:32px 16px;color:var(--muted)}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:10px}
.empty-state p{font-size:0.88rem}

/* ── Responsive ── */
@media(min-width:521px){
  #app{border-left:1px solid var(--border);border-right:1px solid var(--border)}
}
</style>
</head>
<body>
<div id="app">
  <header id="topbar">
    <div class="logo"><div class="logo-mark">🚀</div><span class="logo-text" id="siteNameEl">SocialGrow</span></div>
    <div class="topbar-right">
      <span id="balanceChip" class="balance-chip hidden">₹0.00</span>
      <button class="btn-sm btn-ghost" id="signOutBtn" onclick="doLogout()" style="display:none">Sign out</button>
    </div>
  </header>

  <main id="content"></main>

  <nav id="bottomNav" style="display:none">
    <button class="nav-item active" data-view="home" onclick="navigate('home')">
      <span class="nav-icon">🏠</span>Home
    </button>
    <button class="nav-item" data-view="wallet" onclick="navigate('wallet')">
      <span class="nav-icon">💰</span>Wallet
    </button>
    <button class="nav-item" data-view="orders" onclick="navigate('orders')">
      <span class="nav-icon">📦</span>Orders
    </button>
    <button class="nav-item" data-view="profile" onclick="navigate('profile')">
      <span class="nav-icon">👤</span>Profile
    </button>
    <button class="nav-item hidden" data-view="admin" id="navAdmin" onclick="navigate('admin')">
      <span class="nav-icon">⚙️</span>Admin
    </button>
  </nav>

  <div id="overlay" onclick="closeSheet()"></div>

  <div id="bottomSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <span class="sheet-title" id="sheetTitle">Service Details</span>
      <button class="btn-sm btn-ghost" onclick="closeSheet()">✕</button>
    </div>
    <div class="sheet-body" id="sheetBody"></div>
  </div>
</div>

<div id="toastWrap"></div>

<script>
// ── State ──────────────────────────────────────────────────────────────────
const API = "?action=";
const SK  = "sg_session_v2";
const S = {
  token:"", role:"", profile:null,
  boot:null, adminMode:false, view:"home", adminSub:"overview",
  myOrders:[], myDeposits:[], myTxns:[], adminData:null,
  activeCat:null, selectedService:null,
  _qrPend:null
};

// ── Utils ──────────────────────────────────────────────────────────────────
const money  = v => "₹ " + (Number(v||0)/100).toFixed(2);
const moneyR = v => "₹ " + Number(v||0).toLocaleString("en-IN");
const esc    = v => String(v||"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const dt     = s => s ? new Date(s).toLocaleString("en-IN",{dateStyle:"medium",timeStyle:"short"}) : "";

function toast(msg, type="info") {
  const colors = {info:"#3b82f6",success:"#10b981",danger:"#ef4444",warn:"#f59e0b"};
  const w = document.getElementById("toastWrap");
  const t = document.createElement("div");
  t.className = "toast";
  t.style.cssText = "background:" + (colors[type]||colors.info) + ";color:#fff;";
  t.textContent = msg;
  w.appendChild(t);
  setTimeout(() => t.remove(), 3000);
}

async function call(action, opts={}) {
  const method = opts.method || "POST";
  const headers = {"Cache-Control":"no-store"};
  if (S.token) headers.Authorization = "Bearer " + S.token;
  let body;
  if (opts.body !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(opts.body); }
  const r = await fetch(API + encodeURIComponent(action), {method, headers, body, cache:"no-store"});
  const p = await r.json().catch(() => ({ok:false, error:"Server error"}));
  if (!r.ok || !p.ok) throw new Error(p.error || "Request failed (" + r.status + ")");
  return p;
}

function compressImage(file, maxW=500, q=0.85) {
  return new Promise((res, rej) => {
    const reader = new FileReader();
    reader.onload = e => {
      const img = new Image();
      img.onload = () => {
        let [w, h] = [img.width, img.height];
        if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
        const c = Object.assign(document.createElement("canvas"), {width:w, height:h});
        c.getContext("2d").drawImage(img, 0, 0, w, h);
        res(c.toDataURL("image/jpeg", q));
      };
      img.onerror = rej;
      img.src = e.target.result;
    };
    reader.onerror = rej;
    reader.readAsDataURL(file);
  });
}

// ── Session ────────────────────────────────────────────────────────────────
function saveSession() { sessionStorage.setItem(SK, JSON.stringify({token:S.token, role:S.role})); }
function clearSession() { sessionStorage.removeItem(SK); Object.assign(S, {token:"",role:"",profile:null,myOrders:[],myDeposits:[],myTxns:[],adminData:null}); }

async function restoreSession() {
  const raw = sessionStorage.getItem(SK); if (!raw) return;
  try {
    const s = JSON.parse(raw); if (!s.token) return;
    S.token = s.token; S.role = s.role || "user";
    const me = await call("me", {method:"GET"}); S.profile = me.profile;
    await enterApp();
  } catch { clearSession(); }
}

// ── Boot ───────────────────────────────────────────────────────────────────
async function boot() {
  try {
    S.boot = await call("boot", {method:"GET"});
    document.title = (S.boot.settings?.siteName || "SocialGrow") + " – SMM Panel";
    document.getElementById("siteNameEl").textContent = S.boot.settings?.siteName || "SocialGrow";
    document.getElementById("serverWarn")?.classList.add("hidden");
  } catch {
    S.boot = {categories:[], services:[], settings:{}, adminCount:0};
    const w = document.getElementById("serverWarn");
    if (w) w.classList.remove("hidden");
  }
  renderAuthForms();
}

// ── Navigation ─────────────────────────────────────────────────────────────
function navigate(view) {
  S.view = view;
  if (view === "admin") S.adminSub = "overview";
  updateNavHighlight();
  renderPage();
}

function updateNavHighlight() {
  document.querySelectorAll("#bottomNav .nav-item").forEach(el => {
    el.classList.toggle("active", el.dataset.view === S.view);
  });
}

function updateBalanceChip() {
  const chip = document.getElementById("balanceChip");
  if (S.role === "user" && S.profile) {
    chip.classList.remove("hidden");
    chip.textContent = "₹ " + (Number(S.profile.balance||0)/100).toFixed(2);
  } else if (S.role === "admin" && !S.adminMode) {
    if (S.profile && S.profile.balance !== undefined) {
      chip.classList.remove("hidden");
      chip.textContent = "₹ " + (Number(S.profile.balance||0)/100).toFixed(2);
    } else { chip.classList.add("hidden"); }
  } else {
    chip.classList.add("hidden");
  }
}

// ── Sheet ──────────────────────────────────────────────────────────────────
function openSheet(title, bodyHtml) {
  document.getElementById("sheetTitle").textContent = title;
  document.getElementById("sheetBody").innerHTML = bodyHtml;
  document.getElementById("bottomSheet").classList.add("show");
  document.getElementById("overlay").classList.add("show");
}
function closeSheet() {
  document.getElementById("bottomSheet").classList.remove("show");
  document.getElementById("overlay").classList.remove("show");
  S.selectedService = null;
}

// ── Render Engine ──────────────────────────────────────────────────────────
function renderPage() {
  const c = document.getElementById("content");
  let html = "";
  if (S.adminMode && S.view === "admin") {
    html = renderAdminPage();
  } else {
    switch (S.view) {
      case "home": html = renderHome(); break;
      case "wallet": html = renderWallet(); break;
      case "orders": html = renderOrders(); loadOrders(); break;
      case "profile": html = renderProfile(); break;
      default: html = renderHome();
    }
  }
  c.innerHTML = '<div class="page">' + html + '</div>';
  afterRender();
}

function afterRender() {
  if (S.view === "wallet") loadDeposits();
  if (S.view === "orders") loadOrders();
}

// ── Home Page ──────────────────────────────────────────────────────────────
function renderHome() {
  const cats = S.boot?.categories || [];
  const allSvcs = S.boot?.services || [];
  const activeCat = S.activeCat;
  const svcs = activeCat ? allSvcs.filter(s => s.categoryId === activeCat) : allSvcs;
  const notice = S.boot?.settings?.notice || "";

  let html = "";
  if (notice) html += '<div class="notice n-info" style="margin-bottom:14px">📢 ' + esc(notice) + '</div>';

  html += '<div class="section-title">🔥 Services</div>';
  html += '<div class="cat-scroll">';
  html += '<button class="cat-pill ' + (!activeCat?"active":"") + '" onclick="filterCat(null)">All 📦</button>';
  cats.forEach(c => {
    html += '<button class="cat-pill ' + (activeCat===c.id?"active":"") + '" onclick="filterCat(\'' + c.id + '\')">' + c.icon + ' ' + esc(c.name) + '</button>';
  });
  html += '</div>';

  html += '<div class="service-grid">';
  if (svcs.length === 0) {
    html += '<div class="empty-state"><div class="empty-icon">📭</div><p>No services in this category.</p></div>';
  } else {
    svcs.forEach(svc => {
      const catObj = cats.find(c => c.id === svc.categoryId) || {};
      html += '<div class="service-card" onclick="openServiceSheet(\'' + svc.id + '\')">' +
        '<div class="svc-top"><div><div class="svc-cat">' + catObj.icon + ' ' + esc(catObj.name||"") + '</div>' +
        '<div class="svc-name">' + esc(svc.name) + '</div></div></div>' +
        '<div class="svc-desc">' + esc(svc.description) + '</div>' +
        '<div class="svc-footer">' +
          '<span class="svc-price">₹' + (Number(svc.pricePerK)/100).toFixed(2) + '<span style="font-size:0.7rem;color:var(--muted)">/1K</span></span>' +
          '<span class="svc-meta-row">Min ' + Number(svc.minOrder).toLocaleString() + ' · ' + esc(svc.avgDelivery) + '</span>' +
        '</div></div>';
    });
  }
  html += '</div>';
  return html;
}

function filterCat(id) {
  S.activeCat = id;
  renderPage();
}

// ── Service Bottom Sheet ───────────────────────────────────────────────────
function openServiceSheet(svcId) {
  const svc = (S.boot?.services||[]).find(s => s.id === svcId);
  if (!svc) return;
  S.selectedService = svc;
  const cats = S.boot?.categories || [];
  const catObj = cats.find(c => c.id === svc.categoryId) || {};
  const bal = Number(S.profile?.balance||0);

  const quickQtys = [];
  if (svc.minOrder) quickQtys.push(svc.minOrder);
  [1000, 5000, 10000, 50000].forEach(q => {
    if (q > svc.minOrder && q < svc.maxOrder && !quickQtys.includes(q)) quickQtys.push(q);
  });
  if (svc.maxOrder && !quickQtys.includes(svc.maxOrder)) quickQtys.push(svc.maxOrder);

  let html = '';
  html += '<div style="margin-bottom:14px"><span class="badge" style="background:rgba(255,45,126,0.12);color:var(--pink);border:1px solid rgba(255,45,126,0.2)">' + catObj.icon + ' ' + esc(catObj.name||"") + '</span></div>';
  html += '<div style="font-family:var(--font-head);font-size:1.15rem;font-weight:700;margin-bottom:8px">' + esc(svc.name) + '</div>';
  html += '<div class="meta" style="margin-bottom:14px">' + esc(svc.description) + '</div>';

  html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px">';
  html += '<div class="card-inner"><small class="meta">Price per 1K</small><div style="font-family:var(--font-head);font-size:1rem;font-weight:700;color:var(--cyan)">₹' + (Number(svc.pricePerK)/100).toFixed(2) + '</div></div>';
  html += '<div class="card-inner"><small class="meta">Delivery</small><div style="font-weight:700;font-size:0.9rem">' + esc(svc.avgDelivery) + '</div></div>';
  html += '<div class="card-inner"><small class="meta">Min Order</small><div style="font-weight:700;font-size:0.9rem">' + Number(svc.minOrder).toLocaleString() + '</div></div>';
  html += '<div class="card-inner"><small class="meta">Max Order</small><div style="font-weight:700;font-size:0.9rem">' + Number(svc.maxOrder).toLocaleString() + '</div></div>';
  html += '</div>';

  html += '<div class="field" style="margin-bottom:10px"><label>Your Link / Username</label><input id="bsLink" placeholder="https://instagram.com/yourprofile" required></div>';

  html += '<label style="font-size:0.78rem;font-weight:700;color:var(--muted);margin-bottom:4px;display:block">Quantity</label>';
  html += '<div class="qty-box">';
  html += '<button onclick="bsQtyAdj(-1)">−</button>';
  html += '<input id="bsQty" type="number" min="' + svc.minOrder + '" max="' + svc.maxOrder + '" value="' + svc.minOrder + '" oninput="bsCalc()">';
  html += '<button onclick="bsQtyAdj(1)">+</button>';
  html += '</div>';
  html += '<div class="qty-chips">';
  quickQtys.forEach(q => {
    html += '<button class="qty-chip" onclick="bsSetQty(' + q + ')">' + (q >= 1000 ? (q/1000)+'K' : q) + '</button>';
  });
  html += '</div>';

  html += '<div id="bsChargeBox" class="charge-box" style="margin-top:14px"></div>';

  const charge = Math.ceil(svc.minOrder * svc.pricePerK / 1000);
  const enough = bal >= charge;
  html += '<button class="btn-full btn-grad" style="margin-top:14px" id="bsPlaceBtn" onclick="bsPlaceOrder()">🚀 Place Order · ₹' + (charge/100).toFixed(2) + '</button>';
  if (!enough) {
    html += '<div class="notice n-warn" style="margin-top:8px">⚠️ Balance ₹' + (bal/100).toFixed(2) + ' — Need ₹' + ((charge-bal)/100).toFixed(2) + ' more. <button class="btn-sm btn-cyan" onclick="closeSheet();navigate(\'wallet\')">Add Funds →</button></div>';
  }

  openSheet('Order Service', html);
  setTimeout(bsCalc, 50);
}

function bsSetQty(q) {
  const inp = document.getElementById("bsQty"); if (!inp) return;
  inp.value = q; bsCalc();
}
function bsQtyAdj(d) {
  const inp = document.getElementById("bsQty"); if (!inp) return;
  const svc = S.selectedService; if (!svc) return;
  const step = Math.max(1, Math.floor(svc.minOrder / 10));
  let v = (parseInt(inp.value) || svc.minOrder) + d * step;
  v = Math.max(svc.minOrder, Math.min(svc.maxOrder, v));
  inp.value = v; bsCalc();
}
function bsCalc() {
  const svc = S.selectedService; if (!svc) return;
  const qty = parseInt(document.getElementById("bsQty")?.value) || svc.minOrder;
  const charge = Math.ceil(qty * svc.pricePerK / 1000);
  const bal = Number(S.profile?.balance||0);
  const enough = bal >= charge;
  const box = document.getElementById("bsChargeBox");
  const btn = document.getElementById("bsPlaceBtn");
  if (box) {
    box.innerHTML = '<div class="charge-row"><span>Quantity</span><span>' + qty.toLocaleString() + '</span></div>' +
      '<div class="charge-row"><span>Rate</span><span>₹' + (svc.pricePerK/100).toFixed(2) + '/1K</span></div>' +
      '<div class="charge-row"><span>Balance</span><span style="color:' + (enough?'var(--green)':'var(--red)') + '">₹' + (bal/100).toFixed(2) + '</span></div>' +
      '<div class="charge-total">Total: ₹' + (charge/100).toFixed(2) + '</div>';
  }
  if (btn) {
    btn.textContent = '🚀 Place Order · ₹' + (charge/100).toFixed(2);
    btn.style.opacity = enough ? '1' : '0.5';
  }
}

async function bsPlaceOrder() {
  const svc = S.selectedService; if (!svc) return;
  const link = document.getElementById("bsLink")?.value.trim();
  const qty = parseInt(document.getElementById("bsQty")?.value) || svc.minOrder;
  if (!link || link.length < 5) { toast("Please enter a valid link","warn"); return; }
  if (qty < svc.minOrder || qty > svc.maxOrder) { toast("Quantity out of range","warn"); return; }
  try {
    const r = await call("place_order", {body:{serviceId:svc.id, link, quantity:qty}});
    toast("Order #" + r.orderId + " placed! 🎉","success");
    closeSheet();
    S.profile = await call("me", {method:"GET"}).then(p => p.profile).catch(() => S.profile);
    updateBalanceChip();
    navigate("orders");
  } catch(ex) { toast(ex.message,"danger"); }
}

// ── Wallet Page ────────────────────────────────────────────────────────────
function renderWallet() {
  const s = S.boot?.settings || {};
  const min = Number(s.minDeposit||50);
  let html = '<div class="section-title">💰 My Wallet</div>';
  html += '<div class="card" style="text-align:center;margin-bottom:16px">';
  html += '<div class="meta">Available Balance</div>';
  html += '<div style="font-family:var(--font-head);font-size:2.4rem;font-weight:800;color:var(--cyan);line-height:1.1">₹' + (Number(S.profile?.balance||0)/100).toFixed(2) + '</div>';
  html += '<div class="meta">Total Spent: ₹' + (Number(S.profile?.totalSpent||0)/100).toFixed(2) + '</div>';
  html += '</div>';

  html += '<div class="pay-block" style="margin-bottom:16px">';
  html += '<div style="font-family:var(--font-head);font-weight:700;color:var(--cyan)">💳 UPI Payment</div>';
  const upi = s.upiId||"";
  if (upi) {
    html += '<div class="upi-row"><span class="upi-val">' + esc(upi) + '</span><button class="btn-sm btn-ghost" onclick="navigator.clipboard.writeText(\'' + esc(upi) + '\').then(()=>toast(\'Copied!\',\'success\'))">Copy</button></div>';
  } else {
    html += '<div class="meta">UPI not configured yet.</div>';
  }
  const qr = s.qrBase64||"";
  if (qr) html += '<div class="qr-wrap"><img src="' + qr + '" alt="QR"></div>';
  html += '<div class="field-note">Pay using the UPI ID above → get the UTR/Transaction ID → submit below.</div>';
  html += '</div>';

  html += '<div class="card" style="margin-bottom:16px"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:12px">Deposit Request</div>';
  html += '<form class="form-stack" onsubmit="doDeposit(event)">';
  html += '<div class="field"><label>Amount (₹) — Min ₹' + min + '</label><input id="dAmt" type="number" min="' + min + '" required placeholder="Enter amount"></div>';
  html += '<div class="field"><label>UTR / Transaction ID</label><input id="dTxn" required placeholder="12-digit UTR"></div>';
  html += '<div class="field"><label>Note (optional)</label><textarea id="dNote" placeholder="Any extra info..."></textarea></div>';
  html += '<button class="btn-full btn-cyan" type="submit">Submit Deposit Request</button>';
  html += '</form></div>';

  html += '<div class="section-title">Recent Deposits</div><div id="depList"><div class="notice n-info">Loading…</div></div>';
  return html;
}

async function loadDeposits() {
  try { const r = await call("my_deposits", {method:"GET"}); S.myDeposits = r.deposits||[]; } catch {}
  const el = document.getElementById("depList"); if (!el) return;
  const deps = S.myDeposits;
  el.innerHTML = deps.length
    ? '<div class="list-stack">' + deps.map(d => '<div class="list-item"><div class="list-head"><strong>₹' + Number(d.amount).toLocaleString() + '</strong><span class="badge b-' + d.status + '">' + d.status + '</span></div><div class="meta">UTR: <span class="mono">' + esc(d.transactionId) + '</span></div>' + (d.adminNote?'<div class="meta">Note: ' + esc(d.adminNote) + '</div>':'') + '<div class="meta">' + dt(d.createdAt) + '</div></div>').join("") + '</div>'
    : '<div class="empty-state"><div class="empty-icon">📭</div><p>No deposits yet.</p></div>';
}

async function doDeposit(e) {
  e.preventDefault();
  const amt = parseInt(document.getElementById("dAmt").value||"0");
  const txn = document.getElementById("dTxn").value.trim();
  const note = document.getElementById("dNote").value.trim();
  if (amt <= 0) { toast("Enter a valid amount","warn"); return; }
  try {
    await call("deposit_request", {body:{amount:amt*100, transactionId:txn, note}});
    toast("Deposit submitted! ✅","success");
    document.getElementById("dAmt").value = ""; document.getElementById("dTxn").value = ""; document.getElementById("dNote").value = "";
    loadDeposits();
  } catch(ex) { toast(ex.message,"danger"); }
}

// ── Orders Page ────────────────────────────────────────────────────────────
async function loadOrders() {
  try { const r = await call("my_orders", {method:"GET"}); S.myOrders = r.orders||[]; } catch {}
  const el = document.getElementById("ordersList"); if (!el) return;
  const orders = S.myOrders;
  el.innerHTML = orders.length
    ? '<div class="list-stack">' + orders.map(o => '<div class="list-item"><div class="list-head"><strong>' + esc(o.serviceName) + '</strong><span class="badge b-' + o.status + '">' + o.status + '</span></div><div class="meta">#' + esc(o.id) + ' · Qty: ' + Number(o.quantity).toLocaleString() + ' · Charge: ₹' + (Number(o.charge)/100).toFixed(2) + '</div><div class="meta">Link: <a href="' + esc(o.link) + '" target="_blank" style="color:var(--cyan)">' + esc(o.link.substring(0,30)) + '…</a></div><div class="meta">' + dt(o.createdAt) + '</div></div>').join("") + '</div>'
    : '<div class="empty-state"><div class="empty-icon">📦</div><p>No orders yet.<br><button class="btn-sm btn-grad" style="margin-top:10px" onclick="navigate(\'home\')">Browse Services</button></p></div>';
}

function renderOrders() {
  return '<div class="section-title">📦 My Orders</div><div id="ordersList"><div class="notice n-info">Loading…</div></div>';
}

// ── Profile Page ───────────────────────────────────────────────────────────
function renderProfile() {
  const u = S.profile;
  return '<div class="section-title">👤 Profile</div>' +
    '<div class="card"><div class="list-stack">' +
    '<div class="list-item"><div class="list-head"><strong>Username</strong></div><div class="meta">' + esc(u?.username||"") + '</div></div>' +
    '<div class="list-item"><div class="list-head"><strong>Email</strong></div><div class="meta">' + esc(u?.email||"") + '</div></div>' +
    '<div class="list-item"><div class="list-head"><strong>Balance</strong></div><div style="font-family:var(--font-head);font-size:1.5rem;color:var(--cyan);font-weight:700">₹' + (Number(u?.balance||0)/100).toFixed(2) + '</div></div>' +
    '<div class="list-item"><div class="list-head"><strong>Total Spent</strong></div><div class="meta">₹' + (Number(u?.totalSpent||0)/100).toFixed(2) + '</div></div>' +
    '<div class="list-item"><div class="list-head"><strong>Member Since</strong></div><div class="meta">' + dt(u?.createdAt) + '</div></div>' +
    '</div></div>';
}

// ── Admin Page ─────────────────────────────────────────────────────────────
function renderAdminPage() {
  const subtabs = [
    {key:"overview", label:"📊 Overview"},
    {key:"orders", label:"📋 Orders"},
    {key:"deposits", label:"💰 Deposits"},
    {key:"users", label:"👥 Users"},
    {key:"services", label:"⚙️ Services"},
    {key:"settings", label:"🔧 Settings"},
  ];
  let html = '<div class="admin-subtabs">';
  subtabs.forEach(t => {
    html += '<button class="admin-subtab ' + (S.adminSub===t.key?"active":"") + '" onclick="setAdminSub(\'' + t.key + '\')">' + t.label + '</button>';
  });
  html += '</div>';

  switch (S.adminSub) {
    case "overview": html += renderAdminOverview(); break;
    case "orders": html += renderAdminOrders(); break;
    case "deposits": html += renderAdminDeposits(); break;
    case "users": html += renderAdminUsers(); break;
    case "services": html += renderAdminServices(); break;
    case "settings": html += renderAdminSettings(); break;
  }
  return html;
}

function setAdminSub(sub) { S.adminSub = sub; renderPage(); }

function renderAdminOverview() {
  const st = S.adminData?.stats||{};
  return '<div class="stats-grid">' +
    '<div class="stat-card"><div class="stat-icon">👥</div><div class="stat-label">Users</div><div class="stat-value">' + (st.totalUsers||0) + '</div></div>' +
    '<div class="stat-card"><div class="stat-icon">📦</div><div class="stat-label">Orders</div><div class="stat-value">' + (st.totalOrders||0) + '</div></div>' +
    '<div class="stat-card"><div class="stat-icon">⏳</div><div class="stat-label">Pending</div><div class="stat-value" style="color:var(--amber)">' + (st.pendingOrders||0) + '</div></div>' +
    '<div class="stat-card"><div class="stat-icon">💰</div><div class="stat-label">Revenue</div><div class="stat-value" style="color:var(--cyan)">₹' + ((Number(st.totalRevenue||0))/100).toFixed(0) + '</div></div>' +
    '<div class="stat-card"><div class="stat-icon">💳</div><div class="stat-label">Pend. Deps</div><div class="stat-value" style="color:var(--pink)">' + (st.pendingDeps||0) + '</div></div>' +
  '</div>' +
  '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Recent Orders</div>' +
  '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>ID</th><th>User</th><th>Service</th><th>Qty</th><th>Status</th></tr></thead><tbody>' +
  ((S.adminData?.orders||[]).slice(0,10).map(o => '<tr><td><span class="mono">' + esc(o.id) + '</span></td><td>' + esc(o.username) + '</td><td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.serviceName) + '</td><td>' + Number(o.quantity).toLocaleString() + '</td><td><span class="badge b-' + o.status + '">' + o.status + '</span></td></tr>').join("") || '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:16px">No orders.</td></tr>') +
  '</tbody></table></div></div>';
}

function renderAdminOrders() {
  const orders = S.adminData?.orders || [];
  return '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">All Orders (' + orders.length + ')</div>' +
  '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>ID</th><th>User</th><th>Service</th><th>Qty</th><th>₹</th><th>Status</th><th>Act</th></tr></thead><tbody>' +
  (orders.map(o => '<tr><td><span class="mono">' + esc(o.id) + '</span></td><td>' + esc(o.username) + '</td><td style="max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(o.serviceName) + '</td><td>' + Number(o.quantity).toLocaleString() + '</td><td>₹' + (Number(o.charge)/100).toFixed(2) + '</td><td><span class="badge b-' + o.status + '">' + o.status + '</span></td><td><button class="btn-xs btn-ghost" onclick="adminEditOrder(\'' + o.id + '\')">Edit</button></td></tr>').join("") || '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">No orders.</td></tr>') +
  '</tbody></table></div></div>';
}

function renderAdminDeposits() {
  const deps = S.adminData?.deposits || [];
  return '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Deposits (' + deps.length + ')</div>' +
  '<div class="list-stack">' + (deps.length ? deps.map(d => '<div class="list-item"><div class="list-head"><strong>₹' + Number(d.amount/100).toFixed(2) + '</strong> <span style="color:var(--muted);font-size:0.8rem">@' + esc(d.username) + '</span><span class="badge b-' + d.status + '">' + d.status + '</span></div><div class="meta">UTR: <span class="mono">' + esc(d.transactionId) + '</span></div>' + (d.utrNote?'<div class="meta">Note: ' + esc(d.utrNote) + '</div>':'') + '<div class="meta">' + dt(d.createdAt) + '</div>' + (d.status==='pending'?'<div class="btn-row" style="margin-top:6px"><button class="btn-xs btn-success" onclick="adminProcessDep(\'' + d.id + '\',\'approve\')">✅ Approve</button><button class="btn-xs btn-danger" onclick="adminProcessDep(\'' + d.id + '\',\'reject\')">❌ Reject</button></div>':'') + '</div>').join("") : '<div class="empty-state"><p>No deposits.</p></div>') + '</div></div>';
}

function renderAdminUsers() {
  const users = S.adminData?.users || [];
  return '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Users (' + users.length + ')</div>' +
  '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Username</th><th>Email</th><th>Balance</th><th>Spent</th><th>Act</th></tr></thead><tbody>' +
  (users.map(u => '<tr><td><strong>' + esc(u.username) + '</strong></td><td>' + esc(u.email) + '</td><td style="color:var(--cyan);font-weight:700">₹' + (Number(u.balance)/100).toFixed(2) + '</td><td>₹' + (Number(u.totalSpent)/100).toFixed(2) + '</td><td><div class="btn-row"><button class="btn-xs btn-success" onclick="adminAddFunds(\'' + u.id + '\',\'' + esc(u.username) + '\')">+₹</button><button class="btn-xs btn-danger" onclick="adminDeleteUser(\'' + u.id + '\',\'' + esc(u.username) + '\')">Del</button></div></td></tr>').join("") || '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:16px">No users.</td></tr>') +
  '</tbody></table></div></div>';
}

function renderAdminServices() {
  const svcs = S.adminData?.services || [];
  const cats = S.adminData?.categories || [];
  return '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Services (' + svcs.length + ') <button class="btn-xs btn-grad" style="float:right" onclick="adminEditService(null)">+ Add</button></div>' +
  '<div class="list-stack">' + (svcs.length ? svcs.map(s => '<div class="list-item"><div class="list-head"><strong>' + esc(s.name) + '</strong><span class="badge ' + (s.active?'b-completed':'b-cancelled') + '">' + (s.active?'Active':'Off') + '</span></div><div class="meta">₹' + (Number(s.pricePerK)/100).toFixed(2) + '/1K · Min ' + s.minOrder + ' · Max ' + s.maxOrder + '</div><div class="btn-row" style="margin-top:4px"><button class="btn-xs btn-ghost" onclick="adminEditService(\'' + s.id + '\')">Edit</button><button class="btn-xs btn-danger" onclick="adminDeleteService(\'' + s.id + '\')">Delete</button></div></div>').join("") : '<div class="empty-state"><p>No services.</p></div>') + '</div>' +
  '<div class="card" style="margin-top:12px"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:10px">Categories <button class="btn-xs btn-ghost" style="float:right" onclick="adminEditCat(null)">+ Add</button></div>' +
  '<div class="list-stack">' + cats.map(c => '<div class="list-item"><div class="list-head"><strong>' + c.icon + ' ' + esc(c.name) + '</strong><button class="btn-xs btn-ghost" onclick="adminEditCat(\'' + c.id + '\')">Edit</button></div></div>').join("") + '</div></div>';
}

function renderAdminSettings() {
  const s = S.adminData?.settings || {};
  const qr = s.qrBase64||"";
  return '<div class="card"><div style="font-family:var(--font-head);font-weight:700;margin-bottom:12px">Settings</div>' +
  '<form class="form-stack" onsubmit="adminSaveSettings(event)">' +
  '<div class="field"><label>Site Name</label><input id="sSiteName" value="' + esc(s.siteName||"SocialGrow") + '" required></div>' +
  '<div class="field"><label>UPI ID</label><input id="sUpi" value="' + esc(s.upiId||"") + '"></div>' +
  '<div class="field"><label>Min Deposit (₹)</label><input id="sMinDep" type="number" value="' + (Number(s.minDeposit||50)) + '"></div>' +
  '<div class="field"><label>Welcome Bonus (₹)</label><input id="sBonus" type="number" min="0" value="' + (Number(s.welcomeBonus||0)) + '"></div>' +
  '<div class="field"><label>Notice</label><textarea id="sNotice">' + esc(s.notice||"") + '</textarea></div>' +
  '<div class="field"><label>QR Code</label>' + (qr?'<img src="' + qr + '" style="max-width:120px;border-radius:8px;margin-bottom:6px"><br>':'') +
  '<input type="file" id="sQrFile" accept="image/*" onchange="adminPickQr(event)"><img id="sQrPrev" style="max-width:120px;display:none;border-radius:8px;margin-top:6px"></div>' +
  '<button class="btn-full btn-grad" type="submit">Save Settings</button></form></div>';
}

// ── Admin Actions ──────────────────────────────────────────────────────────
async function adminRefresh() {
  try { S.adminData = await call("admin_data", {method:"GET"}); renderPage(); toast("Refreshed","success"); }
  catch(ex) { toast(ex.message,"danger"); }
}

function adminEditOrder(id) {
  const o = (S.adminData?.orders||[]).find(x => x.id === id); if (!o) return;
  let html = '<div class="notice n-info" style="margin-bottom:12px"><strong>' + esc(o.id) + '</strong><br>' + esc(o.serviceName) + '<br>User: @' + esc(o.username) + '<br>Link: ' + esc(o.link) + '</div>';
  html += '<form class="form-stack" onsubmit="adminDoUpdateOrder(event,\'' + id + '\')">';
  html += '<div class="field"><label>Status</label><select id="oSt">';
  ['pending','processing','completed','partial','cancelled'].forEach(st => {
    html += '<option value="' + st + '" ' + (o.status===st?'selected':'') + '>' + st + '</option>';
  });
  html += '</select></div>';
  html += '<div class="field"><label>Start Count</label><input id="oSc" type="number" value="' + o.startCount + '"></div>';
  html += '<div class="field"><label>Remains</label><input id="oRem" type="number" value="' + o.remains + '"></div>';
  html += '<div class="field"><label>Admin Note</label><textarea id="oNote">' + esc(o.adminNote||"") + '</textarea></div>';
  html += '<button class="btn-full btn-grad" type="submit">Update Order</button></form>';
  openSheet("Edit Order #" + esc(o.id), html);
}

async function adminDoUpdateOrder(e, id) {
  e.preventDefault();
  try {
    await call("update_order", {body:{id, status:document.getElementById("oSt").value, startCount:parseInt(document.getElementById("oSc").value||"0"), remains:parseInt(document.getElementById("oRem").value||"0"), adminNote:document.getElementById("oNote").value.trim()}});
    toast("Updated","success"); closeSheet(); await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

async function adminProcessDep(id, action) {
  const note = action==="reject" ? (prompt("Rejection reason:")||"") : "";
  try {
    await call("approve_deposit", {body:{id, action, note}});
    toast(action==="approve"?"Approved!":"Rejected", action==="approve"?"success":"danger");
    await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

function adminAddFunds(uid, uname) {
  let html = '<div class="notice n-info" style="margin-bottom:12px">Adding funds to: <strong>@' + esc(uname) + '</strong></div>';
  html += '<form class="form-stack" onsubmit="adminDoAddFunds(event,\'' + uid + '\')">';
  html += '<div class="field"><label>Amount (₹)</label><input id="afAmt" type="number" min="1" required></div>';
  html += '<button class="btn-full btn-success" type="submit">Add Funds</button></form>';
  openSheet("Add Funds", html);
}

async function adminDoAddFunds(e, uid) {
  e.preventDefault();
  const amt = parseInt(document.getElementById("afAmt").value||"0");
  if (amt <= 0) { toast("Invalid amount","warn"); return; }
  try {
    await call("add_funds", {body:{userId:uid, amount:amt*100}});
    toast("₹" + amt + " added","success"); closeSheet(); await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

async function adminDeleteUser(id, uname) {
  if (!confirm("Delete @" + uname + " permanently?")) return;
  try { await call("delete_user_admin", {body:{id}}); toast("Deleted","success"); await adminRefresh(); }
  catch(ex) { toast(ex.message,"danger"); }
}

function adminEditService(id) {
  const svc = id ? (S.adminData?.services||[]).find(s => s.id === id) : null;
  const cats = S.adminData?.categories||[];
  let html = '<form class="form-stack" onsubmit="adminDoSaveService(event,\'' + (id||"") + '\')">';
  html += '<div class="field"><label>Category</label><select id="svCat" required>' + cats.map(c => '<option value="' + c.id + '" ' + (svc?.categoryId===c.id?'selected':'') + '>' + c.icon + ' ' + esc(c.name) + '</option>').join("") + '</select></div>';
  html += '<div class="field"><label>Name</label><input id="svNm" required value="' + esc(svc?.name||"") + '"></div>';
  html += '<div class="field"><label>Description</label><textarea id="svDsc">' + esc(svc?.description||"") + '</textarea></div>';
  html += '<div class="field"><label>Price per 1000 (paise)</label><input id="svPpk" type="number" min="1" value="' + (svc?.pricePerK||100) + '"></div>';
  html += '<div class="field"><label>Min Order</label><input id="svMin" type="number" min="1" value="' + (svc?.minOrder||100) + '"></div>';
  html += '<div class="field"><label>Max Order</label><input id="svMax" type="number" min="1" value="' + (svc?.maxOrder||10000) + '"></div>';
  html += '<div class="field"><label>Avg Delivery</label><input id="svDel" value="' + esc(svc?.avgDelivery||"1-3 days") + '"></div>';
  html += '<div class="field"><label>Active</label><select id="svAct"><option value="1" ' + (svc?.active!=0?'selected':'') + '>Yes</option><option value="0" ' + (svc?.active==0?'selected':'') + '>No</option></select></div>';
  html += '<button class="btn-full btn-grad" type="submit">' + (svc?"Save":"Add Service") + '</button></form>';
  openSheet(svc?"Edit Service":"Add Service", html);
}

async function adminDoSaveService(e, id) {
  e.preventDefault();
  try {
    await call("save_service", {body:{id:id||undefined, categoryId:document.getElementById("svCat").value, name:document.getElementById("svNm").value.trim(), description:document.getElementById("svDsc").value.trim(), pricePerK:parseInt(document.getElementById("svPpk").value||"0"), minOrder:parseInt(document.getElementById("svMin").value||"0"), maxOrder:parseInt(document.getElementById("svMax").value||"0"), avgDelivery:document.getElementById("svDel").value.trim(), active:parseInt(document.getElementById("svAct").value)}});
    toast("Saved","success"); closeSheet(); await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

async function adminDeleteService(id) {
  if (!confirm("Delete this service?")) return;
  try { await call("delete_service", {body:{id}}); toast("Deleted","success"); await adminRefresh(); }
  catch(ex) { toast(ex.message,"danger"); }
}

function adminEditCat(id) {
  const cat = id ? (S.adminData?.categories||[]).find(c => c.id === id) : null;
  let html = '<form class="form-stack" onsubmit="adminDoSaveCat(event,\'' + (id||"") + '\')">';
  html += '<div class="field"><label>Name</label><input id="catNm" required value="' + esc(cat?.name||"") + '"></div>';
  html += '<div class="field"><label>Icon (emoji)</label><input id="catIco" value="' + esc(cat?.icon||"📦") + '"></div>';
  html += '<div class="field"><label>Sort Order</label><input id="catSort" type="number" value="' + (cat?.sortOrder||0) + '"></div>';
  html += '<button class="btn-full btn-grad" type="submit">' + (cat?"Save":"Add") + '</button></form>';
  openSheet(cat?"Edit Category":"Add Category", html);
}

async function adminDoSaveCat(e, id) {
  e.preventDefault();
  try {
    await call("save_category", {body:{id:id||undefined, name:document.getElementById("catNm").value.trim(), icon:document.getElementById("catIco").value.trim(), sortOrder:parseInt(document.getElementById("catSort").value||"0")}});
    toast("Saved","success"); closeSheet(); await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

async function adminPickQr(e) {
  const file = e.target.files[0]; if (!file) return;
  try {
    S._qrPend = await compressImage(file, 500, 0.85);
    const p = document.getElementById("sQrPrev"); if(p){p.src=S._qrPend;p.style.display="block";}
  } catch { toast("Image error","danger"); }
}

async function adminSaveSettings(e) {
  e.preventDefault();
  const qrBase64 = S._qrPend !== null ? S._qrPend : (S.adminData?.settings?.qrBase64||"");
  try {
    await call("save_settings", {body:{
      siteName:document.getElementById("sSiteName").value.trim(),
      upiId:document.getElementById("sUpi").value.trim(),
      minDeposit:parseInt(document.getElementById("sMinDep").value||"50"),
      welcomeBonus:parseInt(document.getElementById("sBonus").value||"0")*100,
      notice:document.getElementById("sNotice").value.trim(),
      qrBase64
    }});
    S._qrPend = null;
    toast("Settings saved!","success"); await adminRefresh();
  } catch(ex) { toast(ex.message,"danger"); }
}

// ── Auth Screens ───────────────────────────────────────────────────────────
let _authTab = "login";

function renderAuthForms() {
  const c = document.getElementById("content");
  const labels = {login:"Login", register:"Register", admin:"Admin"};
  const noAdmin = (S.boot?.adminCount||0) === 0;

  let html = '<div class="auth-screen">';
  html += '<div class="auth-hero"><div style="font-size:3rem;margin-bottom:8px">🚀</div><h1>Grow Your<br><em>Social Media</em></h1><p>Fast, reliable SMM panel. Instagram, YouTube, TikTok & more.</p></div>';
  html += '<div class="auth-card">';
  html += '<div class="auth-tabs">';
  ["login","register","admin"].forEach(k => {
    html += '<button class="auth-tab ' + (_authTab===k?"active":"") + '" onclick="setAuthTab(\'' + k + '\')">' + labels[k] + '</button>';
  });
  html += '</div>';

  if (_authTab === "register") {
    html += '<form class="form-stack" onsubmit="doRegister(event)">';
    html += '<div class="field"><label>Username</label><input id="ru" required minlength="3" placeholder="Choose username"></div>';
    html += '<div class="field"><label>Email</label><input id="re" type="email" required placeholder="your@email.com"></div>';
    html += '<div class="field"><label>Password</label><input id="rp" type="password" minlength="6" required placeholder="Min 6 chars"></div>';
    html += '<button class="btn-full btn-grad" type="submit">Create Free Account</button>';
    html += '<p class="field-note">🎁 Free bonus on signup!</p></form>';
  } else if (_authTab === "admin") {
    html += '<form class="form-stack" onsubmit="doAdminLogin(event)">';
    html += '<div class="field"><label>Admin Username</label><input id="au" required></div>';
    html += '<div class="field"><label>Password</label><input id="ap" type="password" required></div>';
    html += '<button class="btn-full btn-grad" type="submit">Admin Login</button></form>';
    if (noAdmin) {
      html += '<div class="divider"></div><div class="notice n-warn" style="margin-bottom:10px">No admin exists yet.</div>';
      html += '<form class="form-stack" onsubmit="doSetupAdmin(event)">';
      html += '<div class="field"><label>Display Name</label><input id="sdn" required></div>';
      html += '<div class="field"><label>Username</label><input id="sun" required minlength="3"></div>';
      html += '<div class="field"><label>Password (min 8)</label><input id="spw" type="password" minlength="8" required></div>';
      html += '<button class="btn-full btn-warn" type="submit">Create First Admin</button></form>';
    }
  } else {
    html += '<form class="form-stack" onsubmit="doLogin(event)">';
    html += '<div class="field"><label>Username</label><input id="lu" required placeholder="Your username"></div>';
    html += '<div class="field"><label>Password</label><input id="lp" type="password" required></div>';
    html += '<button class="btn-full btn-grad" type="submit">Login</button></form>';
    html += '<p class="field-note">New here? Switch to Register ↑</p>';
  }
  html += '</div></div>';
  c.innerHTML = html;
}

function setAuthTab(t) { _authTab = t; renderAuthForms(); }

async function doRegister(e) {
  e.preventDefault();
  try {
    await call("register", {body:{username:document.getElementById("ru").value.trim(), email:document.getElementById("re").value.trim(), password:document.getElementById("rp").value}});
    toast("Account created! Login now.","success"); setAuthTab("login");
  } catch(ex) { toast(ex.message,"danger"); }
}

async function doLogin(e) {
  e.preventDefault();
  try {
    const p = await call("login", {body:{username:document.getElementById("lu").value.trim(), password:document.getElementById("lp").value}});
    S.token = p.token; S.role = "user"; S.profile = p.user;
    saveSession(); await enterApp(); toast("Welcome, " + p.user.username + "!","success");
  } catch(ex) { toast(ex.message,"danger"); }
}

async function doAdminLogin(e) {
  e.preventDefault();
  try {
    const p = await call("login_admin", {body:{username:document.getElementById("au").value.trim(), password:document.getElementById("ap").value}});
    S.token = p.token; S.role = "admin"; S.profile = p.admin;
    saveSession(); await enterApp(); toast("Admin panel ready","success");
  } catch(ex) { toast(ex.message,"danger"); }
}

async function doSetupAdmin(e) {
  e.preventDefault();
  try {
    await call("setup_admin", {body:{displayName:document.getElementById("sdn").value.trim(), username:document.getElementById("sun").value.trim(), password:document.getElementById("spw").value}});
    toast("Admin created!","success"); await boot();
  } catch(ex) { toast(ex.message,"danger"); }
}

// ── Enter App ──────────────────────────────────────────────────────────────
async function enterApp() {
  document.getElementById("content").innerHTML = "";
  document.getElementById("bottomNav").style.display = "flex";
  document.getElementById("signOutBtn").style.display = "";
  S.adminMode = S.role === "admin";
  S.view = "home";

  const navAdmin = document.getElementById("navAdmin");
  if (S.role === "admin") { navAdmin.classList.remove("hidden"); }
  else { navAdmin.classList.add("hidden"); }

  await refreshAll();
  updateBalanceChip();
  navigate("home");
}

async function refreshAll() {
  S.boot = await call("boot", {method:"GET"}).catch(() => S.boot);
  if (S.adminMode) {
    try { S.adminData = await call("admin_data", {method:"GET"}); } catch {}
  }
  if (S.role === "user") {
    try { const me = await call("me", {method:"GET"}); S.profile = me.profile; } catch {}
  }
  document.getElementById("siteNameEl").textContent = S.boot?.settings?.siteName || "SocialGrow";
  updateBalanceChip();
}

function doLogout() {
  clearSession();
  document.getElementById("bottomNav").style.display = "none";
  document.getElementById("signOutBtn").style.display = "none";
  document.getElementById("balanceChip").classList.add("hidden");
  closeSheet();
  renderAuthForms();
}

// ── Init ───────────────────────────────────────────────────────────────────
(async () => {
  await boot();
  await restoreSession();
})();
</script>
</body>
</html>
HTML;
}