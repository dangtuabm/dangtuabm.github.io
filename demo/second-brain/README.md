# ABM Second Brain — Não bộ thứ hai cho Lãnh đạo (bản mẫu)

Nền tảng ghi chú liên kết lấy cảm hứng từ Obsidian, **chạy ngay trên trình duyệt, không cần cài đặt**, một file HTML không phụ thuộc thư viện ngoài, phát hành tại `/demo/second-brain/`. Đã triển khai đủ 3 giai đoạn: cục bộ → đồng bộ đa thiết bị → nhóm, phân quyền, A.I và Nhân sự số.

Triết lý: **Brain First – A.I Second**. Nền tảng giữ bối cảnh (mục tiêu · quyết định · việc · quy trình · tri thức) để Sếp và A.I làm việc trên cùng một sự thật.

## 1. Tính năng lõi (tương đương Obsidian)

| Nhóm | Obsidian | Bản mẫu này |
|---|---|---|
| Định dạng | Markdown thuần, file .md | Markdown thuần, lưu IndexedDB, xuất .zip các file .md chuẩn Obsidian |
| Liên kết | `[[wikilink]]`, backlink, alias | Có, tạo ghi chú mới khi bấm liên kết chưa tồn tại |
| Thẻ | `#tag`, frontmatter | Có, trang Thẻ và lọc theo thẻ |
| Tìm kiếm | Quick switcher, command palette | Ctrl K: tìm tiêu đề + nội dung, lệnh, tạo mới ngay từ ô tìm |
| Sơ đồ | Graph view | Canvas lực, tô màu theo loại ghi chú, kéo, phóng, bấm mở |
| Mẫu | Templates plugin | 8 mẫu lãnh đạo có sẵn |
| Nhật ký | Daily notes | Hôm nay, Rà soát tuần |
| Thuộc tính | Properties (frontmatter) | Bảng thuộc tính bên phải, sửa là cập nhật frontmatter |
| Xem/soạn | Live preview, reading | Soạn · Song song · Xem; checkbox tick được ngay trong bản xem |
| Offline | Cục bộ | PWA: cài lên màn hình điện thoại, dùng không mạng |
| Giao diện | Sáng/tối, theme | Sáng/tối, nhận diện ABM |

## 2. Lớp lãnh đạo – doanh nghiệp (Obsidian không có sẵn)

| Tính năng | Giải quyết bài toán |
|---|---|
| **Tổng quan lãnh đạo** | Mỗi sáng nhìn một màn: việc quá hạn, quyết định chờ duyệt, tiến độ mục tiêu, hộp thu nhận |
| **Hộp thu nhận** | Ghi nhanh một dòng, phân loại sau; mục tiêu hộp trống mỗi tối |
| **Việc cần làm gom tự động** | Mọi dòng `- [ ] Việc @Tên 📅 2026-11-05` ở bất kỳ ghi chú nào được gom theo Quá hạn / Hôm nay / Tuần này / Người phụ trách; tick ở đây cập nhật ngay ghi chú gốc |
| **Sổ quyết định** | Mẫu bối cảnh – phương án – quyết định – giả định – điều kiện đảo ngược – ngày rà soát; bảng theo dõi trạng thái và hạn rà soát |
| **Mục tiêu (OKR)** | Thanh tiến độ, chủ sở hữu, hạn; tiến độ trung bình lên Tổng quan |
| **Biên bản họp, Họp 1:1** | Mẫu có việc cần làm kèm người và hạn, tự chảy vào Việc cần làm |
| **SOP / Playbook** | Mẫu mục đích – vai trò (người / vai số) – các bước – tiêu chí đạt – Ranh giới Đỏ, có phiên bản và chủ sở hữu |
| **Dự án** | Bài toán – giá trị – thước đo – phạm vi – mốc – rủi ro |
| **Phân loại dữ liệu Xanh – Vàng – Đỏ** | Mỗi ghi chú có mức; mức Đỏ **không bao giờ** vào gói ngữ cảnh A.I; mức Vàng phải bật mới gửi |
| **Đóng gói ngữ cảnh cho A.I** | Chọn phạm vi (ghi chú hiện tại, liên kết đi/về, mục tiêu, quyết định 30 ngày, việc mở) + 6 prompt sẵn (tóm tắt, phản biện, soạn SOP, chuẩn bị họp, giao việc cho Nhân sự số) → sao chép dán vào Claude / ChatGPT / Gemini / NotebookLM |
| **Nhập vault Obsidian** | Chọn thư mục vault hoặc nhiều file .md; trùng tiêu đề thì cập nhật |
| **Sao lưu** | Xuất .zip (.md) và .json; nhắc sao lưu khi quá 7 ngày |

## 3. Giai đoạn 2 — Không gian & đồng bộ (đã làm)

Nhiều **không gian** trong một ứng dụng, mỗi không gian chọn một cách lưu:

| Cách lưu | Cơ chế | Cần gì |
|---|---|---|
| Chỉ máy này | IndexedDB | không |
| Kho GitHub | mỗi ghi chú = file .md, Contents API, có lịch sử phiên bản | fine-grained token (Contents: read/write) |
| Google Drive | file .md trong thư mục "ABM Second Brain — <tên>", phạm vi `drive.file` | OAuth Client ID |
| Thư mục trên máy | File System Access API, ghi thẳng vào vault Obsidian | Chrome / Edge máy tính |
| Máy chủ ABM | API PHP (`/api/brain`), xem `api/brain/README.md` | tài khoản ABM |

Quy tắc: ghi chú **mức Đỏ mặc định không đồng bộ**; xung đột hai bên cùng sửa → giữ bản của Sếp, tạo bản sao "(bản máy chủ …)" để tự hợp nhất, không mất chữ; xóa được lan truyền bằng bia mộ; tự đồng bộ mỗi 3 phút và khi quay lại tab.

## 4. Giai đoạn 3 — Nhóm, phân quyền, A.I, Nhân sự số (đã làm)

- **Không gian nhóm** trên máy chủ ABM: vai Chủ / Quản trị / Biên tập / Chỉ xem; mức Đỏ chỉ chủ, quản trị và tác giả đọc được — **máy chủ lọc**, không phải giao diện; nhật ký kiểm toán ai làm gì lúc nào.
- **A.I trực tiếp**: Cổng A.I của ABM (khóa ở máy chủ) hoặc khóa riêng (Anthropic / OpenAI / Gemini). "Hỏi A.I với ngữ cảnh" trả lời ngay trong ứng dụng, lưu thành ghi chú chờ duyệt hoặc chèn vào ghi chú.
- **Nhân sự số**: ghi chú loại `nhan-su-so` khai Vai trò · Nhiệm vụ · Phạm vi ngữ cảnh · Định dạng đầu ra · Điểm con người duyệt. Bấm **Chạy** → kết quả thành ghi chú gắn nhãn `[A.I Generated — Tên — thời gian]`, trạng thái **Chờ duyệt**; Sếp Duyệt hoặc Từ chối. Tổng quan có ô "Kết quả A.I chờ duyệt".
- **Ranh giới Đỏ cưỡng chế**: mức Đỏ không bao giờ vào ngữ cảnh A.I; kết quả A.I thừa kế mức cao nhất của đầu vào.

## 5. Chưa làm (đề xuất tiếp)
- Chạy Nhân sự số theo lịch tự động (cần tiến trình nền ở máy chủ).
- Kết nối Notion (API Notion không cho gọi từ trình duyệt, cần máy chủ trung gian).
- Đồng bộ thời gian thực (hiện là 3 phút hoặc bấm tay).

## 6. Cú pháp cần nhớ

```
[[Tên ghi chú]]              liên kết hai chiều
#the-cua-sep                 thẻ
- [ ] Việc @Tên 📅 2026-11-05  việc có người và hạn
---                          thuộc tính ở đầu ghi chú:
loai: quyet-dinh | hop | muc-tieu | sop | du-an | nhat-ky | thu-nhan | nhan-su-so | ghi-chu
muc_do: xanh | vang | do
trang_thai: moi | dang-lam | cho-duyet | da-chot | hoan-thanh | tam-dung | luu-tru
tien_do: 0-100 · ra_soat: YYYY-MM-DD · han: YYYY-MM-DD · chu_so_huu: Tên
---
```

## 7. Phím tắt

Ctrl/⌘ K tìm & lệnh · Ctrl/⌘ N ghi chú mới · Ctrl/⌘ E đổi chế độ · Ctrl/⌘ S lưu · Esc đóng.
