<?php
// api/debug_connection.php
// ไฟล์นี้ใช้สำหรับทดสอบการเชื่อมต่อและ Query หลักเท่านั้น

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ตั้งค่า header เป็น text/html เพื่อให้แสดงผล var_dump สวยงาม
header("Content-Type: text/html; charset=utf-8");

echo "<h1>เริ่มต้นการดีบัก...</h1>";

// 1. ตรวจสอบไฟล์ Config
require_once dirname(__DIR__) . '/db_config.php';
echo "<p><strong>สถานะ:</strong> โหลดไฟล์ db_config.php สำเร็จ</p>";
echo "<p><strong>Host:</strong> {$db_host}</p>";

// 2. สร้างการเชื่อมต่อ
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo "<h2>เกิดข้อผิดพลาดร้ายแรง</h2>";
    echo "<p style='color:red;'><strong>ไม่สามารถเชื่อมต่อฐานข้อมูลได้:</strong> " . $conn->connect_error . "</p>";
    exit;
}
echo "<p><strong>สถานะ:</strong> เชื่อมต่อฐานข้อมูลสำเร็จ</p>";

// 3. ตั้งค่า Character Set
if (!$conn->set_charset("utf8")) {
    echo "<p style='color:orange;'><strong>คำเตือน:</strong> ไม่สามารถตั้งค่า Charset เป็น utf8 ได้: " . $conn->error . "</p>";
} else {
    echo "<p><strong>สถานะ:</strong> ตั้งค่า Charset เป็น utf8 สำเร็จ</p>";
}

// 4. เตรียมและรัน Query ที่ง่ายที่สุด
$sql = "SELECT docno, location_code, delivery_status 
        FROM transfer_data_from_mssql 
        WHERE TRIM(location_code) = '05' 
        AND (delivery_status IS NULL OR delivery_status = '') 
        LIMIT 10";

echo "<h2>กำลังรัน SQL Query ต่อไปนี้:</h2>";
echo "<pre style='background-color:#eee; padding:10px; border:1px solid #ccc;'>" . htmlspecialchars($sql) . "</pre>";

$result = $conn->query($sql);

if ($result === false) {
    echo "<h2>เกิดข้อผิดพลาดในการ Query</h2>";
    echo "<p style='color:red;'><strong>Error:</strong> " . $conn->error . "</p>";
} else {
    echo "<h2>ผลลัพธ์การ Query:</h2>";
    echo "<p><strong>จำนวนแถวที่พบ:</strong> " . $result->num_rows . "</p>";
    
    if ($result->num_rows > 0) {
        echo "<table border='1' cellpadding='5' cellspacing='0'>
                <tr style='background-color:#ddd;'>
                    <th>docno</th>
                    <th>location_code</th>
                    <th>delivery_status</th>
                </tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>
                    <td>" . htmlspecialchars($row['docno']) . "</td>
                    <td>" . htmlspecialchars($row['location_code']) . " (ความยาว: " . strlen($row['location_code']) . ")</td>
                    <td>" . htmlspecialchars($row['delivery_status'] ?? 'NULL') . "</td>
                  </tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:blue;'>ไม่พบข้อมูลที่ตรงกับเงื่อนไข</p>";
    }
}

$conn->close();
echo "<h1>...สิ้นสุดการดีบัก</h1>";

?>
