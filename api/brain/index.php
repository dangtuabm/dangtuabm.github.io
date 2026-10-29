<?php
// ABM Second Brain — API máy chủ (Giai đoạn 2–3): tài khoản, không gian nhóm, đồng bộ ghi chú,
// phân quyền theo vai và theo mức dữ liệu Xanh–Vàng–Đỏ (cưỡng chế ở tầng lưu trữ), nhật ký kiểm toán,
// và cổng gọi A.I bằng khóa của doanh nghiệp (thành viên không cần khóa riêng).
// Cấu hình KHÔNG nằm trong mã nguồn: file abm-config.php ngoài thư mục web (xem README.md cùng thư mục).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(int $code, string $error, string $msg = ''): void { out($code, ['ok' => false, 'error' => $error, 'message' => $msg]); }
function now_ms(): int { return (int) floor(microtime(true) * 1000); }

// ---------- Cấu hình ----------
$cfgPath = getenv('ABM_CONFIG') ?: dirname(__DIR__, 3) . '/abm-config.php';
if (!is_readable($cfgPath)) fail(500, 'config', 'Thiếu file cấu hình abm-config.php');
$cfg = require $cfgPath;
$brain = $cfg['brain'] ?? [];

// ---------- CORS (chỉ cho website của ABM) ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $cfg['allowed_origins'] ?? [];
if ($origin !== '') {
  if (!in_array($origin, $allowed, true)) fail(403, 'origin', 'Nguồn gọi không được phép');
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Kết nối DB ----------
try {
  $db = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Throwable $e) { error_log('[abm-brain] db: ' . $e->getMessage()); fail(500, 'db'); }
$isSqlite = str_starts_with((string) $cfg['dsn'], 'sqlite:');
if (!empty($brain['auto_schema'])) {
  $sql = file_get_contents(__DIR__ . '/schema.sql');
  if ($isSqlite) $sql = preg_replace('/\bAUTO_INCREMENT\b/', 'AUTOINCREMENT', preg_replace('/ENGINE=\w+[^;]*/', '', $sql));
  foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) { try { $db->exec($stmt); } catch (Throwable $e) { /* đã tồn tại */ } }
}

// ---------- Tiện ích ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim((string) ($_GET['r'] ?? ''), '/');
$raw = file_get_contents('php://input');
$in = $method === 'POST' ? (json_decode($raw ?: '[]', true) ?: []) : $_GET;
$s = fn(string $k, int $max = 200) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
$ipHash = hash('sha256', ($cfg['salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
const ROLES = ['chu', 'quan-tri', 'bien-tap', 'xem'];
const LEVELS = ['xanh', 'vang', 'do'];
function level_of(string $body): string {
  if (preg_match('/^---\R(.*?)\R---/s', $body, $m) && preg_match('/^muc_do:\s*(\w+)/m', $m[1], $l)) return in_array($l[1], LEVELS, true) ? $l[1] : 'xanh';
  return 'xanh';
}
function audit(PDO $db, ?int $uid, ?int $space, string $action, string $target = '', string $detail = ''): void {
  $db->prepare('INSERT INTO brain_audit (user_id, space_id, action, target, detail, created_at) VALUES (?,?,?,?,?,?)')->execute([$uid, $space, $action, mb_substr($target, 0, 190), mb_substr($detail, 0, 500), now_ms()]);
}
function auth(PDO $db): ?array {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,})$/', $h, $m)) return null;
  $q = $db->prepare('SELECT u.id, u.email, u.name FROM brain_tokens t JOIN brain_users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > ?');
  $q->execute([hash('sha256', $m[1]), now_ms()]);
  $u = $q->fetch();
  return $u ?: null;
}
function need_user(PDO $db): array { $u = auth($db); if (!$u) fail(401, 'auth', 'Cần đăng nhập'); return $u; }
function role_in(PDO $db, int $uid, int $space): ?string {
  $q = $db->prepare('SELECT role FROM brain_members WHERE user_id = ? AND space_id = ?'); $q->execute([$uid, $space]); $r = $q->fetchColumn(); return $r ?: null;
}
function need_role(PDO $db, int $uid, int $space, array $roles): string {
  $r = role_in($db, $uid, $space); if (!$r || !in_array($r, $roles, true)) fail(403, 'role', 'Không đủ quyền trong không gian này'); return $r;
}
function issue_token(PDO $db, int $uid): string {
  $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  $db->prepare('INSERT INTO brain_tokens (user_id, token_hash, created_at, expires_at) VALUES (?,?,?,?)')->execute([$uid, hash('sha256', $tok), now_ms(), now_ms() + 30 * 86400 * 1000]);
  return $tok;
}
function space_row(PDO $db, int $id): ?array { $q = $db->prepare('SELECT id, name, created_at FROM brain_spaces WHERE id = ?'); $q->execute([$id]); return $q->fetch() ?: null; }

try {
switch ("$method $route") {

  // ---------- Xác thực ----------
  case 'POST auth/register': {
    if (empty($brain['open_register']) && ($brain['invite_code'] ?? '') !== $s('invite', 80)) fail(403, 'invite', 'Cần mã mời do ABM cấp');
    $email = mb_strtolower($s('email', 120)); $pw = (string) ($in['password'] ?? ''); $name = $s('name', 80) ?: explode('@', $email)[0];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'email', 'Email không hợp lệ');
    if (strlen($pw) < 8) fail(422, 'password', 'Mật khẩu tối thiểu 8 ký tự');
    $q = $db->prepare('SELECT id FROM brain_users WHERE email = ?'); $q->execute([$email]);
    if ($q->fetch()) fail(409, 'exists', 'Email đã đăng ký');
    $db->prepare('INSERT INTO brain_users (email, name, password_hash, created_at) VALUES (?,?,?,?)')->execute([$email, $name, password_hash($pw, PASSWORD_DEFAULT), now_ms()]);
    $uid = (int) $db->lastInsertId();
    // Mỗi người có một không gian riêng mặc định
    $db->prepare('INSERT INTO brain_spaces (name, owner_id, created_at) VALUES (?,?,?)')->execute(['Không gian của ' . $name, $uid, now_ms()]);
    $sid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO brain_members (space_id, user_id, role, created_at) VALUES (?,?,?,?)')->execute([$sid, $uid, 'chu', now_ms()]);
    audit($db, $uid, $sid, 'register');
    out(200, ['ok' => true, 'token' => issue_token($db, $uid), 'user' => ['id' => $uid, 'email' => $email, 'name' => $name]]);
  }
  case 'POST auth/login': {
    $email = mb_strtolower($s('email', 120)); $pw = (string) ($in['password'] ?? '');
    $q = $db->prepare('SELECT COUNT(*) FROM brain_login_attempts WHERE ip_hash = ? AND created_at > ?'); $q->execute([$ipHash, now_ms() - 600000]);
    if ((int) $q->fetchColumn() >= 10) fail(429, 'rate', 'Thử lại sau 10 phút');
    $q = $db->prepare('SELECT id, email, name, password_hash FROM brain_users WHERE email = ?'); $q->execute([$email]); $u = $q->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) {
      $db->prepare('INSERT INTO brain_login_attempts (ip_hash, created_at) VALUES (?,?)')->execute([$ipHash, now_ms()]);
      fail(401, 'login', 'Sai email hoặc mật khẩu');
    }
    audit($db, (int) $u['id'], null, 'login');
    out(200, ['ok' => true, 'token' => issue_token($db, (int) $u['id']), 'user' => ['id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name']]]);
  }
  case 'POST auth/logout': {
    $u = need_user($db); $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ''; preg_match('/Bearer\s+(\S+)/', $h, $m);
    $db->prepare('DELETE FROM brain_tokens WHERE token_hash = ?')->execute([hash('sha256', $m[1] ?? '')]);
    out(200, ['ok' => true]);
  }
  case 'GET me': {
    $u = need_user($db);
    $q = $db->prepare('SELECT s.id, s.name, m.role, s.owner_id FROM brain_members m JOIN brain_spaces s ON s.id = m.space_id WHERE m.user_id = ? ORDER BY s.id'); $q->execute([$u['id']]);
    out(200, ['ok' => true, 'user' => $u, 'spaces' => $q->fetchAll(), 'ai_gateway' => !empty($brain['anthropic_key'])]);
  }

  // ---------- Không gian & thành viên ----------
  case 'POST spaces': {
    $u = need_user($db); $name = $s('name', 80); if ($name === '') fail(422, 'name');
    $db->prepare('INSERT INTO brain_spaces (name, owner_id, created_at) VALUES (?,?,?)')->execute([$name, $u['id'], now_ms()]); $sid = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO brain_members (space_id, user_id, role, created_at) VALUES (?,?,?,?)')->execute([$sid, $u['id'], 'chu', now_ms()]);
    audit($db, (int) $u['id'], $sid, 'space.create', $name);
    out(200, ['ok' => true, 'space' => ['id' => $sid, 'name' => $name, 'role' => 'chu']]);
  }
  case 'GET spaces/members': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); need_role($db, (int) $u['id'], $sid, ['chu', 'quan-tri', 'bien-tap', 'xem']);
    $q = $db->prepare('SELECT u.id, u.email, u.name, m.role FROM brain_members m JOIN brain_users u ON u.id = m.user_id WHERE m.space_id = ? ORDER BY m.id'); $q->execute([$sid]);
    out(200, ['ok' => true, 'members' => $q->fetchAll()]);
  }
  case 'POST spaces/members': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); $my = need_role($db, (int) $u['id'], $sid, ['chu', 'quan-tri']);
    $email = mb_strtolower($s('email', 120)); $role = $s('role', 20);
    if (!in_array($role, ROLES, true) || $role === 'chu') fail(422, 'role', 'Vai không hợp lệ');
    if ($role === 'quan-tri' && $my !== 'chu') fail(403, 'role', 'Chỉ chủ không gian được gán quản trị');
    $q = $db->prepare('SELECT id FROM brain_users WHERE email = ?'); $q->execute([$email]); $tid = (int) $q->fetchColumn();
    if (!$tid) fail(404, 'user', 'Người này chưa có tài khoản — mời họ đăng ký trước');
    $cur = role_in($db, $tid, $sid);
    if ($cur === 'chu') fail(403, 'role', 'Không đổi vai của chủ không gian');
    if ($cur) $db->prepare('UPDATE brain_members SET role = ? WHERE space_id = ? AND user_id = ?')->execute([$role, $sid, $tid]);
    else $db->prepare('INSERT INTO brain_members (space_id, user_id, role, created_at) VALUES (?,?,?,?)')->execute([$sid, $tid, $role, now_ms()]);
    audit($db, (int) $u['id'], $sid, 'member.set', $email, $role);
    out(200, ['ok' => true]);
  }
  case 'POST spaces/members/remove': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); need_role($db, (int) $u['id'], $sid, ['chu', 'quan-tri']);
    $tid = (int) ($in['user_id'] ?? 0); if (role_in($db, $tid, $sid) === 'chu') fail(403, 'role', 'Không xóa chủ không gian');
    $db->prepare('DELETE FROM brain_members WHERE space_id = ? AND user_id = ?')->execute([$sid, $tid]);
    audit($db, (int) $u['id'], $sid, 'member.remove', (string) $tid);
    out(200, ['ok' => true]);
  }

  // ---------- Đồng bộ ghi chú ----------
  case 'GET notes': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); $role = need_role($db, (int) $u['id'], $sid, ROLES);
    $since = (int) ($in['since'] ?? 0); $admin = in_array($role, ['chu', 'quan-tri'], true);
    // Mức Đỏ: chỉ chủ / quản trị / chính tác giả được đọc — cưỡng chế tại đây, không phải ở giao diện.
    $sql = 'SELECT id, title, body, path, level, author_id, created_at, updated_at, deleted FROM brain_notes WHERE space_id = ? AND server_at > ?' . ($admin ? '' : ' AND (level <> \'do\' OR author_id = ?)') . ' ORDER BY server_at LIMIT 2000';
    $q = $db->prepare($sql); $q->execute($admin ? [$sid, $since] : [$sid, $since, $u['id']]);
    $notes = []; $deleted = [];
    foreach ($q as $r) { if ((int) $r['deleted']) $deleted[] = $r['id']; else $notes[] = ['id' => $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'path' => $r['path'], 'created' => (int) $r['created_at'], 'updated' => (int) $r['updated_at'], 'author_id' => (int) $r['author_id']]; }
    out(200, ['ok' => true, 'notes' => $notes, 'deleted' => $deleted, 'now' => now_ms(), 'role' => $role]);
  }
  case 'POST notes': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); $role = need_role($db, (int) $u['id'], $sid, ROLES);
    if ($role === 'xem') fail(403, 'role', 'Vai "xem" không được ghi');
    $items = is_array($in['notes'] ?? null) ? array_slice($in['notes'], 0, 500) : [];
    $admin = in_array($role, ['chu', 'quan-tri'], true);
    $accepted = []; $conflicts = []; $rejected = [];
    $get = $db->prepare('SELECT id, title, body, path, level, author_id, updated_at, deleted FROM brain_notes WHERE space_id = ? AND id = ?');
    $ins = $db->prepare('INSERT INTO brain_notes (id, space_id, title, body, path, level, author_id, created_at, updated_at, server_at, deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $upd = $db->prepare('UPDATE brain_notes SET title = ?, body = ?, path = ?, level = ?, updated_at = ?, server_at = ?, deleted = ? WHERE space_id = ? AND id = ?');
    $db->beginTransaction();
    foreach ($items as $n) {
      $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($n['id'] ?? '')); if ($id === '' || strlen($id) > 40) continue;
      $title = mb_substr((string) ($n['title'] ?? ''), 0, 300); $body = (string) ($n['body'] ?? ''); if (strlen($body) > 2_000_000) { $rejected[] = $id; continue; }
      $path = mb_substr((string) ($n['path'] ?? ''), 0, 300); $updated = (int) ($n['updated'] ?? now_ms()); $del = !empty($n['deleted']) ? 1 : 0; $base = (int) ($n['base'] ?? 0);
      $level = level_of($body);
      $get->execute([$sid, $id]); $cur = $get->fetch();
      if ($cur) {
        if (!$admin && $cur['level'] === 'do' && (int) $cur['author_id'] !== (int) $u['id']) { $rejected[] = $id; continue; } // không được ghi đè ghi chú Đỏ của người khác
        // Xung đột: máy chủ đã có bản mới hơn bản mà máy khách từng thấy
        if ((int) $cur['updated_at'] > $base && (int) $cur['updated_at'] !== $updated) {
          $conflicts[] = ['id' => $id, 'title' => $cur['title'], 'body' => $cur['body'], 'path' => $cur['path'], 'updated' => (int) $cur['updated_at'], 'deleted' => (int) $cur['deleted']]; continue;
        }
        $upd->execute([$title, $body, $path, $level, $updated, now_ms(), $del, $sid, $id]);
      } else {
        if ($del) { $accepted[] = $id; continue; }
        $ins->execute([$id, $sid, $title, $body, $path, $level, $u['id'], (int) ($n['created'] ?? $updated), $updated, now_ms(), 0]);
      }
      $accepted[] = $id;
      audit($db, (int) $u['id'], $sid, $del ? 'note.delete' : 'note.write', $id, $title);
    }
    $db->commit();
    out(200, ['ok' => true, 'accepted' => $accepted, 'conflicts' => $conflicts, 'rejected' => $rejected, 'now' => now_ms()]);
  }
  case 'GET audit': {
    $u = need_user($db); $sid = (int) ($in['space'] ?? 0); need_role($db, (int) $u['id'], $sid, ['chu', 'quan-tri']);
    $q = $db->prepare('SELECT a.action, a.target, a.detail, a.created_at, u.name, u.email FROM brain_audit a LEFT JOIN brain_users u ON u.id = a.user_id WHERE a.space_id = ? ORDER BY a.id DESC LIMIT 200'); $q->execute([$sid]);
    out(200, ['ok' => true, 'audit' => $q->fetchAll()]);
  }

  // ---------- Cổng A.I của doanh nghiệp (khóa nằm ở máy chủ, có kiểm toán) ----------
  case 'POST ai/complete': {
    $u = need_user($db); $key = (string) ($brain['anthropic_key'] ?? ''); if ($key === '') fail(503, 'ai', 'Máy chủ chưa cấu hình khóa A.I');
    $sid = (int) ($in['space'] ?? 0); if ($sid) need_role($db, (int) $u['id'], $sid, ROLES);
    $system = mb_substr((string) ($in['system'] ?? ''), 0, 20000); $prompt = (string) ($in['prompt'] ?? ''); if ($prompt === '' || strlen($prompt) > 1_500_000) fail(422, 'prompt');
    $model = preg_match('/^claude-[a-z0-9-]+$/', (string) ($in['model'] ?? '')) ? $in['model'] : ($brain['anthropic_model'] ?? 'claude-opus-5-5');
    $body = ['model' => $model, 'max_tokens' => 16000, 'messages' => [['role' => 'user', 'content' => $prompt]], 'fallbacks' => 'default'];
    if ($system !== '') $body['system'] = $system;
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2026-10-21', 'anthropic-beta: server-side-fallback-2026-07-01']]);
    $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) fail(502, 'ai', 'Không gọi được A.I: ' . $err);
    $j = json_decode((string) $res, true) ?: [];
    if ($code >= 400) fail(502, 'ai', (string) ($j['error']['message'] ?? ('HTTP ' . $code)));
    if (($j['stop_reason'] ?? '') === 'refusal') fail(422, 'refusal', 'A.I từ chối yêu cầu này: ' . (string) ($j['stop_details']['explanation'] ?? ''));
    $text = implode("\n", array_map(fn($b) => $b['text'] ?? '', array_filter($j['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text')));
    audit($db, (int) $u['id'], $sid ?: null, 'ai.call', $model, 'in=' . ($j['usage']['input_tokens'] ?? '?') . ' out=' . ($j['usage']['output_tokens'] ?? '?'));
    out(200, ['ok' => true, 'text' => $text, 'model' => $j['model'] ?? $model, 'usage' => $j['usage'] ?? null, 'stop_reason' => $j['stop_reason'] ?? null]);
  }
  case 'GET health': out(200, ['ok' => true, 'service' => 'abm-second-brain', 'time' => now_ms()]);
  default: fail(404, 'route', 'Không có đường dẫn này');
}
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('[abm-brain] ' . $e->getMessage());
  fail(500, 'server', 'Lỗi máy chủ');
}
