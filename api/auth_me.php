<?php

require_once __DIR__ . '/_bootstrap.php';

$user = app_current_user();

app_json_response([
    'success' => true,
    'authenticated' => $user !== null,
    'user' => $user,
]);

