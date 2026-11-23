<?php
declare(strict_types=1);

/*
 * Run from the command line only.
 * Store this file and its state file outside public_html.
 *
 * Requires SLACK_WEBHOOK_URL in the environment (cron, systemd, shell export).
 */

const STATUS_URL = 'https://isviesdown.eu/status.json';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$stateFile = __DIR__ . '/vies-status-state.json';
$lockFile  = __DIR__ . '/vies-status-monitor.lock';

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
            'Set SLACK_WEBHOOK_URL in the environment before running the monitor.'
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

    foreach ($current as $country => $status) {
        $oldStatus = $previous[$country] ?? null;

        if ($status === 'down' && $oldStatus !== 'down') {
            $changes[] = "🔴 *{$country}* is DOWN";
        } elseif ($status === 'up' && $oldStatus === 'down') {
            $changes[] = "🟢 *{$country}* is back UP";
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

