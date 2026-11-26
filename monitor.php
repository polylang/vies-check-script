<?php
declare(strict_types=1);

/*
 * Run from the command line only.
 * Store this file and its state file outside public_html.
 *
 * SLACK_WEBHOOK_URL: copy .env.example to .env, or set in the environment.
 */

const STATUS_URL = 'https://isviesdown.eu/status.json';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

loadEnvFile(__DIR__ . '/.env');

$stateFile = __DIR__ . '/vies-status-state.json';
$lockFile  = __DIR__ . '/vies-status-monitor.lock';

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $equalsAt = strpos($line, '=');
        if ($equalsAt === false) {
            continue;
        }

        $name = trim(substr($line, 0, $equalsAt));
        $value = trim(substr($line, $equalsAt + 1));

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if (
            strlen($value) >= 2
            && $value[0] === $value[-1]
            && ($value[0] === '"' || $value[0] === "'")
        ) {
            $value = substr($value, 1, -1);
        }

        putenv("{$name}={$value}");
    }
}

function fetchStatuses(): array
{
    $ch = curl_init(STATUS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'VIES-Status-Monitor/1.0',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        throw new RuntimeException(
            "Could not fetch status JSON (HTTP {$httpCode}): {$error}"
        );
    }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected JSON format.');
    }

    $statuses = [];

    foreach ($data as $row) {
        if (!is_array($row) || !isset($row['country'], $row['status'])) {
            continue;
        }

        $country = strtoupper(trim((string) $row['country']));
        $status  = strtolower(trim((string) $row['status']));

        if ($country !== '' && in_array($status, ['up', 'down'], true)) {
            $statuses[$country] = $status;
        }
    }

    if (!$statuses) {
        throw new RuntimeException('No country statuses found in the JSON.');
    }

    return $statuses;
}

function slackWebhookUrl(): string
{
    $url = getenv('SLACK_WEBHOOK_URL');
    if (!is_string($url) || $url === '') {
        throw new RuntimeException(
            'Set SLACK_WEBHOOK_URL in .env (see .env.example) or in the environment.'
        );
    }

    return $url;
}

function sendSlackMessage(string $message): void
{
    $ch = curl_init(slackWebhookUrl());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['text' => $message], JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300 || trim($body) !== 'ok') {
        throw new RuntimeException(
            "Slack webhook failed (HTTP {$httpCode}): {$error} {$body}"
        );
    }
}

function saveState(string $path, array $state): void
{
    $temporaryFile = tempnam(__DIR__, '.vies-state-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Could not create temporary state file.');
    }

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryFile, $json) === false) {
        throw new RuntimeException('Could not write state file.');
    }

    chmod($temporaryFile, 0600);

    if (!rename($temporaryFile, $path)) {
        @unlink($temporaryFile);
        throw new RuntimeException('Could not update state file.');
    }
}

$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // Another run is already active.
}

try {
    $current = fetchStatuses();

    $previous = [];
    if (is_file($stateFile)) {
        $decoded = json_decode(
            (string) file_get_contents($stateFile),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($decoded)) {
            throw new RuntimeException('The saved state file is invalid.');
        }
        $previous = $decoded;
    }

    $changes = [];
    $recovered = false;

    foreach ($current as $country => $status) {
        $oldStatus = $previous[$country] ?? null;

        if ($status === 'down' && $oldStatus !== 'down') {
            $changes[] = "🔴 *{$country}* is DOWN";
        } elseif ($status === 'up' && $oldStatus === 'down') {
            $changes[] = "🟢 *{$country}* is back UP";
            $recovered = true;
        }
    }

    if ($recovered) {
        foreach ($current as $country => $status) {
            if ($status === 'down' && ($previous[$country] ?? null) === 'down') {
                $changes[] = "🔴 *{$country}* is still DOWN";
            }
        }
    }

    if ($changes) {
        $message = "*VIES availability update*\n"
            . implode("\n", $changes)
            . "\n<https://isviesdown.eu/|View status>";

        // Save state only after Slack accepts the alert, so a failed send retries.
        sendSlackMessage($message);
    }

    // Keep prior entries if a country is temporarily absent from the response.
    saveState($stateFile, array_merge($previous, $current));
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' VIES monitor error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

