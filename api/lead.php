<?php
// ABM — nhận yêu cầu tư vấn từ form website và lưu vào MySQL (theo đề xuất của IT: dữ liệu vào database, không lưu file).
// Cấu hình kết nối KHÔNG nằm trong mã nguồn: đặt file abm-config.php NGOÀI thư mục web (xem server/README-SERVER.md).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'method']);

$cfgPath = getenv('ABM_CONFIG') ?: dirname(__DIR__, 2) . '/abm-config.php';
if (!is_readable($cfgPath)) out(500, ['ok' => false, 'error' => 'config']);
$cfg = require $cfgPath; // trả về ['dsn'=>..., 'user'=>..., 'pass'=>..., 'salt'=>..., 'allowed_origins'=>[...]]

// Chỉ nhận từ chính website
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $cfg['allowed_origins'] ?? [], true)) out(403, ['ok' => false, 'error' => 'origin']);

// Bẫy chống spam: trường ẩn phải rỗng
if (trim((string)($_POST['website'] ?? '')) !== '') out(200, ['ok' => true]);

$s = fn(string $k, int $max) => mb_substr(trim(strip_tags((string)($_POST[$k] ?? ''))), 0, $max);
$data = [
  'role' => $s('role', 20), 'need' => $s('need', 120), 'name' => $s('name', 80), 'title' => $s('title', 80),
  'org' => $s('org', 120), 'phone' => preg_replace('/[^0-9+]/', '', $s('phone', 20)), 'email' => $s('email', 120),
  'pref_time' => $s('time', 80), 'message' => $s('message', 1000), 'page' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
];
if (!in_array($data['role'], ['leader', 'biz', 'partner', 'student'], true)) out(422, ['ok' => false, 'error' => 'role']);
if ($data['name'] === '' || strlen($data['phone']) < 9) out(422, ['ok' => false, 'error' => 'required']);
if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) out(422, ['ok' => false, 'error' => 'email']);
if (($_POST['consent'] ?? '') !== '1') out(422, ['ok' => false, 'error' => 'consent']);

try {
  $db = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
  // Giới hạn tần suất: tối đa 5 yêu cầu / 10 phút / IP (IP chỉ lưu dạng băm)
  $ipHash = hash('sha256', ($cfg['salt'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
  $q = $db->prepare('SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)');
  $q->execute([$ipHash]);
  if ((int)$q->fetchColumn() >= 5) out(429, ['ok' => false, 'error' => 'rate']);

  $ins = $db->prepare('INSERT INTO leads (role, need, name, title, org, phone, email, pref_time, message, page, ip_hash, consent_at)
                       VALUES (:role, :need, :name, :title, :org, :phone, :email, :pref_time, :message, :page, :ip_hash, NOW())');
  $ins->execute($data + ['ip_hash' => $ipHash]);
  out(200, ['ok' => true]);
} catch (Throwable $e) {
  error_log('[abm-lead] ' . $e->getMessage());
  out(500, ['ok' => false, 'error' => 'server']);
}
