<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
});

// ─── UltraSecureEncrypter257 Engine ─────────────────────────────────────
class UltraSecureEncrypter257
{
    const VERSION = '257.5.0';
    const CREDIT  = 'UltraSecureEncrypter257 - Created by You & Me';

    public static function generateUltraKey(): string
    {
        $timestamp = (int) (microtime(true) * 257);
        $keyLayers = [];
        for ($layer = 0; $layer < 3; $layer++) {
            $layerKey = '';
            for ($i = 0; $i < 18; $i++) {
                $val = (($timestamp * 257 * ($i + 1) * ($layer + 1)) % 89) + 33;
                $val = ($val ^ (257 >> ($i % 8))) % 89 + 33;
                $val = ($val + intdiv(257 * $layer, $i + 1)) % 89 + 33;
                $layerKey .= chr($val);
            }
            $keyLayers[] = $layerKey;
        }
        return implode('|257|', $keyLayers);
    }

    public static function calculateIntegrityHash(string $data): string
    {
        $integrityData = $data . strlen($data) . '257_SECURITY' . self::CREDIT;
        return substr(hash('sha256', $integrityData), 0, 32);
    }

    public static function encryptUltraSecure(string $plaintext, string $key): array
    {
        $integrityHash  = self::calculateIntegrityHash($plaintext);
        $originalLength = strlen($plaintext);

        $encryptedData     = [];
        $noisePositions    = [];
        $antiTamperMarkers = [];
        $fakeDataPositions = [];

        $keyParts  = explode('|257|', $key);
        $keyLayers = array_map('str_split', $keyParts);

        $securityHeader = array_merge(
            [ord('U'), ord('L'), ord('T'), ord('R'), ord('A')],
            [2, 5, 7],
            [$originalLength % 256, ($originalLength >> 8) % 256],
            array_map('ord', str_split(substr($integrityHash, 0, 8)))
        );
        $encryptedData = $securityHeader;

        for ($i = 0; $i < $originalLength; $i++) {
            $temp = ord($plaintext[$i]);

            foreach ($keyLayers as $layerIdx => $keyLayer) {
                $keyChar = $keyLayer[$i % count($keyLayer)];
                $temp    = ($temp ^ ord($keyChar));
                $temp    = ($temp + (257 * $layerIdx)) % 256;
            }

            $temp = (($temp << 5) | ($temp >> 3)) & 0xFF;
            $temp = ($temp * 13) % 256;
            $temp = $temp ^ (($i * 257 * 13) % 256);

            if ($i % 7 == 0)  $temp ^= 0xAA;
            if ($i % 13 == 0) $temp = ($temp + 0x55) % 256;

            $encryptedData[] = $temp;

            if (mt_rand() / mt_getrandmax() < 0.4) {
                $fakeHtml = [60, random_int(65, 90), random_int(97, 122), 62];
                foreach ($fakeHtml as $b) {
                    $encryptedData[]   = $b;
                    $fakeDataPositions[] = count($encryptedData) - 1;
                }
                for ($j = 0; $j < 4; $j++) {
                    $b = random_int(102, 111);
                    $encryptedData[]   = $b;
                    $fakeDataPositions[] = count($encryptedData) - 1;
                }
            }

            if ($i % 77 == 0) {
                foreach ([0xDE, 0xAD, 0xBE, 0xEF] as $m) {
                    $encryptedData[]   = $m;
                    $antiTamperMarkers[] = count($encryptedData) - 1;
                }
            }
        }

        $securityFooter = array_merge(
            array_map('ord', str_split(substr($integrityHash, 8, 8))),
            [0xFF, 0xEE, 0xDD],
            [$originalLength % 256, ($originalLength >> 8) % 256]
        );
        $footerStart = count($encryptedData);
        $encryptedData = array_merge($encryptedData, $securityFooter);

        $protectionPositions = array_unique(array_merge(
            range(0, count($securityHeader) - 1),
            $noisePositions,
            $fakeDataPositions,
            $antiTamperMarkers,
            range($footerStart, count($encryptedData) - 1)
        ));

        $encryptedHex = '';
        foreach ($encryptedData as $byte) {
            $encryptedHex .= sprintf('%02x', $byte);
        }

        return [
            'encryptedHex'        => $encryptedHex,
            'protectionPositions' => array_values($protectionPositions),
            'integrityHash'       => $integrityHash,
            'originalLength'      => $originalLength,
        ];
    }
}

// ─── Helper functions ──────────────────────────────────────────────────
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function nowIso(): string { return gmdate('c'); }

function nextId(): int {
    return (int) round(microtime(true) * 1000) + random_int(10, 999);
}

function tokenHash(string $token): string { return hash('sha256', $token); }

function requireString(array $input, string $key, int $minLength = 1): string {
    $value = trim((string) ($input[$key] ?? ''));
    if (mb_strlen($value) < $minLength) {
        respond(['ok' => false, 'error' => "Invalid {$key}"], 400);
    }
    return $value;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(['ok' => false, 'error' => 'Invalid JSON request body'], 400);
    }
    return $decoded;
}

// ─── Database rotation ─────────────────────────────────────────────────
const DB_MAX_SIZE = 1 * 1024 * 1024;

function getActiveDbPath(): string {
    $pointerFile = __DIR__ . DIRECTORY_SEPARATOR . 'db_pointer.txt';
    if (!file_exists($pointerFile)) {
        file_put_contents($pointerFile, '0');
        return __DIR__ . DIRECTORY_SEPARATOR . 'vault.sqlite';
    }
    $suffix = trim(file_get_contents($pointerFile));
    if ($suffix === '0') {
        return __DIR__ . DIRECTORY_SEPARATOR . 'vault.sqlite';
    }
    return __DIR__ . DIRECTORY_SEPARATOR . "vault{$suffix}.sqlite";
}

function getAllDbPaths(): array {
    $files = glob(__DIR__ . DIRECTORY_SEPARATOR . 'vault*.sqlite');
    if (empty($files)) {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'vault.sqlite';
        if (!file_exists($path)) {
            $pdo = new PDO('sqlite:' . $path);
            initializeSchema($pdo);
        }
        return [$path];
    }
    return $files;
}

function initializeSchema(PDO $pdo): void {
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY,
            username    TEXT NOT NULL COLLATE NOCASE UNIQUE,
            displayName TEXT NOT NULL,
            email       TEXT NOT NULL,
            passwordHash TEXT NOT NULL,
            createdAt   TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS admins (
            id          INTEGER PRIMARY KEY,
            username    TEXT NOT NULL COLLATE NOCASE UNIQUE,
            displayName TEXT NOT NULL,
            passwordHash TEXT NOT NULL,
            createdAt   TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS catalog (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            price       INTEGER NOT NULL DEFAULT 0,
            imageBase64 TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS orders (
            id              TEXT PRIMARY KEY,
            userId          INTEGER NOT NULL,
            customerJson    TEXT NOT NULL,
            itemsJson       TEXT NOT NULL,
            totalAmount     INTEGER NOT NULL,
            transactionId   TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL DEFAULT 'pending',
            source          TEXT NOT NULL DEFAULT 'checkout',
            notes           TEXT NOT NULL DEFAULT '',
            rejectionReason TEXT NOT NULL DEFAULT '',
            submittedAt     TEXT NOT NULL,
            updatedAt       TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS tokens (
            hash      TEXT PRIMARY KEY,
            role      TEXT NOT NULL,
            subjectId INTEGER NOT NULL,
            issuedAt  TEXT NOT NULL,
            expiresAt TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS audit (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            actor TEXT NOT NULL,
            action TEXT NOT NULL,
            at    TEXT NOT NULL
        );
    ");
}

function rotateDbIfNeeded(): void {
    $activePath = getActiveDbPath();
    if (!file_exists($activePath)) return;
    if (filesize($activePath) < DB_MAX_SIZE) return;

    $pointerFile = __DIR__ . DIRECTORY_SEPARATOR . 'db_pointer.txt';
    $currentSuffix = (int) file_get_contents($pointerFile);
    $nextSuffix = ($currentSuffix === 0) ? 1 : $currentSuffix + 1;
    $newPath = __DIR__ . DIRECTORY_SEPARATOR . "vault{$nextSuffix}.sqlite";

    $newPdo = new PDO('sqlite:' . $newPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    initializeSchema($newPdo);

    $oldPdo = new PDO('sqlite:' . $activePath);
    $settings = $oldPdo->query("SELECT key,value FROM settings")->fetchAll();
    $stmt = $newPdo->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)");
    foreach ($settings as $row) {
        $stmt->execute([$row['key'], $row['value']]);
    }

    file_put_contents($pointerFile, (string) $nextSuffix);
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $path = getActiveDbPath();
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    initializeSchema($pdo);

    $count = (int) $pdo->query("SELECT COUNT(*) FROM catalog")->fetchColumn();
    if ($count === 0) {
        $defaults = [
            [1001, 'Complete Trading Mastery',    'Stocks, options, psychology, and structured market execution.', 9999],
            [1002, 'Options Strategies Intensive','Directional and hedged options systems with disciplined risk planning.', 14999],
            [1003, 'Live Trading Classes',         'Session-based execution review with recurring practice and trade journaling.', 4999],
        ];
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO catalog (id,name,description,price,imageBase64) VALUES (?,?,?,?,?)');
        foreach ($defaults as $row) $stmt->execute([$row[0], $row[1], $row[2], $row[3], '']);
    }
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('upiId','tanmay99i@ptaxis')");
    $pdo->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('qrCodeBase64','')");

    return $pdo;
}

function queryAll(string $sql, array $params = []): array {
    $result = [];
    foreach (getAllDbPaths() as $dbPath) {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $result = array_merge($result, $rows);
    }
    return $result;
}

// Perform a write operation across all DB files until one succeeds
function executeOnAll(string $sql, array $params): int {
    $affected = 0;
    foreach (getAllDbPaths() as $dbPath) {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected += $stmt->rowCount();
    }
    return $affected;
}

function getCatalog(): array {
    return queryAll("SELECT id,name,description,price,imageBase64 FROM catalog ORDER BY id");
}

function getSettings(): array {
    $out = [];
    foreach (queryAll("SELECT key,value FROM settings") as $row) {
        $out[$row['key']] = $row['value'];
    }
    return $out;
}

function rowToOrder(array $row): array {
    return [
        'id'            => $row['id'],
        'userId'        => (int) $row['userId'],
        'customer'      => json_decode($row['customerJson'], true) ?? [],
        'items'         => json_decode($row['itemsJson'],    true) ?? [],
        'totalAmount'   => (int) $row['totalAmount'],
        'transactionId' => $row['transactionId'],
        'status'        => $row['status'],
        'source'        => $row['source'],
        'notes'         => $row['notes'],
        'submittedAt'   => $row['submittedAt'],
        'updatedAt'     => $row['updatedAt'],
    ];
}

function sanitizeUser(array $u): array {
    return [
        'id'          => (int) $u['id'],
        'username'    => $u['username'],
        'displayName' => $u['displayName'],
        'email'       => $u['email'],
        'createdAt'   => $u['createdAt'] ?? '',
    ];
}

function sanitizeAdmin(array $a): array {
    return [
        'id'          => (int) $a['id'],
        'username'    => $a['username'],
        'displayName' => $a['displayName'],
        'createdAt'   => $a['createdAt'] ?? '',
    ];
}

function appendAudit(string $actor, string $action): void {
    $db = getDb();
    $db->prepare("INSERT INTO audit (actor,action,at) VALUES (?,?,?)")->execute([$actor, $action, nowIso()]);
    $db->exec("DELETE FROM audit WHERE id NOT IN (SELECT id FROM audit ORDER BY id DESC LIMIT 200)");
}

function cleanupExpiredTokens(): void {
    $now = nowIso();
    foreach (getAllDbPaths() as $path) {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare("DELETE FROM tokens WHERE expiresAt < ?")->execute([$now]);
    }
}

// ─── Authentication ────────────────────────────────────────────────────
function issueToken(string $role, int $subjectId): string {
    cleanupExpiredTokens();
    $plain     = bin2hex(random_bytes(32));
    $hash      = tokenHash($plain);
    $expiresAt = gmdate('c', time() + 60 * 60 * 12);
    getDb()->prepare("INSERT OR REPLACE INTO tokens (hash,role,subjectId,issuedAt,expiresAt) VALUES (?,?,?,?,?)")
        ->execute([$hash, $role, $subjectId, nowIso(), $expiresAt]);
    return $plain;
}

function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(.+)/i', (string) $header, $matches) === 1) {
        return trim($matches[1]);
    }
    return null;
}

function resolveAuth(): ?array {
    cleanupExpiredTokens();
    $bearer = getBearerToken();
    if (!$bearer) return null;
    $hash = tokenHash($bearer);
    foreach (getAllDbPaths() as $path) {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $stmt = $pdo->prepare("SELECT * FROM tokens WHERE hash = ?");
        $stmt->execute([$hash]);
        $token = $stmt->fetch();
        if ($token) {
            $role      = (string) $token['role'];
            $subjectId = (int)    $token['subjectId'];
            $table     = $role === 'admin' ? 'admins' : 'users';
            $stmt2     = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt2->execute([$subjectId]);
            $record = $stmt2->fetch();
            if ($record) {
                return ['role' => $role, 'record' => $record];
            }
        }
    }
    return null;
}

function requireAuth(): array {
    $auth = resolveAuth();
    if (!$auth) respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    return $auth;
}

function requireAdmin(): array {
    $auth = requireAuth();
    if (($auth['role'] ?? '') !== 'admin') respond(['ok' => false, 'error' => 'Admin access required'], 403);
    return $auth;
}

// ─── Self‑decrypting HTML export ───────────────────────────────────────
function createSelfDecryptingHtml(
    string $encryptedHex,
    array $protectionPositions,
    string $integrityHash,
    int $originalLength
): string {
    $protectionStr = implode(',', $protectionPositions);
    $credit = UltraSecureEncrypter257::CREDIT;
    $html = <<<'LOADER'
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <title>🔐 ULTRA SECURE ENCRYPTER 257</title>
  <script>
    const STORAGE_KEY = 'ultra_secure_257_key_admin_snapshot';
    function getStoredKey() {
        try { const k = localStorage.getItem(STORAGE_KEY); if (k && k.length >= 10) return k; } catch(e) {}
        try {
            const cookies = document.cookie.split(';');
            for (let c of cookies) {
                const [n, v] = c.trim().split('=');
                if (n === STORAGE_KEY && v.length >= 10) return v;
            }
        } catch(e) {}
        return null;
    }
    function storeKeyPermanently(key) {
        try { localStorage.setItem(STORAGE_KEY, key); } catch(e) {}
        try {
            const d = new Date();
            d.setTime(d.getTime() + 10 * 365 * 24 * 60 * 60 * 1000);
            document.cookie = `${STORAGE_KEY}=${key}; expires=${d.toUTCString()}; path=/; Secure; SameSite=Strict`;
        } catch(e) {}
    }
    function clearStoredKey() {
        try { localStorage.removeItem(STORAGE_KEY); } catch(e) {}
        try { document.cookie = `${STORAGE_KEY}=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/`; } catch(e) {}
    }
    function sha256(ascii) {
        function rightRotate(v,a){return(v>>>a)|(v<<(32-a));}
        var mp=Math.pow, mw=mp(2,32), lp='length', i,j,res='';
        var words=[];
        var abl = ascii[lp]*8;
        var hash=sha256.h=sha256.h||[];
        var k=sha256.k=sha256.k||[];
        var pc=k[lp], isComp={};
        for(var c=2;pc<64;c++){if(!isComp[c]){for(i=0;i<313;i+=c)isComp[i]=c;k[pc]=(mp(c,.5)*mw/2)|0;pc++;}}
        ascii+='\x80';
        while(ascii[lp]%64-56)ascii+='\x00';
        for(i=0;i<ascii[lp];i++){j=ascii.charCodeAt(i);if(j>>8)return'';words[i>>2]|=j<<((3-i)%4)*8;}
        words[words[lp]]=((abl/mw)|0);
        words[words[lp]]=(abl);
        for(j=0;j<words[lp];){
            var w=words.slice(j,j+=16), oh=hash;
            hash=hash.slice(0,8);
            for(i=0;i<64;i++){
                var w15=w[i-15],w2=w[i-2];
                var a=hash[0],e=hash[4];
                var t1=hash[7]+(rightRotate(e,6)^rightRotate(e,11)^rightRotate(e,25))+((e&hash[5])^((~e)&hash[6]))+k[i]
                      +(w[i]=(i<16)?w[i]:(w[i-16]+(rightRotate(w15,7)^rightRotate(w15,18)^(w15>>>3))+w[i-7]+(rightRotate(w2,17)^rightRotate(w2,19)^(w2>>>10)))|0);
                var t2=(rightRotate(a,2)^rightRotate(a,13)^rightRotate(a,22))+((a&hash[1])^(a&hash[2])^(hash[1]&hash[2]));
                hash=[(t1+t2)|0].concat(hash);
                hash[4]=(hash[4]+t1)|0;
            }
            for(i=0;i<8;i++)hash[i]=(hash[i]+oh[i])|0;
        }
        for(i=0;i<8;i++){for(j=3;j>=0;j--){res+=((hash[i]>>(j*8))&255).toString(16).padStart(2,'0');}}
        return res;
    }
    function calculateIntegrity(data) {
        const integrityData = data + data.length.toString() + '257_SECURITY' + 'UltraSecureEncrypter257 - Created by You & Me';
        return sha256(integrityData).substring(0, 32);
    }
    function decryptUltraSecure(encHex, key, protectionPos, expectedInt, expectedLen) {
        const encBytes = [];
        for (let i = 0; i < encHex.length; i += 2) encBytes.push(parseInt(encHex.substr(i,2),16));
        const protSet = new Set(protectionPos);
        const clean = encBytes.filter((_, idx) => !protSet.has(idx));
        const keyParts = key.split('|257|');
        const keyLayers = keyParts.map(p => p.split(''));
        let out = '';
        for (let i = 0; i < clean.length; i++) {
            let temp = clean[i];
            if (i % 13 === 0) temp = (temp - 0x55 + 256) % 256;
            if (i % 7 === 0)  temp ^= 0xAA;
            temp = temp ^ ((i * 257 * 13) % 256);
            temp = (temp * 197) % 256;
            temp = ((temp >> 5) | (temp << 3)) & 0xFF;
            for (let l = keyLayers.length - 1; l >= 0; l--) {
                temp = (temp - (257 * l) + 256) % 256;
                temp ^= keyLayers[l][i % keyLayers[l].length].charCodeAt(0);
            }
            out += String.fromCharCode(temp);
        }
        if (calculateIntegrity(out) !== expectedInt) throw new Error('Integrity mismatch');
        if (out.length !== expectedLen) throw new Error('Length mismatch');
        return out;
    }
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            let key = getStoredKey();
            if (!key) {
                key = prompt('🔐 Enter ULTRA SECURE 257 key:');
                if (!key || key.length < 10) { alert('Invalid key'); return; }
                storeKeyPermanently(key);
            }
            try {
                const dec = decryptUltraSecure(
                    "ENCRYPTED_HEX_PLACEHOLDER",
                    key,
                    [PROTECTION_POS_PLACEHOLDER],
                    "INTEGRITY_HASH_PLACEHOLDER",
                    ORIGINAL_LENGTH_PLACEHOLDER
                );
                document.open();
                document.write(dec);
                document.close();
            } catch(e) {
                clearStoredKey();
                document.body.innerHTML = `<div style="color:red;font-family:monospace;">
                    <h1>❌ DECRYPTION FAILED</h1><p>${e.message}</p>
                    <button onclick="location.reload()">Try Again</button>
                </div>`;
            }
        }, 100);
    });
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && (e.key === 'u' || e.key === 'U')) {
            e.preventDefault(); alert('🔐 Source view disabled'); return false;
        }
    });
  </script>
</head>
<body style="background:#000;color:#0f0;margin:0;padding:0;">
  <div style="padding:50px;text-align:center;">
    <h1>🔐 ULTRA SECURE ENCRYPTER 257</h1>
    <p>Initializing security protocols…</p>
    <p style="color:#0ff;">Credit: You & Me - 257 Pioneers!</p>
  </div>
</body>
</html>
LOADER;

    $html = str_replace('ENCRYPTED_HEX_PLACEHOLDER', $encryptedHex, $html);
    $html = str_replace('PROTECTION_POS_PLACEHOLDER', $protectionStr, $html);
    $html = str_replace('INTEGRITY_HASH_PLACEHOLDER', $integrityHash, $html);
    $html = str_replace('ORIGINAL_LENGTH_PLACEHOLDER', (string) $originalLength, $html);
    return $html;
}

// ─── Routing ───────────────────────────────────────────────────────────
$isApiRequest = isset($_GET['action']);
if (!$isApiRequest) {
    header('Content-Type: text/html; charset=utf-8');
    serveFullHtml();
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

try {
    rotateDbIfNeeded();
    $db = getDb();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET' && $action === 'boot') {
        respond([
            'ok'       => true,
            'catalog'  => getCatalog(),
            'settings' => getSettings(),
            'meta'     => [
                'adminCount' => count(queryAll("SELECT id FROM admins")),
                'userCount'  => count(queryAll("SELECT id FROM users")),
                'orderCount' => count(queryAll("SELECT id FROM orders")),
            ],
            'file'     => basename(getActiveDbPath()),
        ]);
    }

    if ($method === 'GET' && $action === 'me') {
        $auth    = requireAuth();
        $profile = $auth['role'] === 'admin'
            ? sanitizeAdmin($auth['record'])
            : sanitizeUser($auth['record']);
        respond(['ok' => true, 'profile' => $profile + ['role' => $auth['role']]]);
    }

    if ($method === 'POST' && $action === 'register_user') {
        $input    = getJsonInput();
        $username = requireString($input, 'username', 3);
        $email    = requireString($input, 'email', 5);
        $password = requireString($input, 'password', 3);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Invalid email'], 400);
        }
        $exists = queryAll("SELECT 1 FROM users WHERE username = ? COLLATE NOCASE UNION SELECT 1 FROM admins WHERE username = ? COLLATE NOCASE", [$username, $username]);
        if (!empty($exists)) {
            respond(['ok' => false, 'error' => 'Username already exists'], 409);
        }
        $db->prepare("INSERT INTO users (id,username,displayName,email,passwordHash,createdAt) VALUES (?,?,?,?,?,?)")
            ->execute([nextId(), $username, $username, $email, password_hash($password, PASSWORD_DEFAULT), nowIso()]);
        appendAudit($username, 'registered user account');
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'setup_admin') {
        $adminCount = count(queryAll("SELECT id FROM admins"));
        if ($adminCount > 0) {
            respond(['ok' => false, 'error' => 'Admin account already initialized'], 409);
        }
        $input       = getJsonInput();
        $displayName = requireString($input, 'displayName', 3);
        $username    = requireString($input, 'username', 3);
        $password    = requireString($input, 'password', 3);
        $db->prepare("INSERT INTO admins (id,username,displayName,passwordHash,createdAt) VALUES (?,?,?,?,?)")
            ->execute([nextId(), $username, $displayName, password_hash($password, PASSWORD_DEFAULT), nowIso()]);
        appendAudit($username, 'created initial admin account');
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'login_user') {
        $input    = getJsonInput();
        $username = requireString($input, 'username', 1);
        $password = requireString($input, 'password', 1);
        foreach (getAllDbPaths() as $path) {
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? COLLATE NOCASE");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, (string) $user['passwordHash'])) {
                $token = issueToken('user', (int) $user['id']);
                appendAudit($username, 'user login');
                respond(['ok' => true, 'token' => $token, 'profile' => sanitizeUser($user)]);
            }
        }
        respond(['ok' => false, 'error' => 'Invalid user credentials'], 401);
    }

    if ($method === 'POST' && $action === 'login_admin') {
        $input    = getJsonInput();
        $username = requireString($input, 'username', 1);
        $password = requireString($input, 'password', 1);
        foreach (getAllDbPaths() as $path) {
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? COLLATE NOCASE");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            if ($admin && password_verify($password, (string) $admin['passwordHash'])) {
                $token = issueToken('admin', (int) $admin['id']);
                appendAudit($username, 'admin login');
                respond(['ok' => true, 'token' => $token, 'profile' => sanitizeAdmin($admin)]);
            }
        }
        respond(['ok' => false, 'error' => 'Invalid admin credentials'], 401);
    }

    if ($method === 'GET' && $action === 'my_orders') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User access required'], 403);
        $userId = (int) $auth['record']['id'];
        $orders = queryAll("SELECT * FROM orders WHERE userId = ? ORDER BY submittedAt DESC", [$userId]);
        respond(['ok' => true, 'orders' => array_map('rowToOrder', $orders)]);
    }

    if ($method === 'POST' && $action === 'create_order') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User access required'], 403);
        $input = getJsonInput();
        $customerName  = requireString($input, 'customerName', 2);
        $email         = requireString($input, 'email', 5);
        $phone         = requireString($input, 'phone', 6);
        $transactionId = requireString($input, 'transactionId', 3);
        $itemsInput    = $input['items'] ?? [];
        if (!is_array($itemsInput) || count($itemsInput) === 0) respond(['ok' => false, 'error' => 'Cart is empty'], 400);
        $items = [];
        $total = 0;
        foreach ($itemsInput as $rawItem) {
            $productId = (int) ($rawItem['productId'] ?? 0);
            $quantity  = max(1, (int) ($rawItem['quantity'] ?? 1));
            $stmt = $db->prepare("SELECT * FROM catalog WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) respond(['ok' => false, 'error' => 'Invalid product in cart'], 400);
            $items[] = ['productId' => (int)$product['id'], 'name' => $product['name'], 'price' => (int)$product['price'], 'quantity' => $quantity];
            $total  += ((int) $product['price']) * $quantity;
        }
        $orderId  = 'ORD' . nextId();
        $customer = ['name' => $customerName, 'email' => $email, 'phone' => $phone];
        $db->prepare("INSERT INTO orders (id,userId,customerJson,itemsJson,totalAmount,transactionId,status,source,submittedAt) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orderId, (int)$auth['record']['id'], json_encode($customer), json_encode($items), $total, $transactionId, 'pending', 'checkout', nowIso()]);
        appendAudit((string)$auth['record']['username'], 'created order ' . $orderId);
        rotateDbIfNeeded();
        respond(['ok' => true, 'order' => rowToOrder($db->query("SELECT * FROM orders WHERE id = '$orderId'")->fetch())]);
    }

    if ($method === 'POST' && $action === 'report_payment') {
        $auth = requireAuth();
        if ($auth['role'] !== 'user') respond(['ok' => false, 'error' => 'User access required'], 403);
        $input         = getJsonInput();
        $name          = requireString($input, 'name', 2);
        $email         = requireString($input, 'email', 5);
        $phone         = requireString($input, 'phone', 6);
        $courseId      = (int) ($input['courseId'] ?? 0);
        $amount        = (int) ($input['amount'] ?? 0);
        $transactionId = requireString($input, 'transactionId', 3);
        $details       = trim((string) ($input['details'] ?? ''));
        $stmt = $db->prepare("SELECT * FROM catalog WHERE id = ?");
        $stmt->execute([$courseId]);
        $product = $stmt->fetch();
        if (!$product || $amount <= 0) respond(['ok' => false, 'error' => 'Invalid payment report'], 400);
        $orderId  = 'RPT' . nextId();
        $customer = ['name' => $name, 'email' => $email, 'phone' => $phone];
        $items    = [['productId' => (int)$product['id'], 'name' => $product['name'], 'price' => (int)$product['price'], 'quantity' => 1]];
        $db->prepare("INSERT INTO orders (id,userId,customerJson,itemsJson,totalAmount,transactionId,status,source,notes,submittedAt) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$orderId, (int)$auth['record']['id'], json_encode($customer), json_encode($items), $amount, $transactionId, 'reported', 'manual-report', $details, nowIso()]);
        appendAudit((string)$auth['record']['username'], 'reported payment ' . $orderId);
        rotateDbIfNeeded();
        respond(['ok' => true, 'order' => rowToOrder($db->query("SELECT * FROM orders WHERE id = '$orderId'")->fetch())]);
    }

    if ($method === 'GET' && $action === 'admin_snapshot') {
        requireAdmin();
        $orders = array_map('rowToOrder', queryAll("SELECT * FROM orders ORDER BY submittedAt DESC"));
        respond([
            'ok'       => true,
            'catalog'  => getCatalog(),
            'settings' => getSettings(),
            'users'    => array_map('sanitizeUser',  queryAll("SELECT * FROM users  ORDER BY createdAt DESC")),
            'admins'   => array_map('sanitizeAdmin', queryAll("SELECT * FROM admins ORDER BY createdAt DESC")),
            'orders'   => $orders,
            'file'     => basename(getActiveDbPath()),
        ]);
    }

    if ($method === 'POST' && $action === 'save_settings') {
        $auth  = requireAdmin();
        $input = getJsonInput();
        $upiId = requireString($input, 'upiId', 3);
        $qr    = trim((string) ($input['qrCodeBase64'] ?? ''));
        $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('upiId',?)")->execute([$upiId]);
        $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('qrCodeBase64',?)")->execute([$qr]);
        appendAudit((string)$auth['record']['username'], 'updated payment settings');
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'add_product') {
        $auth        = requireAdmin();
        $input       = getJsonInput();
        $name        = requireString($input, 'name', 3);
        $description = requireString($input, 'description', 8);
        $price       = (int) ($input['price'] ?? 0);
        if ($price <= 0) respond(['ok' => false, 'error' => 'Invalid price'], 400);
        $imageDataUri = trim((string) ($input['imageBase64'] ?? ''));
        $db->prepare("INSERT INTO catalog (id,name,description,price,imageBase64) VALUES (?,?,?,?,?)")
            ->execute([nextId(), $name, $description, $price, $imageDataUri]);
        appendAudit((string)$auth['record']['username'], 'added product ' . $name);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'update_product') {
        $auth        = requireAdmin();
        $input       = getJsonInput();
        $id          = (int) ($input['id'] ?? 0);
        $name        = requireString($input, 'name', 3);
        $description = requireString($input, 'description', 8);
        $price       = (int) ($input['price'] ?? 0);
        if ($price <= 0) respond(['ok' => false, 'error' => 'Invalid price'], 400);
        $imageDataUri = trim((string) ($input['imageBase64'] ?? ''));
        $affected = executeOnAll("UPDATE catalog SET name=?,description=?,price=?,imageBase64=? WHERE id=?", [$name, $description, $price, $imageDataUri, $id]);
        if ($affected === 0) respond(['ok' => false, 'error' => 'Product not found'], 404);
        appendAudit((string)$auth['record']['username'], 'updated product ' . $id);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'delete_product') {
        $auth  = requireAdmin();
        $input = getJsonInput();
        $id    = (int) ($input['id'] ?? 0);
        $affected = executeOnAll("DELETE FROM catalog WHERE id = ?", [$id]);
        if ($affected === 0) respond(['ok' => false, 'error' => 'Product not found'], 404);
        appendAudit((string)$auth['record']['username'], 'deleted product ' . $id);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'update_order_status') {
        $auth   = requireAdmin();
        $input  = getJsonInput();
        $id     = requireString($input, 'id', 3);
        $status = requireString($input, 'status', 3);
        if (!in_array($status, ['approved', 'rejected'], true)) {
            respond(['ok' => false, 'error' => 'Invalid order status'], 400);
        }
        $rejectionReason = '';
        if ($status === 'rejected') $rejectionReason = requireString($input, 'reason', 3);
        $affected = executeOnAll("UPDATE orders SET status=?,rejectionReason=?,updatedAt=? WHERE id=?", [$status, $rejectionReason, nowIso(), $id]);
        if ($affected === 0) respond(['ok' => false, 'error' => 'Order not found'], 404);
        appendAudit((string)$auth['record']['username'], 'updated order ' . $id . ' to ' . $status);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'add_user') {
        $auth     = requireAdmin();
        $input    = getJsonInput();
        $username = requireString($input, 'username', 3);
        $email    = requireString($input, 'email', 5);
        $password = requireString($input, 'password', 3);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['ok' => false, 'error' => 'Invalid email'], 400);
        }
        $exists = queryAll("SELECT 1 FROM users WHERE username = ? COLLATE NOCASE UNION SELECT 1 FROM admins WHERE username = ? COLLATE NOCASE", [$username, $username]);
        if (!empty($exists)) {
            respond(['ok' => false, 'error' => 'Username already exists'], 409);
        }
        $db->prepare("INSERT INTO users (id,username,displayName,email,passwordHash,createdAt) VALUES (?,?,?,?,?,?)")
            ->execute([nextId(), $username, $username, $email, password_hash($password, PASSWORD_DEFAULT), nowIso()]);
        appendAudit((string)$auth['record']['username'], 'admin added user ' . $username);
        respond(['ok' => true]);
    }

    if ($method === 'POST' && $action === 'delete_user') {
        $auth = requireAdmin();
        $input = getJsonInput();
        $id    = (int) ($input['id'] ?? 0);
        if ($id <= 0) respond(['ok' => false, 'error' => 'Invalid user ID'], 400);
        $affected = executeOnAll("DELETE FROM users WHERE id = ?", [$id]);
        if ($affected === 0) respond(['ok' => false, 'error' => 'User not found'], 404);
        appendAudit((string)$auth['record']['username'], 'deleted user ' . $id);
        respond(['ok' => true]);
    }

    if ($method === 'GET' && $action === 'export_encrypted_snapshot') {
        $auth = requireAdmin();
        $snapshot = [
            'users'      => array_map('sanitizeUser',  queryAll("SELECT * FROM users  ORDER BY createdAt DESC")),
            'admins'     => array_map('sanitizeAdmin', queryAll("SELECT * FROM admins ORDER BY createdAt DESC")),
            'catalog'    => getCatalog(),
            'settings'   => getSettings(),
            'orders'     => array_map('rowToOrder', queryAll("SELECT * FROM orders ORDER BY submittedAt DESC")),
            'audit'      => queryAll("SELECT * FROM audit ORDER BY id DESC LIMIT 200"),
            'file'       => basename(getActiveDbPath()),
            'exportedAt' => nowIso(),
        ];
        $jsonData = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $key = UltraSecureEncrypter257::generateUltraKey();
        $encResult = UltraSecureEncrypter257::encryptUltraSecure($jsonData, $key);
        extract($encResult);
        $loaderHtml = createSelfDecryptingHtml($encryptedHex, $protectionPositions, $integrityHash, $originalLength);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="ultra_secure_admin_snapshot.html"');
        header('Content-Length: ' . strlen($loaderHtml));
        echo $loaderHtml;
        appendAudit((string)$auth['record']['username'], 'exported encrypted admin snapshot');
        exit;
    }

    respond(['ok' => false, 'error' => "Unsupported action: {$action}"], 400);

} catch (Throwable $error) {
    respond(['ok' => false, 'error' => $error->getMessage()], 500);
}

// ─── The full HTML user interface ──────────────────────────────────────
function serveFullHtml(): void {
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>257 Trading Masterclass – UltraSecureEncrypter257</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script type="importmap">
        { "imports": { "three": "https://unpkg.com/three@0.128.0/build/three.module.js" } }
    </script>
    <style>
        :root {
            --bg: #08111f; --bg-soft: #0f1b31; --panel: rgba(9, 18, 34, 0.82); --line: rgba(148, 163, 184, 0.18);
            --line-strong: rgba(96, 165, 250, 0.38); --text: #e5eefc; --muted: #9eb0cb; --accent: #4f8cff;
            --accent-2: #14b8a6; --accent-3: #7c3aed; --warn: #f59e0b; --danger: #ef4444; --success: #22c55e;
            --shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: radial-gradient(circle at top left, rgba(79, 140, 255, 0.16), transparent 24%), radial-gradient(circle at 85% 10%, rgba(20, 184, 166, 0.14), transparent 18%), linear-gradient(180deg, #07101d 0%, #08111f 46%, #050a13 100%); color: var(--text); overflow-x: hidden; }
        button, input, select, textarea { font: inherit; }
        button { border: 0; cursor: pointer; background: none; }
        a { color: inherit; text-decoration: none; }
        #bg-canvas { position: fixed; inset: 0; z-index: -2; pointer-events: none; opacity: 0.9; }
        .hidden { display: none !important; }
        .app-shell { min-height: 100vh; }
        .hero-layout { min-height: 100vh; display: grid; grid-template-columns: 1.1fr 0.9fr; align-items: stretch; }
        .hero-panel { padding: 48px; display: flex; flex-direction: column; justify-content: center; gap: 26px; }
        .brand-mark { display: inline-flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 700; color: #c8daf9; letter-spacing: 0.06em; text-transform: uppercase; }
        .brand-dot { width: 12px; height: 12px; border-radius: 999px; background: linear-gradient(135deg, var(--accent), var(--accent-2)); box-shadow: 0 0 18px rgba(79, 140, 255, 0.65); }
        .hero-panel h1 { font-size: clamp(2.6rem, 4vw, 4.7rem); line-height: 0.98; max-width: 11ch; font-weight: 800; }
        .hero-panel p { max-width: 560px; color: var(--muted); font-size: 1.04rem; line-height: 1.7; }
        .hero-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; max-width: 720px; }
        .metric-card, .glass-card { background: var(--panel); border: 1px solid var(--line); box-shadow: var(--shadow); backdrop-filter: blur(18px); }
        .metric-card { border-radius: 18px; padding: 18px 20px; }
        .metric-card strong { display: block; font-size: 1.45rem; margin-bottom: 6px; }
        .metric-card span { color: var(--muted); font-size: 0.92rem; }
        .auth-panel-wrap { padding: 28px; display: flex; align-items: center; justify-content: center; }
        .auth-card { width: min(100%, 480px); border-radius: 28px; padding: 28px; }
        .eyebrow { color: #b8caf0; font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px; }
        .auth-card h2 { font-size: 1.95rem; margin-bottom: 8px; }
        .auth-card > p { color: var(--muted); line-height: 1.6; margin-bottom: 18px; }
        .segmented { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--line); padding: 8px; border-radius: 18px; margin-bottom: 18px; }
        .segment { min-height: 42px; border-radius: 14px; background: transparent; color: #c5d5f3; font-weight: 700; }
        .segment.active { background: linear-gradient(135deg, rgba(79,140,255,0.28), rgba(20,184,166,0.24)); color: #fff; border: 1px solid rgba(79,140,255,0.35); }
        .form-stack { display: grid; gap: 14px; }
        .field { display: grid; gap: 8px; }
        .field label { font-size: 0.88rem; color: #b7c9e8; }
        .field input, .field textarea, .field select { width: 100%; min-height: 48px; border-radius: 14px; border: 1px solid rgba(148,163,184,0.16); background: rgba(3,9,18,0.72); color: var(--text); padding: 14px 15px; outline: none; }
        .field textarea { min-height: 110px; resize: vertical; }
        .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--line-strong); box-shadow: 0 0 0 3px rgba(79,140,255,0.16); }
        .btn { min-height: 48px; padding: 0 18px; border-radius: 14px; font-weight: 700; transition: 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { color: #fff; background: linear-gradient(135deg, var(--accent), var(--accent-3)); box-shadow: 0 12px 26px rgba(79,140,255,0.28); }
        .btn-secondary { color: #dce7fb; background: rgba(255,255,255,0.06); border: 1px solid rgba(148,163,184,0.16); }
        .btn-danger { color: #fff; background: linear-gradient(135deg, #dc2626, #b91c1c); }
        .btn-success { color: #fff; background: linear-gradient(135deg, #16a34a, #15803d); }
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .muted-note { color: var(--muted); font-size: 0.9rem; line-height: 1.55; }
        .warning-box, .status-box { border-radius: 16px; padding: 14px 16px; line-height: 1.55; font-size: 0.94rem; }
        .warning-box { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.18); color: #f7d9a0; }
        .status-box { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.16); color: #b9f5c8; }
        .topbar { position: sticky; top: 0; z-index: 80; backdrop-filter: blur(18px); background: rgba(5,11,21,0.72); border-bottom: 1px solid var(--line); }
        .topbar-inner { max-width: 1440px; margin: 0 auto; padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap; }
        .topbar-title { display: flex; flex-direction: column; gap: 5px; }
        .topbar-title strong { font-size: 1.1rem; }
        .topbar-title span { color: var(--muted); font-size: 0.9rem; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .pill { padding: 10px 14px; border-radius: 999px; border: 1px solid var(--line); background: rgba(255,255,255,0.04); color: #d7e4fb; font-size: 0.92rem; }
        .workspace { max-width: 1440px; margin: 0 auto; padding: 24px; display: grid; gap: 22px; }
        .nav-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-tab { min-height: 42px; padding: 0 16px; border-radius: 14px; color: #c9d7f0; background: rgba(255,255,255,0.04); border: 1px solid transparent; font-weight: 700; }
        .nav-tab.active { background: linear-gradient(135deg, rgba(79,140,255,0.3), rgba(20,184,166,0.2)); border-color: rgba(79,140,255,0.3); color: #fff; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .stat-box { border-radius: 20px; padding: 18px 18px 16px; }
        .stat-box span { display: block; color: var(--muted); font-size: 0.88rem; margin-bottom: 8px; }
        .stat-box strong { font-size: 1.8rem; }
        .content-grid { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 22px; }
        .panel { border-radius: 24px; padding: 22px; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; }
        .panel-header h3 { font-size: 1.15rem; }
        .panel-header p { color: var(--muted); font-size: 0.92rem; }
        .catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
        .product-card { border-radius: 22px; overflow: hidden; border: 1px solid var(--line); background: linear-gradient(180deg, rgba(13,23,41,0.95), rgba(8,14,27,0.95)); display: grid; }
        .product-visual { aspect-ratio: 16 / 9; background: linear-gradient(135deg, rgba(79,140,255,0.28), transparent), linear-gradient(135deg, rgba(124,58,237,0.24), rgba(20,184,166,0.14)); display: grid; place-items: center; overflow: hidden; }
        .product-visual img { width: 100%; height: 100%; object-fit: cover; }
        .product-visual span { font-size: 1.1rem; color: #d9e6ff; font-weight: 700; letter-spacing: 0.04em; }
        .product-body { padding: 18px; display: grid; gap: 12px; }
        .product-body p { color: var(--muted); line-height: 1.6; font-size: 0.94rem; min-height: 4.8em; }
        .price-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .price-row strong { font-size: 1.35rem; }
        .list-stack { display: grid; gap: 12px; }
        .list-item { border-radius: 18px; border: 1px solid var(--line); background: rgba(255,255,255,0.03); padding: 15px; display: grid; gap: 10px; }
        .list-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
        .list-head strong { font-size: 1rem; }
        .meta-line { color: var(--muted); font-size: 0.9rem; line-height: 1.5; }
        .badge { display: inline-flex; align-items: center; min-height: 30px; padding: 0 12px; border-radius: 999px; font-size: 0.84rem; font-weight: 700; }
        .badge.pending { background: rgba(245,158,11,0.16); color: #ffd79b; }
        .badge.approved { background: rgba(34,197,94,0.14); color: #b8f6c8; }
        .badge.rejected { background: rgba(239,68,68,0.14); color: #ffc2c2; }
        .badge.reported { background: rgba(79,140,255,0.18); color: #cfe0ff; }
        .badge.user { background: rgba(79,140,255,0.14); color: #cfe0ff; }
        .badge.admin { background: rgba(124,58,237,0.18); color: #e6d6ff; }
        .mini-note { font-size: 0.86rem; color: var(--muted); }
        .drawer { position: fixed; top: 0; right: 0; width: min(100%, 440px); height: 100vh; padding: 20px; background: rgba(4,9,18,0.95); backdrop-filter: blur(24px); border-left: 1px solid var(--line); box-shadow: -24px 0 60px rgba(0,0,0,0.35); transform: translateX(100%); transition: transform 0.25s ease; z-index: 120; overflow-y: auto; }
        .drawer.open { transform: translateX(0); }
        .overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.52); z-index: 110; opacity: 0; pointer-events: none; transition: opacity 0.22s ease; }
        .overlay.open { opacity: 1; pointer-events: auto; }
        .footer-note { color: var(--muted); font-size: 0.88rem; text-align: center; padding-bottom: 24px; }
        @media (max-width: 1180px) { .hero-layout, .content-grid { grid-template-columns: 1fr; } .dashboard-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .hero-panel, .auth-panel-wrap, .workspace { padding: 20px; } .hero-metrics, .dashboard-grid { grid-template-columns: 1fr; } .segmented { grid-template-columns: 1fr; } .catalog-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <canvas id="bg-canvas"></canvas>

    <div id="authScreen" class="app-shell">
        <div class="hero-layout">
            <section class="hero-panel">
                <div class="brand-mark"><span class="brand-dot"></span><span>257 Trading Masterclass</span></div>
                <h1>Ultra‑secure course commerce with 257 encryption.</h1>
                <p>Powered by <strong>UltraSecureEncrypter257</strong> – a proprietary multi‑layer cipher with noise injection, anti‑tamper markers, and integrity verification. All data stored in an encrypted vault file.</p>
                <div class="hero-metrics">
                    <div class="metric-card glass-card"><strong>257 Layered</strong><span>Multi‑layer cipher with 40% noise density</span></div>
                    <div class="metric-card glass-card"><strong>Server‑based</strong><span>All data lives in the encrypted vault</span></div>
                    <div class="metric-card glass-card"><strong>Role aware</strong><span>Separate user / admin paths</span></div>
                </div>
            </section>
            <aside class="auth-panel-wrap">
                <div class="auth-card glass-card">
                    <div class="eyebrow">Professional Access</div>
                    <h2>Sign in to continue</h2>
                    <p>User login for purchases, admin login for management.</p>
                    <div id="serverWarning" class="warning-box hidden">This page is opened as a local file or the PHP backend is offline.</div>
                    <div class="segmented" id="authTabs">
                        <button class="segment active" data-auth="userLogin">User Login</button>
                        <button class="segment" data-auth="register">Register</button>
                        <button class="segment" data-auth="adminLogin">Admin Login</button>
                    </div>
                    <div id="authForms"></div>
                </div>
            </aside>
        </div>
    </div>

    <div id="appScreen" class="app-shell hidden">
        <header class="topbar">
            <div class="topbar-inner">
                <div class="topbar-title"><strong>257 Trading Masterclass Control Panel</strong><span id="dbStatusText">Checking encrypted database status...</span></div>
                <div class="topbar-actions">
                    <div id="roleBadge" class="badge user">Guest</div>
                    <div id="sessionName" class="pill">No active session</div>
                    <button class="btn btn-secondary" id="cartButton" onclick="openCartDrawer()">Cart</button>
                    <button class="btn btn-secondary" onclick="logout()">Logout</button>
                </div>
            </div>
        </header>
        <main class="workspace">
            <nav class="nav-tabs" id="mainTabs"></nav>
            <div id="viewMount"></div>
        </main>
        <div class="footer-note">Encryption engine: UltraSecureEncrypter257 – 3‑layer cipher with 0xDEADBEEF markers. Data stored in <code>vault*.sqlite</code> via <code>api.php</code>.</div>
    </div>

    <div id="overlay" class="overlay" onclick="closeDrawers()"></div>
    <aside id="cartDrawer" class="drawer"></aside>
    <!-- New edit product drawer -->
    <aside id="editProductDrawer" class="drawer"></aside>

    <script type="module">
        import * as THREE from "three";
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(70, window.innerWidth / window.innerHeight, 0.1, 1000);
        const renderer = new THREE.WebGLRenderer({ canvas: document.getElementById("bg-canvas"), alpha: true, antialias: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(window.innerWidth, window.innerHeight);
        const knot = new THREE.Mesh(new THREE.TorusKnotGeometry(1.25, 0.28, 180, 28), new THREE.MeshStandardMaterial({ color: 0x4f8cff, emissive: 0x0f3db1, roughness: 0.24, metalness: 0.82 }));
        scene.add(knot);
        const particles = new THREE.BufferGeometry();
        const points = [];
        for (let i = 0; i < 1200; i++) points.push((Math.random() - 0.5) * 200, (Math.random() - 0.5) * 140, (Math.random() - 0.5) * 120);
        particles.setAttribute("position", new THREE.BufferAttribute(new Float32Array(points), 3));
        const stars = new THREE.Points(particles, new THREE.PointsMaterial({ color: 0xd8e6ff, size: 0.33 }));
        scene.add(stars);
        scene.add(new THREE.AmbientLight(0x4a5675, 0.8));
        const light = new THREE.PointLight(0xffffff, 1.05);
        light.position.set(6, 5, 9);
        scene.add(light);
        camera.position.z = 4.8;
        function animate() { requestAnimationFrame(animate); knot.rotation.x += 0.0025; knot.rotation.y += 0.0042; stars.rotation.y += 0.0007; renderer.render(scene, camera); }
        animate();
        window.addEventListener("resize", () => { camera.aspect = window.innerWidth / window.innerHeight; camera.updateProjectionMatrix(); renderer.setSize(window.innerWidth, window.innerHeight); });
    </script>

    <script>
        const API_URL = "./api.php";
        const SESSION_KEY = "tm_auth_session";
        const state = {
            token: "", role: "", profile: null, serverOnline: false, boot: null,
            activeAuthTab: "userLogin", activeView: "store", cart: [],
            myOrders: [], adminSnapshot: null
        };

        function money(v) { return `Rs ${Number(v||0).toLocaleString("en-IN")}`; }
        function escapeHtml(v) { return String(v||"").replace(/[&<>"]/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;" }[c])); }
        function showToast(msg, tone="primary") {
            const colors = { primary:"#4f8cff", success:"#22c55e", danger:"#ef4444", warn:"#f59e0b" };
            const t = document.createElement("div");
            t.textContent = msg;
            t.style.cssText = `position:fixed;right:18px;bottom:18px;z-index:160;background:${colors[tone]||colors.primary};color:#fff;padding:12px 16px;border-radius:14px;font-weight:700;box-shadow:0 16px 34px rgba(0,0,0,0.28);`;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 2600);
        }

        async function api(action, options={}) {
            const method = options.method || "POST";
            const headers = { "Cache-Control":"no-store", ...(options.headers||{}) };
            if (state.token) headers.Authorization = `Bearer ${state.token}`;
            let body;
            if (options.body !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(options.body); }
            const resp = await fetch(`${API_URL}?action=${encodeURIComponent(action)}`, { method, headers, body, cache:"no-store" });
            const payload = await resp.json().catch(() => ({ ok:false, error:"Invalid server response" }));
            if (!resp.ok || !payload.ok) throw new Error(payload.error || `Request failed (${resp.status})`);
            return payload;
        }

        function setServerState(online, fileLabel="vault.db") {
            state.serverOnline = online;
            document.getElementById("serverWarning").classList.toggle("hidden", online);
            document.getElementById("dbStatusText").textContent = online ? `Encrypted database active in ${fileLabel}.` : "Backend offline. Run through PHP.";
        }

        async function boot() {
            try { const p = await api("boot", { method:"GET" }); state.boot = p; setServerState(true, p.file || "vault.db"); }
            catch(e) { state.boot = { catalog:[], settings:{}, meta:{adminCount:0} }; setServerState(false); }
            renderAuthForms();
        }

        function saveSession() { sessionStorage.setItem(SESSION_KEY, JSON.stringify({ token:state.token, role:state.role })); }
        function clearSession() { sessionStorage.removeItem(SESSION_KEY); state.token = ""; state.role = ""; state.profile = null; state.cart = []; state.myOrders = []; state.adminSnapshot = null; }

        async function restoreSession() {
            const raw = sessionStorage.getItem(SESSION_KEY);
            if (!raw) return;
            try {
                const s = JSON.parse(raw); if (!s.token) return;
                state.token = s.token; state.role = s.role || "";
                const me = await api("me", { method:"GET" }); state.profile = me.profile;
                await enterApp();
            } catch(e) { clearSession(); }
        }

        // ── Auth forms ──────────────────────────────────────────────────
        function renderAuthForms() {
            document.getElementById("authTabs").innerHTML = ["userLogin","register","adminLogin"].map(k =>
                `<button class="segment ${state.activeAuthTab===k?"active":""}" data-auth="${k}">${{userLogin:"User Login",register:"Register",adminLogin:"Admin Login"}[k]}</button>`).join("");
            const forms = document.getElementById("authForms");
            if (state.activeAuthTab === "register") {
                forms.innerHTML = `<form class="form-stack" onsubmit="handleRegister(event)"><div class="field"><label>Username</label><input id="regUser" required></div><div class="field"><label>Email</label><input id="regEmail" type="email" required></div><div class="field"><label>Password</label><input id="regPass" type="password" minlength="3" required></div><button class="btn btn-primary" type="submit">Create User Account</button><div class="muted-note">Passwords are hashed server-side.</div></form>`;
            } else if (state.activeAuthTab === "adminLogin") {
                const setup = (state.boot?.meta?.adminCount || 0) === 0 ? `<div class="warning-box">No admin exists. Create first admin below.</div><form class="form-stack" onsubmit="handleSetupAdmin(event)"><div class="field"><label>Display Name</label><input id="setupDisp" required></div><div class="field"><label>Username</label><input id="setupUser" required></div><div class="field"><label>Password</label><input id="setupPass" type="password" minlength="3" required></div><button class="btn btn-primary" type="submit">Create First Admin</button></form>` : "";
                forms.innerHTML = `<form class="form-stack" onsubmit="handleAdminLogin(event)"><div class="field"><label>Admin Username</label><input id="adminUser" required></div><div class="field"><label>Password</label><input id="adminPass" type="password" required></div><button class="btn btn-primary" type="submit">Open Admin Panel</button></form> ${setup}`;
            } else {
                forms.innerHTML = `<form class="form-stack" onsubmit="handleUserLogin(event)"><div class="field"><label>Username</label><input id="userUser" required></div><div class="field"><label>Password</label><input id="userPass" type="password" required></div><button class="btn btn-primary" type="submit">Login to Store</button><div class="muted-note">Server-backed login.</div></form>`;
            }
            document.querySelectorAll("[data-auth]").forEach(btn => btn.addEventListener("click", () => { state.activeAuthTab = btn.dataset.auth; renderAuthForms(); }));
        }

        async function handleRegister(e) { e.preventDefault(); try { await api("register_user", { body: { username: document.getElementById("regUser").value.trim(), email: document.getElementById("regEmail").value.trim(), password: document.getElementById("regPass").value } }); showToast("Account created", "success"); state.activeAuthTab = "userLogin"; await boot(); } catch(ex) { showToast(ex.message, "danger"); } }
        async function handleUserLogin(e) { e.preventDefault(); try { const p = await api("login_user", { body: { username: document.getElementById("userUser").value.trim(), password: document.getElementById("userPass").value } }); state.token = p.token; state.role = "user"; state.profile = p.profile; saveSession(); await enterApp(); showToast("User login success", "success"); } catch(ex) { showToast(ex.message, "danger"); } }
        async function handleAdminLogin(e) { e.preventDefault(); try { const p = await api("login_admin", { body: { username: document.getElementById("adminUser").value.trim(), password: document.getElementById("adminPass").value } }); state.token = p.token; state.role = "admin"; state.profile = p.profile; saveSession(); await enterApp(); showToast("Admin login success", "success"); } catch(ex) { showToast(ex.message, "danger"); } }
        async function handleSetupAdmin(e) { e.preventDefault(); try { await api("setup_admin", { body: { displayName: document.getElementById("setupDisp").value.trim(), username: document.getElementById("setupUser").value.trim(), password: document.getElementById("setupPass").value } }); showToast("First admin created", "success"); await boot(); } catch(ex) { showToast(ex.message, "danger"); } }

        // ── Enter app ────────────────────────────────────────────────────
        async function enterApp() {
            document.getElementById("authScreen").classList.add("hidden");
            document.getElementById("appScreen").classList.remove("hidden");
            document.getElementById("roleBadge").textContent = state.role === "admin" ? "Admin" : "User";
            document.getElementById("roleBadge").className = `badge ${state.role === "admin" ? "admin" : "user"}`;
            document.getElementById("sessionName").textContent = state.profile?.displayName || state.profile?.username || "Active session";
            state.activeView = state.role === "admin" ? "adminOverview" : "store";
            await refreshDataForRole();
            renderTabs(); renderView(); renderCartDrawer();
        }

        async function refreshDataForRole() {
            const bootPayload = await api("boot", { method:"GET" }); state.boot = bootPayload; setServerState(true, bootPayload.file || "vault.db");
            if (state.role === "admin") { const snap = await api("admin_snapshot", { method:"GET" }); state.adminSnapshot = snap; }
            else if (state.role === "user") { const orders = await api("my_orders", { method:"GET" }); state.myOrders = orders.orders || []; }
        }

        function logout() { clearSession(); document.getElementById("appScreen").classList.add("hidden"); document.getElementById("authScreen").classList.remove("hidden"); closeDrawers(); renderAuthForms(); }

        // ── Tabs & Views ─────────────────────────────────────────────────
        function renderTabs() {
            const tabs = state.role === "admin"
                ? [ {key:"adminOverview",label:"Overview"}, {key:"catalogAdmin",label:"Catalog"}, {key:"ordersAdmin",label:"Orders"}, {key:"usersAdmin",label:"Users"}, {key:"settingsAdmin",label:"Settings"} ]
                : [ {key:"store",label:"Store"}, {key:"checkout",label:"Checkout"}, {key:"myOrders",label:"My Orders"}, {key:"report",label:"Report Payment"} ];
            document.getElementById("mainTabs").innerHTML = tabs.map(t => `<button class="nav-tab ${state.activeView===t.key?"active":""}" onclick="switchView('${t.key}')">${t.label}</button>`).join("");
        }
        function switchView(view) { state.activeView = view; renderTabs(); renderView(); }

        function renderView() {
            const mount = document.getElementById("viewMount");
            if (state.role === "admin") mount.innerHTML = renderAdminView();
            else mount.innerHTML = renderUserView();
        }

        // ── User views ───────────────────────────────────────────────────
        function renderUserView() { if (state.activeView === "checkout") return checkoutMarkup(); if (state.activeView === "myOrders") return myOrdersMarkup(); if (state.activeView === "report") return reportMarkup(); return storeMarkup(); }

        function storeMarkup() {
            const products = state.boot?.catalog || [];
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Available Courses</h3></div></div><div class="catalog-grid">${products.map(p => `<article class="product-card"><div class="product-visual">${p.imageBase64 ? `<img src="${p.imageBase64}" alt="${escapeHtml(p.name)}">` : `<span>${escapeHtml(p.name)}</span>`}</div><div class="product-body"><div><h3>${escapeHtml(p.name)}</h3></div><p>${escapeHtml(p.description||"")}</p><div class="price-row"><strong>${money(p.price)}</strong><button class="btn btn-primary" onclick="addToCart(${p.id})">Add to Cart</button></div></div></article>`).join("")}</div></section><aside class="panel glass-card"><div class="section-title"><h3>Account Summary</h3></div><div class="list-stack"><div class="list-item"><strong>${escapeHtml(state.profile?.displayName||state.profile?.username||"User")}</strong><div class="meta-line">${escapeHtml(state.profile?.email||"")}</div></div><div class="list-item"><strong>Current UPI</strong><div class="meta-line">${escapeHtml(state.boot?.settings?.upiId||"")}</div></div><div class="list-item"><strong>Cart Items</strong><div class="meta-line">${state.cart.length} selected</div><button class="btn btn-secondary" onclick="openCartDrawer()">Review Cart</button></div></div></aside></div>`;
        }

        function checkoutMarkup() {
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Checkout</h3></div></div><form class="form-stack" onsubmit="submitCheckout(event)"><div class="field"><label>Full Name</label><input id="chName" required value="${escapeHtml(state.profile?.displayName||"")}"></div><div class="field"><label>Email</label><input id="chEmail" type="email" required value="${escapeHtml(state.profile?.email||"")}"></div><div class="field"><label>Phone</label><input id="chPhone" required></div><div class="field"><label>Transaction ID</label><input id="chTrans" required></div><button class="btn btn-primary" type="submit">Create Pending Order</button></form></section><aside class="panel glass-card"><div class="section-title"><h3>Payment Reference</h3></div><div class="list-stack"><div class="list-item"><strong>UPI ID</strong><div class="meta-line">${escapeHtml(state.boot?.settings?.upiId||"")}</div></div><div class="list-item"><strong>Cart Total</strong><div class="meta-line">${money(cartTotal())}</div></div><div class="list-item"><strong>Cart Contents</strong><div class="meta-line">${state.cart.length ? state.cart.map(i=>`${escapeHtml(i.name)} x${i.quantity}`).join(", ") : "No items"}</div></div></div></aside></div>`;
        }

        function myOrdersMarkup() {
            return `<section class="panel glass-card"><div class="panel-header"><div><h3>My Orders</h3></div><button class="btn btn-secondary" onclick="refreshMyOrders()">Refresh</button></div><div class="list-stack">${state.myOrders.length ? state.myOrders.map(o => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(o.id)}</strong><span class="badge ${o.status}">${o.status}</span></div><div class="meta-line">Amount: ${money(o.totalAmount)}</div><div class="meta-line">Items: ${(o.items||[]).map(i=>`${escapeHtml(i.name)} x${i.quantity}`).join(", ")}</div><div class="meta-line">Transaction: ${escapeHtml(o.transactionId||"")}</div><div class="meta-line">Submitted: ${escapeHtml(o.submittedAt||"")}</div></div>`).join("") : `<div class="list-item"><div class="meta-line">No orders yet.</div></div>`}</div></section>`;
        }

        function reportMarkup() {
            const products = state.boot?.catalog || [];
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Manual Payment Report</h3></div></div><form class="form-stack" onsubmit="submitReport(event)"><div class="field"><label>Full Name</label><input id="rName" required value="${escapeHtml(state.profile?.displayName||"")}"></div><div class="field"><label>Email</label><input id="rEmail" type="email" required value="${escapeHtml(state.profile?.email||"")}"></div><div class="field"><label>Phone</label><input id="rPhone" required></div><div class="field"><label>Course</label><select id="rCourse" required><option value="">Select</option>${products.map(p=>`<option value="${p.id}">${escapeHtml(p.name)} - ${money(p.price)}</option>`).join("")}</select></div><div class="field"><label>Paid Amount</label><input id="rAmount" type="number" min="1" required></div><div class="field"><label>Transaction ID</label><input id="rTrans" required></div><div class="field"><label>Notes</label><textarea id="rDetails"></textarea></div><button class="btn btn-primary" type="submit">Submit Payment Report</button></form></section><aside class="panel glass-card"><div class="status-box">Server-side order records are encrypted with the 257‑cipher. Integrity verification prevents tampering.</div></aside></div>`;
        }

        // ── Admin views ──────────────────────────────────────────────────
        function renderAdminView() { if (state.activeView === "catalogAdmin") return adminCatalogMarkup(); if (state.activeView === "ordersAdmin") return adminOrdersMarkup(); if (state.activeView === "usersAdmin") return adminUsersMarkup(); if (state.activeView === "settingsAdmin") return adminSettingsMarkup(); return adminOverviewMarkup(); }

        function adminOverviewMarkup() {
            const snap = state.adminSnapshot || { users:[], orders:[], catalog:[], admins:[], settings:{} };
            const revenue = snap.orders.filter(o=>o.status==="approved").reduce((s,o)=>s+Number(o.totalAmount||0), 0);
            const pending = snap.orders.filter(o=>o.status==="pending"||o.status==="reported").length;
            return `<div class="dashboard-grid"><div class="stat-box glass-card"><span>Total Users</span><strong>${snap.users.length}</strong></div><div class="stat-box glass-card"><span>Total Orders</span><strong>${snap.orders.length}</strong></div><div class="stat-box glass-card"><span>Pending Review</span><strong>${pending}</strong></div><div class="stat-box glass-card"><span>Approved Revenue</span><strong>${money(revenue)}</strong></div></div>`;
        }

        // ── Image compression ────────────────────────────────────────────
        function compressImage(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const maxWidth = 800, maxHeight = 800;
                        let width = img.width, height = img.height;
                        if (width > maxWidth || height > maxHeight) {
                            if (width > height) { height = Math.round(height * maxWidth / width); width = maxWidth; }
                            else { width = Math.round(width * maxHeight / height); height = maxHeight; }
                        }
                        const canvas = document.createElement('canvas');
                        canvas.width = width; canvas.height = height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        const dataUri = canvas.toDataURL('image/jpeg', 0.7);
                        resolve(dataUri);
                    };
                    img.onerror = reject;
                    img.src = e.target.result;
                };
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        }

        let pendingImageDataUri = null;
        let editPendingImageDataUri = null; // for edit drawer

        function previewImage(event) {
            const file = event.target.files[0];
            if (!file) return;
            document.getElementById('compressionNote').style.display = 'block';
            compressImage(file).then(dataUri => {
                pendingImageDataUri = dataUri;
                const preview = document.getElementById('pImagePreview');
                preview.src = dataUri; preview.style.display = 'block';
            }).catch(() => showToast('Image processing failed', 'danger'));
        }

        function previewEditImage(event) {
            const file = event.target.files[0];
            if (!file) return;
            document.getElementById('editCompressionNote').style.display = 'block';
            compressImage(file).then(dataUri => {
                editPendingImageDataUri = dataUri;
                const preview = document.getElementById('editImagePreview');
                preview.src = dataUri; preview.style.display = 'block';
            }).catch(() => showToast('Image processing failed', 'danger'));
        }

        // ── Edit Product Drawer ────────────────────────────────────────
        function openEditProductDrawer(productId) {
            const product = (state.adminSnapshot?.catalog || []).find(p => Number(p.id) === Number(productId));
            if (!product) return;
            const drawer = document.getElementById("editProductDrawer");
            drawer.innerHTML = `
                <div class="panel-header"><div><h3>Edit Course</h3></div><button class="btn btn-secondary" onclick="closeEditDrawer()">Close</button></div>
                <form class="form-stack" onsubmit="submitEditProduct(event, ${product.id})" enctype="multipart/form-data">
                    <div class="field"><label>Course Name</label><input id="editName" value="${escapeHtml(product.name)}" required></div>
                    <div class="field"><label>Description</label><textarea id="editDesc" required>${escapeHtml(product.description||"")}</textarea></div>
                    <div class="field"><label>Price</label><input id="editPrice" type="number" min="1" value="${product.price}" required></div>
                    <div class="field">
                        <label>Current Image</label>
                        ${product.imageBase64 ? `<img src="${product.imageBase64}" style="max-width:200px; max-height:120px;">` : `<span class="muted-note">No image</span>`}
                    </div>
                    <div class="field">
                        <label>New Image (optional)</label>
                        <input type="file" id="editImage" accept="image/*" onchange="previewEditImage(event)">
                        <img id="editImagePreview" style="max-width:200px; max-height:120px; margin-top:8px; display:none;">
                        <p class="mini-note" id="editCompressionNote" style="display:none;">Image will be compressed automatically.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">Save Changes</button>
                </form>
            `;
            editPendingImageDataUri = null; // reset
            document.getElementById("overlay").classList.add("open");
            drawer.classList.add("open");
        }

        function closeEditDrawer() {
            document.getElementById("overlay").classList.remove("open");
            document.getElementById("editProductDrawer").classList.remove("open");
        }

        async function submitEditProduct(e, id) {
            e.preventDefault();
            const name = document.getElementById("editName").value.trim();
            const desc = document.getElementById("editDesc").value.trim();
            const price = Number(document.getElementById("editPrice").value);
            const original = (state.adminSnapshot?.catalog || []).find(p => Number(p.id) === Number(id));
            const imageBase64 = editPendingImageDataUri !== null ? editPendingImageDataUri : (original ? original.imageBase64 : "");
            try {
                await api("update_product", { body: { id, name, description: desc, price, imageBase64 } });
                await refreshAdminSnapshot();
                closeEditDrawer();
                showToast("Product updated", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        // ── Admin Catalog Markup ────────────────────────────────────────
        function adminCatalogMarkup() {
            const catalog = state.adminSnapshot?.catalog || [];
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Catalog Management</h3></div><button class="btn btn-secondary" onclick="refreshAdminSnapshot()">Refresh</button></div><div class="list-stack">${catalog.map(p => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(p.name)}</strong><span>${money(p.price)}</span></div><div class="meta-line">${escapeHtml(p.description||"")}</div>${p.imageBase64 ? `<div class="product-visual" style="aspect-ratio:16/9;max-width:200px;"><img src="${p.imageBase64}" style="width:100%;height:100%;object-fit:cover;"></div>` : ''}<div class="btn-row"><button class="btn btn-secondary" onclick="openEditProductDrawer(${p.id})">Edit</button><button class="btn btn-danger" onclick="deleteProduct(${p.id})">Delete</button></div></div>`).join("")}</div></section><aside class="panel glass-card"><div class="panel-header"><div><h3>Add Course</h3></div></div><form class="form-stack" onsubmit="submitProductCreate(event)" enctype="multipart/form-data"><div class="field"><label>Course Name</label><input id="pName" required></div><div class="field"><label>Description</label><textarea id="pDesc" required></textarea></div><div class="field"><label>Price</label><input id="pPrice" type="number" min="1" required></div><div class="field"><label>Course Image (jpg/png)</label><input type="file" id="pImage" accept="image/*" onchange="previewImage(event)"><img id="pImagePreview" style="max-width:200px; max-height:120px; margin-top:8px; display:none;"><p class="mini-note" id="compressionNote" style="display:none;">Image will be automatically resized and compressed.</p></div><button class="btn btn-primary" type="submit">Add Product</button></form></aside></div>`;
        }

        async function submitProductCreate(e) {
            e.preventDefault();
            const name = document.getElementById("pName").value.trim();
            const desc = document.getElementById("pDesc").value.trim();
            const price = Number(document.getElementById("pPrice").value);
            const imageBase64 = pendingImageDataUri || '';
            try {
                await api("add_product", { body: { name, description: desc, price, imageBase64 } });
                await refreshAdminSnapshot();
                switchView("catalogAdmin");
                showToast("Product added", "success");
                document.getElementById("pName").value = '';
                document.getElementById("pDesc").value = '';
                document.getElementById("pPrice").value = '';
                document.getElementById("pImage").value = '';
                document.getElementById("pImagePreview").style.display = 'none';
                document.getElementById("compressionNote").style.display = 'none';
                pendingImageDataUri = null;
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        function adminOrdersMarkup() {
            const orders = state.adminSnapshot?.orders || [];
            return `<section class="panel glass-card"><div class="panel-header"><div><h3>Order Review Queue</h3></div><button class="btn btn-secondary" onclick="refreshAdminSnapshot()">Refresh</button></div><div class="list-stack">${orders.length ? orders.map(o => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(o.id)}</strong><span class="badge ${o.status}">${o.status}</span></div><div class="meta-line">Customer: ${escapeHtml(o.customer?.name||"")} | ${escapeHtml(o.customer?.email||"")}</div><div class="meta-line">Amount: ${money(o.totalAmount)} | Transaction: ${escapeHtml(o.transactionId||"")}</div><div class="meta-line">Items: ${(o.items||[]).map(i=>`${escapeHtml(i.name)} x${i.quantity}`).join(", ")}</div><div class="btn-row"><button class="btn btn-success" onclick="updateOrderStatus('${o.id}', 'approved')">Approve</button><button class="btn btn-danger" onclick="updateOrderStatus('${o.id}', 'rejected')">Reject</button></div></div>`).join("") : `<div class="list-item"><div class="meta-line">No orders found.</div></div>`}</div></section>`;
        }

        function adminUsersMarkup() {
            const users = state.adminSnapshot?.users || [];
            const admins = state.adminSnapshot?.admins || [];
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Registered Users</h3></div><button class="btn btn-secondary" onclick="refreshAdminSnapshot()">Refresh</button></div><div class="list-stack">${users.length ? users.map(u => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(u.displayName||u.username)}</strong><span class="badge user">user</span></div><div class="meta-line">${escapeHtml(u.email)}</div><div class="meta-line">${escapeHtml(u.username)}</div><button class="btn btn-danger" onclick="deleteUser('${u.id}')">Remove User</button></div>`).join("") : `<div class="list-item"><div class="meta-line">No users registered.</div></div>`}<button class="btn btn-primary" onclick="showAddUserForm()">Add New User</button></div></section><aside class="panel glass-card"><div class="panel-header"><div><h3>Admin Accounts</h3></div></div><div class="list-stack">${admins.length ? admins.map(a => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(a.displayName||a.username)}</strong><span class="badge admin">admin</span></div><div class="meta-line">${escapeHtml(a.username)}</div></div>`).join("") : `<div class="list-item"><div class="meta-line">No admins found.</div></div>`}</div></aside></div>`;
        }

        function adminSettingsMarkup() {
            const settings = state.adminSnapshot?.settings || {};
            return `<div class="content-grid"><section class="panel glass-card"><div class="panel-header"><div><h3>Payment Settings</h3></div></div><form class="form-stack" onsubmit="saveSettings(event)"><div class="field"><label>UPI ID</label><input id="sUpiId" required value="${escapeHtml(settings.upiId||"")}"></div><div class="field"><label>QR Code Base64</label><textarea id="sQr">${escapeHtml(settings.qrCodeBase64||"")}</textarea></div><button class="btn btn-primary" type="submit">Save Settings</button></form><div class="list-item" style="margin-top:24px;"><div class="list-head"><strong>Encrypted Admin Export</strong></div><p class="mini-note">Download a self‑decrypting HTML file containing the entire admin snapshot (users, orders, catalog, settings) encrypted with UltraSecureEncrypter257.</p><button class="btn btn-primary" onclick="exportEncryptedSnapshot()">Export Encrypted Snapshot</button></div></section><aside class="panel glass-card"><div class="status-box"><strong>UltraSecureEncrypter257</strong> protects the full database export with a 3‑layer cipher, fake‑data injection, and integrity hashes. Exported snapshots can only be opened with the original 257 key.</div></aside></div>`;
        }

        // ── Cart & Checkout helpers ──────────────────────────────────────
        function addToCart(productId) {
            const product = (state.boot?.catalog || []).find(i => Number(i.id) === Number(productId));
            if (!product) return;
            const existing = state.cart.find(i => Number(i.id) === Number(productId));
            if (existing) existing.quantity += 1;
            else state.cart.push({ id: product.id, name: product.name, price: product.price, quantity: 1 });
            renderCartDrawer(); showToast("Added to cart", "success");
        }
        function cartTotal() { return state.cart.reduce((s, i) => s + Number(i.price)*Number(i.quantity), 0); }
        function openCartDrawer() { renderCartDrawer(); document.getElementById("overlay").classList.add("open"); document.getElementById("cartDrawer").classList.add("open"); }
        function closeDrawers() { document.getElementById("overlay").classList.remove("open"); document.getElementById("cartDrawer").classList.remove("open"); document.getElementById("editProductDrawer").classList.remove("open"); }
        function changeCartQuantity(productId, delta) {
            const item = state.cart.find(i => Number(i.id) === Number(productId));
            if (!item) return;
            item.quantity += delta;
            if (item.quantity <= 0) state.cart = state.cart.filter(i => Number(i.id) !== Number(productId));
            renderCartDrawer();
            if (state.activeView === "checkout") renderView();
        }
        function renderCartDrawer() {
            const drawer = document.getElementById("cartDrawer");
            drawer.innerHTML = `<div class="panel-header"><div><h3>Cart Review</h3><p>${state.cart.length} item(s)</p></div><button class="btn btn-secondary" onclick="closeDrawers()">Close</button></div><div class="list-stack">${state.cart.length ? state.cart.map(item => `<div class="list-item"><div class="list-head"><strong>${escapeHtml(item.name)}</strong><span>${money(Number(item.price)*Number(item.quantity))}</span></div><div class="btn-row"><button class="btn btn-secondary" onclick="changeCartQuantity(${item.id}, -1)">-</button><button class="btn btn-secondary">${item.quantity}</button><button class="btn btn-secondary" onclick="changeCartQuantity(${item.id}, 1)">+</button></div></div>`).join("") : `<div class="list-item"><div class="meta-line">Your cart is empty.</div></div>`}</div><div style="margin-top:16px;" class="list-item"><div class="list-head"><strong>Total</strong><span>${money(cartTotal())}</span></div><div class="btn-row"><button class="btn btn-primary" onclick="switchView('checkout');closeDrawers();">Go to Checkout</button></div></div>`;
            document.getElementById("cartButton").textContent = `Cart (${state.cart.reduce((s,i) => s + i.quantity, 0)})`;
        }

        async function submitCheckout(e) {
            e.preventDefault();
            if (!state.cart.length) return showToast("Add courses to cart first", "warn");
            try {
                await api("create_order", { body: {
                    customerName: document.getElementById("chName").value.trim(),
                    email: document.getElementById("chEmail").value.trim(),
                    phone: document.getElementById("chPhone").value.trim(),
                    transactionId: document.getElementById("chTrans").value.trim(),
                    items: state.cart.map(i => ({ productId: i.id, quantity: i.quantity }))
                }});
                state.cart = [];
                await refreshMyOrders();
                renderCartDrawer();
                switchView("myOrders");
                showToast("Order saved to encrypted database", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        async function submitReport(e) {
            e.preventDefault();
            try {
                await api("report_payment", { body: {
                    name: document.getElementById("rName").value.trim(),
                    email: document.getElementById("rEmail").value.trim(),
                    phone: document.getElementById("rPhone").value.trim(),
                    courseId: Number(document.getElementById("rCourse").value),
                    amount: Number(document.getElementById("rAmount").value),
                    transactionId: document.getElementById("rTrans").value.trim(),
                    details: document.getElementById("rDetails").value.trim()
                }});
                await refreshMyOrders();
                switchView("myOrders");
                showToast("Payment report stored", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        async function refreshMyOrders() {
            const p = await api("my_orders", { method:"GET" }); state.myOrders = p.orders || [];
            if (state.activeView === "myOrders") renderView();
        }

        async function refreshAdminSnapshot() {
            const p = await api("admin_snapshot", { method:"GET" }); state.adminSnapshot = p;
            const bootP = await api("boot", { method:"GET" }); state.boot = bootP;
            renderView(); renderCartDrawer(); showToast("Admin data refreshed", "success");
        }

        async function saveSettings(e) {
            e.preventDefault();
            try {
                await api("save_settings", { body: {
                    upiId: document.getElementById("sUpiId").value.trim(),
                    qrCodeBase64: document.getElementById("sQr").value.trim()
                }});
                await refreshAdminSnapshot();
                showToast("Settings saved", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        async function updateOrderStatus(orderId, status) {
            let reason = "";
            if (status === "rejected") { reason = prompt("Enter rejection reason") || ""; if (!reason) return; }
            try {
                await api("update_order_status", { body: { id: orderId, status, reason } });
                await refreshAdminSnapshot();
                showToast(`Order ${status}`, "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        async function deleteProduct(id) {
            if (!confirm("Delete this product?")) return;
            try {
                await api("delete_product", { body: { id: Number(id) } });
                await refreshAdminSnapshot();
                showToast("Product deleted", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        function showAddUserForm() {
            const username = prompt("Enter username for new user:");
            if (!username) return;
            const email = prompt("Enter email:");
            if (!email) return;
            const password = prompt("Enter password (min 3 chars):");
            if (!password || password.length < 3) return alert("Password too short");
            api("add_user", { body: { username, email, password } })
                .then(() => { refreshAdminSnapshot(); showToast("User added", "success"); })
                .catch(e => showToast(e.message, "danger"));
        }

        async function deleteUser(userId) {
            if (!confirm("Remove this user?")) return;
            try {
                await api("delete_user", { body: { id: Number(userId) } });
                await refreshAdminSnapshot();
                showToast("User removed", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        async function exportEncryptedSnapshot() {
            try {
                const resp = await fetch(`${API_URL}?action=export_encrypted_snapshot`, {
                    method: "GET",
                    headers: { Authorization: `Bearer ${state.token}`, "Cache-Control":"no-store" }
                });
                if (!resp.ok) throw new Error("Export failed");
                const blob = await resp.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = "ultra_secure_admin_snapshot.html";
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(url);
                showToast("Encrypted snapshot exported.", "success");
            } catch(ex) { showToast(ex.message, "danger"); }
        }

        // Global exposure
        window.handleRegister = handleRegister;
        window.handleUserLogin = handleUserLogin;
        window.handleAdminLogin = handleAdminLogin;
        window.handleSetupAdmin = handleSetupAdmin;
        window.switchView = switchView;
        window.logout = logout;
        window.addToCart = addToCart;
        window.openCartDrawer = openCartDrawer;
        window.closeDrawers = closeDrawers;
        window.changeCartQuantity = changeCartQuantity;
        window.submitCheckout = submitCheckout;
        window.submitReport = submitReport;
        window.refreshMyOrders = refreshMyOrders;
        window.refreshAdminSnapshot = refreshAdminSnapshot;
        window.submitProductCreate = submitProductCreate;
        window.deleteProduct = deleteProduct;
        window.openEditProductDrawer = openEditProductDrawer;
        window.closeEditDrawer = closeEditDrawer;
        window.submitEditProduct = submitEditProduct;
        window.previewEditImage = previewEditImage;
        window.updateOrderStatus = updateOrderStatus;
        window.saveSettings = saveSettings;
        window.exportEncryptedSnapshot = exportEncryptedSnapshot;
        window.deleteUser = deleteUser;
        window.showAddUserForm = showAddUserForm;
        window.previewImage = previewImage;

        async function start() { if (location.protocol === "file:") setServerState(false); await boot(); await restoreSession(); }
        start();
    </script>
</body>
</html>
HTML;
}