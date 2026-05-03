<?php

require_once __DIR__ . '/_bootstrap.php';

app_start_session();
$profiles = app_db_profiles();
$hasSelectedProfile = isset($_SESSION['db_profile']);
$currentKey = app_current_db_profile_key();
$data = [];

foreach ($profiles as $key => $profile) {
    $data[] = app_db_profile_payload((string) $key, $profile);
}

app_json_response([
    'success' => true,
    'current' => app_db_profile_payload($currentKey, $profiles[$currentKey]),
    'selected' => $hasSelectedProfile,
    'data' => $data,
]);
