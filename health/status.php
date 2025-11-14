<?php
require_once(__DIR__ . '/../../app/wds/bootstrap/app.php');
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'time'=>gmdate('Y-m-d H:i:s'),'tz'=>'UTC']);
