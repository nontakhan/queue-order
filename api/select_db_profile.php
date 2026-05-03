<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

$key = app_get_post('profile');
if ($key === '') {
    app_json_response(['success' => false, 'message' => 'Database profile is required'], 422);
}

$profile = app_select_db_profile($key);

app_json_response([
    'success' => true,
    'current' => app_db_profile_payload($key, $profile),
]);
