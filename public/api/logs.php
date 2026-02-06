<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$runId = (string) ($_GET['id'] ?? '');
$runId = preg_replace('/[^a-f0-9]/', '', strtolower($runId));

if ($runId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$logFile = __DIR__ . '/../../storage/logs/run-' . $runId . '.log';
if (!is_file($logFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Log not found']);
    exit;
}

$lines = isset($_GET['lines']) ? (int) $_GET['lines'] : 200;
if ($lines < 20) {
    $lines = 20;
}
if ($lines > 1000) {
    $lines = 1000;
}

$data = file($logFile, FILE_IGNORE_NEW_LINES);
if ($data === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to read log']);
    exit;
}

$tail = array_slice($data, -$lines);

echo json_encode([
    'id' => $runId,
    'lines' => $lines,
    'content' => implode(PHP_EOL, $tail),
]);
