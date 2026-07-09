<?php

ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$docno = app_get_post('docno');
$subId = app_get_post('sub_id');
$cdCode = app_get_post('cd_code');
$locationCode = app_get_post('location_code');
$unit = app_get_post('unit');
$price = app_get_post('price');
$shortnote = app_get_post('shortnote');

if ($docno === '' || $cdCode === '' || $locationCode === '') {
    app_json_response(['success' => false, 'message' => 'docno, cd_code, and location_code are required'], 422);
}

if ($price !== '' && !is_numeric($price)) {
    app_json_response(['success' => false, 'message' => 'price must be numeric'], 422);
}

$shortnoteLength = function_exists('mb_strlen') ? mb_strlen($shortnote, 'UTF-8') : strlen($shortnote);
if ($shortnoteLength > 500) {
    app_json_response(['success' => false, 'message' => 'shortnote must be 500 characters or less'], 422);
}

$conn = app_db();

try {
    app_ensure_transfer_shortnote_column($conn);

    $whereClauses = ['docno = ?', 'cd_code = ?', 'location_code = ?'];
    $params = [$shortnote === '' ? null : $shortnote, $docno, $cdCode, $locationCode];
    $types = 'ssss';

    if ($subId !== '') {
        $whereClauses[] = 'sub_id = ?';
        $params[] = $subId;
        $types .= 's';
    }

    if ($unit !== '' && $price !== '') {
        $whereClauses[] = 'Lname_unit = ?';
        $whereClauses[] = 'UNITPRICE = ?';
        $params[] = $unit;
        $params[] = (float) $price;
        $types .= 'sd';
    }

    $sql = 'UPDATE transfer_data_from_mssql SET shortnote = ? WHERE ' . implode(' AND ', $whereClauses);
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->affected_rows > 1) {
        throw new Exception('Shortnote update affected more than one row.');
    }

    $stmt->close();
    $conn->close();

    app_json_response(['success' => true, 'message' => 'Shortnote saved successfully.']);
} catch (Throwable $e) {
    $conn->close();
    app_error_response('Shortnote Update Error', 500, $e);
}
