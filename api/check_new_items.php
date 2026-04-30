<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();
$conn = app_db();

$locationCode = ($user && !empty($user['default_location_code'])) ? $user['default_location_code'] : (isset($_GET['location_code']) ? trim($_GET['location_code']) : '');
if ($locationCode === '') {
    app_json_response(['success' => false, 'message' => 'กรุณาระบุ location_code']);
}

$lastKnownCount = isset($_GET['last_count']) ? (int) $_GET['last_count'] : -1;
$excludedCustomerKeyword = 'เธเธณเธฃเธธเนเธเน€เธเธซเธฐเธ เธฑเธ“เธ‘เนเธชเธณเธเธฑเธเธเธฒเธเนเธซเธเน';
$normalizedCustomerExpr = "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(custname), ' ', ''), '(', ''), ')', ''), 'ใ€€', '')";
$normalizedLocationExpr = "REPLACE(TRIM(location_code), ' ', '')";
$excludedPattern = '%' . $excludedCustomerKeyword . '%';

try {
    $sql = "SELECT COUNT(*) as total_pending
            FROM transfer_data_from_mssql
            WHERE FIND_IN_SET(?, {$normalizedLocationExpr}) > 0
            AND (delivery_status IS NULL OR delivery_status = '')
            AND (custname IS NULL OR {$normalizedCustomerExpr} NOT LIKE ?)";
    $result = app_execute($conn, $sql, 'ss', [$locationCode, $excludedPattern]);
    if ($result === false) {
        throw new Exception('Query failed.');
    }

    $totalPending = (int) $result->fetch_assoc()['total_pending'];
    $newItems = [];

    if ($lastKnownCount >= 0 && $totalPending > $lastKnownCount) {
        $diff = $totalPending - $lastKnownCount;
        $limitItems = min($diff, 10);
        $sqlNew = "SELECT docno, docdate, custname, cd_name, qty, Lname_unit
                   FROM transfer_data_from_mssql
                   WHERE FIND_IN_SET(?, {$normalizedLocationExpr}) > 0
                   AND (delivery_status IS NULL OR delivery_status = '')
                   AND (custname IS NULL OR {$normalizedCustomerExpr} NOT LIKE ?)
                   ORDER BY last_update DESC, docdate DESC
                   LIMIT ?";
        $newResult = app_execute($conn, $sqlNew, 'ssi', [$locationCode, $excludedPattern, $limitItems]);
        if ($newResult) {
            while ($newRow = $newResult->fetch_assoc()) {
                $newItems[] = $newRow;
            }
        }
    }

    $conn->close();
    app_json_response(['success' => true, 'data' => ['location_code' => $locationCode, 'total_pending' => $totalPending, 'new_items' => $newItems]]);
} catch (Exception $e) {
    $conn->close();
    app_error_response('Query Error', 500, $e);
}
