<?php

declare(strict_types=1);

set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$prompt = trim((string) ($payload['prompt'] ?? ''));
$verbose = (bool) ($payload['verbose'] ?? false);
$overrides = is_array($payload['overrides'] ?? null) ? $payload['overrides'] : [];

if ($prompt === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Prompt is required']);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';

use Dalehurley\Phpbot\Bot;

$envPath = __DIR__ . '/../../.env';
if (is_file($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

$configPath = __DIR__ . '/../../config/phpbot.php';
$config = file_exists($configPath) ? require $configPath : [];

$logEnabled = (bool) ($config['log_enabled'] ?? true);
$logDir = $config['log_path'] ?? __DIR__ . '/../../storage/logs';
if ($logEnabled && !is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$clientRunId = preg_replace('/[^a-f0-9\\-]/i', '', (string) ($payload['client_run_id'] ?? ''));
$runId = $clientRunId !== '' ? $clientRunId : bin2hex(random_bytes(8));
$logFile = $logDir . '/run-' . $runId . '.log';

$log = $logEnabled
    ? function (string $message) use ($logFile): void {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND);
    }
    : function (string $message): void {};

$logJson = function (string $label, array $data) use ($log): void {
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $log($label . ': ' . ($encoded !== false ? $encoded : '<<json encode failed>>'));
};

$sendWsEvent = function (string $type, array $data = []) use ($runId, $log): void {
    $payload = array_merge([
        'type' => $type,
        'run_id' => $runId,
        'ts' => time(),
    ], $data);

    $socket = @stream_socket_client('udp://127.0.0.1:8789', $errno, $errstr, 0.05);
    if ($socket === false) {
        $log('WebSocket UDP unavailable: ' . $errstr);
        return;
    }
    @fwrite($socket, json_encode($payload));
    @fclose($socket);
};

register_shutdown_function(function () use ($log) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

$allowedOverrides = [
    'model' => 'string',
    'fast_model' => 'string',
    'super_model' => 'string',
    'max_iterations' => 'int',
    'max_tokens' => 'int',
    'temperature' => 'float',
    'timeout' => 'float',
];

$appliedOverrides = [];
foreach ($allowedOverrides as $key => $type) {
    if (!array_key_exists($key, $overrides)) {
        continue;
    }
    $value = $overrides[$key];
    if ($type === 'string') {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
    } elseif ($type === 'int') {
        $value = (int) $value;
    } elseif ($type === 'float') {
        $value = (float) $value;
    }
    $appliedOverrides[$key] = $value;
}

$config = array_merge($config, $appliedOverrides);

$progress = [];

try {
    $log('Run started');
    $log('Prompt: ' . $prompt);
    $logJson('Request payload', $payload);
    if (!empty($appliedOverrides)) {
        $log('Overrides: ' . json_encode($appliedOverrides));
    }
    $sendWsEvent('started', [
        'message' => 'Run started',
    ]);

    $bot = new Bot($config, $verbose);
    $result = $bot->run($prompt, function (string $stage, string $message) use (&$progress, $sendWsEvent, $log) {
        $progress[] = [
            'stage' => $stage,
            'message' => $message,
            'ts' => time(),
        ];
        $log('Progress: ' . $stage . ' - ' . $message);
        $sendWsEvent('progress', [
            'stage' => $stage,
            'message' => $message,
        ]);
    });

    $response = $result->toArray();
    $response['progress'] = $progress;
    $response['overrides_applied'] = $appliedOverrides;
    $response['log_id'] = $runId;
    $response['log_tail'] = tailFile($logFile, 200);
    $response['run_id'] = $runId;

    $logJson('Result', $response);
    $log('Run completed. Success=' . ($result->isSuccess() ? 'true' : 'false'));
    $sendWsEvent('completed', [
        'success' => $result->isSuccess(),
    ]);
    echo json_encode($response, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $log('Exception: ' . $e->getMessage());
    $log('Trace: ' . $e->getTraceAsString());
    $sendWsEvent('error', [
        'message' => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'log_id' => $runId,
        'run_id' => $runId,
        'log_tail' => tailFile($logFile, 200),
    ]);
}

function tailFile(string $path, int $lines = 200): string
{
    if (!is_file($path)) {
        return '';
    }
    $data = file($path, FILE_IGNORE_NEW_LINES);
    if ($data === false) {
        return '';
    }
    $slice = array_slice($data, -$lines);
    return implode(PHP_EOL, $slice);
}
