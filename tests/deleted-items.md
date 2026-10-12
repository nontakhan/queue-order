# ทดสอบรายการที่ลบ

ฟังก์ชันนี้ใช้ `deleted_transfer_items` ตาม `setup_deleted_items.sql` ในแต่ละชุดฐานข้อมูล ระบบสร้างตารางอัตโนมัติเมื่อใช้งานครั้งแรก และตรวจว่า source/archive ใช้ InnoDB ก่อนลบ สำเนาเก็บทุกคอลัมน์ต้นฉบับ ประวัติรับสินค้า เวลาตาม Asia/Bangkok และผู้ลบใน transaction เดียวกับการนำออกจากตารางเดิม

หน้า `deleted_items.php` และ API รายการ/รายละเอียดต้องมี `role=admin` สิทธิ์ `can_view_all_bills` ไม่ให้เข้าถึงหน้านี้ สคริปต์นำเข้า `mssql_queue_transfer_*_all.py` ทั้ง 4 ชุดนำเข้าตาม flow เดิม: หากข้อมูลต้นทางยังมีอยู่ รายการที่ลบจากตารางหลักจะถูกดึงกลับมาได้ด้วย UPSERT โดยไม่แก้ไขหรือลบประวัติใน `deleted_transfer_items` และยังนำเข้าได้ในฐานข้อมูลที่ไม่มีตารางประวัติ

ชุดทดสอบเชื่อมต่อเฉพาะ MariaDB ที่ `127.0.0.1` พอร์ต `3307` บัญชี root ของฐานข้อมูลทดสอบ เปลี่ยนพอร์ตด้วย `QUEUE_TEST_MYSQL_PORT` ได้ สร้าง/ลบเฉพาะฐานข้อมูลชื่อ `codex_queue_archive_test_*` และ `codex_queue_archive_http_*` หรือ temporary tables ไม่ใช้ฐานข้อมูลตาม config ของแอป

```powershell
& C:\xampp\php\php.exe tests\deleted-items.test.php
node tests\deleted-items-http.test.js
python tests\deleted-items-import.test.py
```

ทดสอบเบราว์เซอร์เพิ่มโดยตั้ง `QUEUE_TEST_PLAYWRIGHT_MODULE` เป็น path ของ Playwright แล้วรันชุด HTTP ใช้ Chrome headless เป็นค่าเริ่มต้น เปลี่ยนด้วย `QUEUE_TEST_BROWSER_CHANNEL` ได้ กำหนด `QUEUE_TEST_SCREENSHOT_DIR` เพื่อเก็บภาพ หากเครือข่ายทดสอบบล็อก CDN ให้ตั้ง `QUEUE_TEST_CDN_DIR` ไปยังโฟลเดอร์ที่มี `tailwind.js`/`sweetalert.js` จาก URL เดียวกับหน้าเว็บ

ผลทดสอบแยกไม่ใช่หลักฐานการลบข้อมูลจริงหรือการ deploy เว็บไซต์บนเซิร์ฟเวอร์
