# ABM Second Brain — API máy chủ (Giai đoạn 2–3)

Một file `index.php` + lược đồ `schema.sql`, chạy trên cùng host PHP/MySQL đang phục vụ `api/lead.php`. Không cần framework, không cần Composer.

## Chức năng
- Tài khoản (email + mật khẩu băm, token 30 ngày), đăng ký mở hoặc theo mã mời.
- Không gian nhóm với 4 vai: **chu** (chủ), **quan-tri**, **bien-tap**, **xem**.
- Đồng bộ ghi chú theo mốc thời gian, phát hiện xung đột (máy khách gửi `base` = mốc máy chủ đã thấy).
- **Mức dữ liệu cưỡng chế ở tầng lưu trữ**: ghi chú có `muc_do: do` chỉ trả về cho chủ, quản trị hoặc chính tác giả; vai "xem" không ghi được; biên tập không ghi đè được ghi chú Đỏ của người khác.
- Nhật ký kiểm toán `brain_audit`: ai ghi/xóa ghi chú nào, đổi vai ai, gọi A.I bao nhiêu token.
- Cổng A.I của doanh nghiệp: `POST ai/complete` gọi Anthropic bằng khóa đặt ở máy chủ, thành viên không cần khóa riêng; có kiểm toán.

## Cài đặt
1. Chép thư mục `api/brain/` lên host (cùng chỗ với `api/lead.php`).
2. Chạy `schema.sql` trên MySQL (một lần).
3. Thêm vào `abm-config.php` (file nằm NGOÀI thư mục web, đã có sẵn cho form lead):
```php
return [
  'dsn' => 'mysql:host=localhost;dbname=abm;charset=utf8mb4', 'user' => '...', 'pass' => '...', 'salt' => '...',
  'allowed_origins' => ['https://abmedu.vn', 'https://www.abmedu.vn'],
  'brain' => [
    'open_register' => false,          // true: ai cũng đăng ký được; false: cần invite_code
    'invite_code'   => 'ABM-2026',      // mã mời do ABM cấp cho học viên / khách
    'auto_schema'   => false,           // chỉ bật khi chạy thử với SQLite
    'anthropic_key' => '',              // điền để mở "Cổng A.I của ABM" cho thành viên
    'anthropic_model' => 'claude-opus-5-5',
  ],
];
```
4. Kiểm tra: `https://abmedu.vn/api/brain/index.php?r=health` trả về `{"ok":true}`.
5. Trong ứng dụng → Không gian & Cài đặt → Tài khoản ABM: máy chủ `/api/brain/index.php`, đăng ký, tạo không gian nhóm, thêm thành viên theo email.

## Chạy thử cục bộ (SQLite)
```bash
cat > /tmp/abm-config.php <<'PHP'
<?php return ['dsn' => 'sqlite:/tmp/brain.sqlite', 'salt' => 'dev', 'allowed_origins' => ['http://localhost:8080'],
  'brain' => ['open_register' => true, 'auto_schema' => true]];
PHP
ABM_CONFIG=/tmp/abm-config.php php -S 127.0.0.1:8080 -t .
```

## Đường dẫn (`index.php?r=…`, JSON, `Authorization: Bearer <token>`)
| Phương thức · r | Việc | Quyền |
|---|---|---|
| POST auth/register · auth/login · auth/logout | tài khoản | — |
| GET me | hồ sơ + danh sách không gian + vai | đăng nhập |
| POST spaces | tạo không gian nhóm (người tạo là chủ) | đăng nhập |
| GET spaces/members · POST spaces/members · POST spaces/members/remove | thành viên | chủ, quản trị (gán quản trị: chỉ chủ) |
| GET notes?space&since | kéo thay đổi từ mốc `since` (lọc mức Đỏ theo vai) | mọi vai |
| POST notes {space, notes:[{id,title,body,path,updated,base,deleted}]} | đẩy; trả `accepted / conflicts / rejected` | chủ, quản trị, biên tập |
| GET audit?space | 200 dòng nhật ký gần nhất | chủ, quản trị |
| POST ai/complete {space, system, prompt, model} | gọi A.I bằng khóa máy chủ | đăng nhập |
| GET health | kiểm tra sống | — |

## An toàn
- Mật khẩu `password_hash`; token lưu dạng SHA-256; giới hạn 10 lần đăng nhập sai / 10 phút / IP (IP chỉ lưu băm).
- CORS chỉ cho `allowed_origins`; mọi truy vấn dùng prepared statement.
- Khóa A.I và chuỗi kết nối không nằm trong mã nguồn.
