<?php
// api/fetch_single_item.php
// ไฟล์นี้ทำหน้าที่เป็น API สำหรับดึงข้อมูลรายการสินค้าเดียวจากฐานข้อมูล MySQL
// โดยใช้ docno และ cd_code เป็นเงื่อนไข

// กำหนด header ให้เป็น JSON เพื่อให้เบราว์เซอร์หรือ client ทราบว่า Response เป็น JSON
header('Content-Type: application/json; charset=utf-8');

// อนุญาต Cross-Origin Resource Sharing (CORS)
// ใน Production ควรระบุ Origin ที่แน่นอนเพื่อความปลอดภัย (เช่น 'http://your-frontend-domain.com')
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

// รวมไฟล์การตั้งค่าฐานข้อมูล
require_once '../db_config.php'; // แก้ไข: ปรับ path ให้ถูกต้องตามโครงสร้างโฟลเดอร์ของคุณ

$conn = null; // กำหนดค่าเริ่มต้นเป็น null
$stmt = null; // กำหนดค่าเริ่มต้นเป็น null

try {
    // สร้างการเชื่อมต่อฐานข้อมูล
    $conn = getDbConnection();
    if (!$conn) {
        throw new Exception("Failed to connect to database.");
    }

    // รับค่า docno และ cd_code จาก GET request
    $docno = isset($_GET['docno']) ? $conn->real_escape_string($_GET['docno']) : '';
    $cd_code = isset($_GET['cd_code']) ? $conn->real_escape_string($_GET['cd_code']) : '';

    // ตรวจสอบว่าได้รับค่าที่จำเป็นครบถ้วนหรือไม่
    if (empty($docno) || empty($cd_code)) {
        throw new Exception("Missing required parameters: docno or cd_code.");
    }

    // สร้าง SQL Query สำหรับดึงข้อมูลรายการเดียว
    // ดึงเฉพาะรายการที่ delivery_status เป็น NULL หรือว่าง เพื่อให้ตรงกับวัตถุประสงค์หลัก
    $sql = "SELECT SHIPFLAG, branch, docno, DOCDATE, CUSTNAME, user_lname, dg_lname, cd_code, cd_name, ct_lname, brand, code, lname, location_code, location, SNAME, LName_unit, qty, REMARK, UNITPRICE, NETAMOUNT, dim5_code, dim5_name, delivery_status
            FROM transfer_data_from_mssql
            WHERE docno = ? AND cd_code = ? AND (delivery_status IS NULL OR delivery_status = '')";

    // เตรียม Prepared Statement
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }

    // Bind parameters: 'ss' สำหรับ string สองตัว (docno, cd_code)
    $stmt->bind_param("ss", $docno, $cd_code);

    // Execute Statement
    $stmt->execute();
    $result = $stmt->get_result();

    $item = null; // กำหนดค่าเริ่มต้นเป็น null
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        // ปรับ format วันที่ให้เป็น 'YYYY-MM-DD' สำหรับ Frontend
        if (isset($item['DOCDATE'])) {
            $item['DOCDATE'] = (new DateTime($item['DOCDATE']))->format('Y-m-d');
        }
    }

    // ส่งข้อมูลกลับในรูปแบบ JSON
    // ถ้าพบรายการ จะส่ง Object นั้นกลับไป, ถ้าไม่พบจะส่ง null
    echo json_encode(['success' => true, 'data' => $item]);

} catch (Exception $e) {
    // จัดการข้อผิดพลาดและส่ง Error Message กลับในรูปแบบ JSON
    error_log("Error in fetch_single_item.php: " . $e->getMessage());
    http_response_code(500); // ตั้ง HTTP Status Code เป็น 500 (Internal Server Error)
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    // ปิดการเชื่อมต่อและ statement เสมอ
    if ($stmt) {
        $stmt->close();
    }
    if ($conn) {
        $conn->close();
    }
}
?>
