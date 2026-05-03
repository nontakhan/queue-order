<?php

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();
$profiles = app_db_profiles();
$currentKey = app_current_db_profile_key();

app_json_response([
    'success' => true,
    'authenticated' => $user !== null,
    'user' => $user,
    'db_profile' => app_db_profile_payload($currentKey, $profiles[$currentKey]),
]);
