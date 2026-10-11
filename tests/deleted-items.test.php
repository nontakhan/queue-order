<?php
// Runs only against a dedicated localhost MariaDB instance, never the configured app database.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$helper = dirname(__DIR__) . '/api/_deleted_items.php';
if (!is_file($helper)) {
    fwrite(STDERR, "FAIL: cancelled items have no archive operation\n");
    exit(1);
}
require $helper;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

function rejected(callable $action, string $message): void
{
    try {
        $action();
    } catch (Throwable $e) {
        check(true, $message);
        return;
    }
    check(false, $message);
}

$conn = new mysqli('127.0.0.1', 'root', '', '', (int) (getenv('QUEUE_TEST_MYSQL_PORT') ?: 3307));
$conn->set_charset('utf8mb4');
$database = 'codex_queue_archive_test_' . bin2hex(random_bytes(5));
$conn->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$conn->select_db($database);
try {
    $conn->query("CREATE TABLE transfer_data_from_mssql (
        docno VARCHAR(100), sub_id VARCHAR(100), cd_code VARCHAR(100), location_code VARCHAR(100),
        Lname_unit VARCHAR(100), UNITPRICE DECIMAL(15,4), user_lname VARCHAR(255),
        delivery_status VARCHAR(50), custname VARCHAR(255), cd_name VARCHAR(255), location VARCHAR(255),
        qty DECIMAL(15,3), shortnote VARCHAR(500), unknown_future_column VARCHAR(100),
        PRIMARY KEY (docno, sub_id, cd_code)
    ) ENGINE=InnoDB");
    // Match the live source's mixed-case column names (mysqli preserves their case).
    $conn->query('ALTER TABLE transfer_data_from_mssql CHANGE custname CUSTNAME VARCHAR(255), CHANGE Lname_unit LName_unit VARCHAR(100)');
    $conn->query("CREATE TABLE item_receive_history (
        id INT AUTO_INCREMENT PRIMARY KEY, docno VARCHAR(100), cd_code VARCHAR(100), lname_unit VARCHAR(100),
        unitprice DECIMAL(15,4), received_qty DECIMAL(15,3), received_by_employee VARCHAR(100),
        received_at DATETIME, note VARCHAR(500)
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO transfer_data_from_mssql VALUES
        ('DOC-A','1','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้าไทย','สินค้าไทย','คลัง A',3.000,'บันทึกเดิม','ข้อมูลเพิ่ม'),
        ('DOC-B','2','SKU','05','ชิ้น',12.3400,'เจ้าของ A','รับแล้ว','ลูกค้า B','สินค้า','คลัง A',5,NULL,NULL),
        ('DOC-C','3','SKU','06','ชิ้น',12.3400,'เจ้าของ B','ยกเลิก','ลูกค้า C','สินค้า','คลัง B',2,NULL,NULL),
        ('DOC-D','4','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้า D','สินค้า','คลัง A',2,NULL,NULL),
        ('DOC-D','5','SKU','05','ชิ้น',12.3400,'เจ้าของ A','ยกเลิก','ลูกค้า D','สินค้า','คลัง A',2,NULL,NULL)");
    $conn->query("INSERT INTO item_receive_history VALUES (NULL,'DOC-A','SKU','ชิ้น',12.3400,1,'พนักงาน','2026-10-10 10:00:00','รับครั้งแรก')");
    $user = ['id' => 7, 'username' => 'seller', 'full_name' => 'ผู้ลบ A', 'role' => 'user', 'sales_lname' => 'เจ้าของ A', 'default_location_code' => '05'];
    $target = ['docno' => 'DOC-A', 'sub_id' => '1', 'cd_code' => 'SKU', 'location_code' => '05', 'unit' => 'ชิ้น', 'price' => '12.3400'];
    app_ensure_deleted_items_table($conn);
    app_ensure_deleted_items_table($conn);
    check(app_archive_cancelled_items($conn, [$target], $user) === 1, 'one cancelled row is archived');
    check((int) $conn->query("SELECT COUNT(*) FROM transfer_data_from_mssql WHERE docno='DOC-A'")->fetch_row()[0] === 0, 'archived row leaves the active table');
    $archive = $conn->query('SELECT * FROM deleted_transfer_items')->fetch_assoc();
    $snapshot = json_decode($archive['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    check($snapshot['item']['unknown_future_column'] === 'ข้อมูลเพิ่ม' && $snapshot['item']['UNITPRICE'] === '12.3400' && $snapshot['item']['shortnote'] === 'บันทึกเดิม', 'snapshot preserves every source field and decimal precision');
    check($snapshot['receive_history'][0]['note'] === 'รับครั้งแรก', 'receive history is preserved with the deleted row');
    check($archive['customer_name'] === 'ลูกค้าไทย', 'live uppercase customer column populates archive search metadata');
    check((int) $archive['deleted_by_user_id'] === 7 && $archive['deleted_by_name'] === 'ผู้ลบ A' && $archive['deleted_at'] !== '', 'deletion records actor and timestamp');
    rejected(fn() => app_archive_cancelled_items($conn, [$target], $user), 'repeat deletion cannot create another archive');
    $received = array_replace($target, ['docno' => 'DOC-B', 'sub_id' => '2']);
    rejected(fn() => app_archive_cancelled_items($conn, [$received], $user), 'non-cancelled rows cannot be deleted');
    $other = array_replace($target, ['docno' => 'DOC-C', 'sub_id' => '3', 'location_code' => '06', 'user_lname' => 'เจ้าของ A']);
    rejected(fn() => app_archive_cancelled_items($conn, [$other], $user), 'warehouse scope prevents deleting another warehouse');
    rejected(fn() => app_archive_cancelled_items($conn, [$other], $user, true), 'sales owner cannot be spoofed');
    $ambiguous = array_replace($target, ['docno' => 'DOC-D', 'sub_id' => '']);
    rejected(fn() => app_archive_cancelled_items($conn, [$ambiguous], null), 'ambiguous identities cannot delete several rows');
    $cancelled = array_replace($target, ['docno' => 'DOC-D', 'sub_id' => '4']);
    rejected(fn() => app_archive_cancelled_items($conn, [$cancelled, $received], $user), 'a failed batch rolls back earlier archive and deletion');
    check((int) $conn->query("SELECT COUNT(*) FROM transfer_data_from_mssql WHERE docno='DOC-D'")->fetch_row()[0] === 2 && (int) $conn->query('SELECT COUNT(*) FROM deleted_transfer_items')->fetch_row()[0] === 1, 'failed batch leaves source and archive unchanged');
    $conn->query("CREATE TRIGGER reject_archive BEFORE INSERT ON deleted_transfer_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Archive write failed'");
    rejected(fn() => app_archive_cancelled_items($conn, [$cancelled], $user), 'failed archive insert keeps the source row');
    check((int) $conn->query("SELECT COUNT(*) FROM transfer_data_from_mssql WHERE docno='DOC-D'")->fetch_row()[0] === 2, 'source remains after a database write failure');
    $conn->query('DROP TRIGGER reject_archive');
    check(app_archive_cancelled_items($conn, [$cancelled], null) === 1, 'existing anonymous warehouse flow can archive cancelled rows');
    $conn->query("ALTER TABLE transfer_data_from_mssql DROP PRIMARY KEY, ENGINE=MyISAM");
    rejected(fn() => app_archive_cancelled_items($conn, [array_replace($cancelled, ['sub_id' => '5'])], null), 'nontransactional source engine fails safely before deletion');
    echo "DELETED_ITEMS_DB_OK\n";
} finally {
    $conn->query("DROP DATABASE `$database`");
    $conn->close();
}
