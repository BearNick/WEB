<?php

declare(strict_types=1);

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_contact_config(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $config = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value);

        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === '\'' && substr($value, -1) === '\''))) {
            $value = substr($value, 1, -1);
        }

        $config[$key] = $value;
    }

    return $config;
}

function extract_payload(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    $rawBody = file_get_contents('php://input') ?: '';

    if ($contentType === 'application/json' || ($rawBody !== '' && str_starts_with(ltrim($rawBody), '{'))) {
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            respond_json(400, ['ok' => false, 'error' => 'invalid_json']);
        }

        return $payload;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    if ($contentType === 'application/x-www-form-urlencoded') {
        parse_str($rawBody, $payload);

        if (is_array($payload)) {
            return $payload;
        }
    }

    respond_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

function clean_string(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $string = trim((string) $value);
    $string = preg_replace("/[\r\n\t]+/", ' ', $string);

    return $string === null ? '' : trim($string);
}

function clean_multiline_string(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $string = trim((string) $value);
    $string = str_replace(["\r\n", "\r"], "\n", $string);
    $string = preg_replace("/\n{3,}/", "\n\n", $string);

    return $string === null ? '' : trim($string);
}

function utf8_length(string $value): int
{
    if ($value === '') {
        return 0;
    }

    preg_match_all('/./us', $value, $matches);
    return count($matches[0]);
}

function validate_lead_payload(string $name, string $contactMethod, string $contact, string $projectType, string $message): ?string
{
    if ($name === '') {
        return 'name_required';
    }

    $allowedContactMethods = ['telegram', 'email', 'whatsapp', 'phone'];
    $allowedProjectTypes = ['', 'website', 'telegram_bot', 'pwa', 'automation', 'other'];

    if (!in_array($contactMethod, $allowedContactMethods, true)) {
        return 'contact_method_required';
    }

    if ($contact === '') {
        return 'contact_required';
    }

    if (!in_array($projectType, $allowedProjectTypes, true)) {
        return 'project_type_invalid';
    }

    if (utf8_length($message) < 10) {
        return 'message_too_short';
    }

    if ($contactMethod === 'email' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        return 'invalid_email';
    }

    if ($contactMethod === 'telegram' && !preg_match('/^(@[A-Za-z0-9_]{5,32}|https?:\/\/(t\.me|telegram\.me)\/[A-Za-z0-9_]{5,32}\/?)$/i', $contact)) {
        return 'invalid_telegram';
    }

    if (($contactMethod === 'phone' || $contactMethod === 'whatsapp') && !preg_match('/^[0-9+\s().-]{7,20}$/', $contact)) {
        return 'invalid_phone';
    }

    return null;
}

function generate_lead_id(): string
{
    return bin2hex(random_bytes(8));
}

function normalize_config_value(string $value, array $placeholderValues = []): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $blocked = array_merge([
        '...',
        'replace-me',
        'replace-with-real-value',
        'replace-with-real-email@example.com',
        'replace-with-real-sender@example.com',
    ], $placeholderValues);

    if (in_array(strtolower($trimmed), array_map('strtolower', $blocked), true)) {
        return '';
    }

    return $trimmed;
}

function extract_email_address(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $matches)) {
        $value = $matches[1];
    }

    $value = trim($value);
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

function escape_mail_header_value(string $value): string
{
    return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
}

function humanize_value(string $value): string
{
    return $value === '' ? 'not specified' : ucwords(str_replace('_', ' ', $value));
}

function build_notification_subject(array $lead): string
{
    return 'New contact request from slaynet.fun [' . $lead['id'] . ']';
}

function build_notification_body(array $lead): string
{
    $lines = [
        'Lead ID: ' . $lead['id'],
        'Timestamp: ' . $lead['timestamp'],
        'Name: ' . $lead['name'],
        'Preferred contact method: ' . humanize_value((string) $lead['contact_method']),
        'Contact: ' . $lead['contact'],
        'Project type: ' . humanize_value((string) $lead['project_type']),
        'Language: ' . ($lead['language'] !== '' ? $lead['language'] : 'unknown'),
        'Page: ' . ($lead['page'] !== '' ? $lead['page'] : 'unknown'),
        '',
        'Message:',
        (string) $lead['message'],
    ];

    return implode("\n", $lines);
}

function build_mail_headers(array $config, array $lead, string $fromEmail, string $fromName): string
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . ($fromName !== '' ? escape_mail_header_value($fromName) . ' <' . $fromEmail . '>' : $fromEmail),
        'X-SlayNet-Lead-ID: ' . $lead['id'],
    ];

    if (($lead['contact_method'] ?? '') === 'email') {
        $replyTo = extract_email_address((string) ($lead['contact'] ?? ''));
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
    }

    return implode("\r\n", $headers);
}

function attempt_php_mail_delivery(array $config, array $lead): array
{
    if (!function_exists('mail')) {
        return ['status' => 'pending', 'error' => 'php_mail_unavailable'];
    }

    $recipient = extract_email_address(normalize_config_value((string) ($config['CONTACT_RECIPIENT_EMAIL'] ?? ''), [
        'replace-with-your-email@example.com',
    ]));
    $fromEmail = extract_email_address(normalize_config_value((string) ($config['MAIL_FROM_EMAIL'] ?? ''), [
        'replace-with-your-sender@example.com',
    ]));
    $fromName = escape_mail_header_value(normalize_config_value((string) ($config['MAIL_FROM_NAME'] ?? 'SlayNet Contact Form')));
    $mailExtraParams = normalize_config_value((string) ($config['MAIL_EXTRA_PARAMS'] ?? ''));

    if ($recipient === '') {
        return ['status' => 'pending', 'error' => 'mail_recipient_not_configured'];
    }

    if ($fromEmail === '') {
        return ['status' => 'pending', 'error' => 'mail_from_not_configured'];
    }

    $subject = build_notification_subject($lead);
    $body = build_notification_body($lead);
    $headers = build_mail_headers($config, $lead, $fromEmail, $fromName);

    try {
        $sent = $mailExtraParams !== ''
            ? @mail($recipient, $subject, $body, $headers, $mailExtraParams)
            : @mail($recipient, $subject, $body, $headers);
    } catch (Throwable $exception) {
        return ['status' => 'failed', 'error' => $exception->getMessage()];
    }

    if ($sent !== true) {
        $error = error_get_last();
        return ['status' => 'failed', 'error' => trim((string) ($error['message'] ?? 'php_mail_failed'))];
    }

    return ['status' => 'sent', 'error' => ''];
}

function ensure_storage_file(string $path): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create storage directory');
    }

    if (!is_file($path)) {
        $initial = "<?php exit; ?>\n";
        if (file_put_contents($path, $initial, LOCK_EX) === false) {
            throw new RuntimeException('Failed to initialize storage file');
        }

        @chmod($path, 0660);
    }
}

function append_stored_message(string $path, array $record): void
{
    ensure_storage_file($path);

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Failed to open storage file');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Failed to lock storage file');
        }

        $stat = fstat($handle);
        if (($stat['size'] ?? 0) === 0) {
            fwrite($handle, "<?php exit; ?>\n");
        }

        fseek($handle, 0, SEEK_END);
        $size = (int) (($stat['size'] ?? 0));
        if ($size > 0) {
            fseek($handle, -1, SEEK_END);
            $lastByte = fread($handle, 1);
            if ($lastByte !== "\n") {
                fseek($handle, 0, SEEK_END);
                fwrite($handle, "\n");
            }
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false || fwrite($handle, $line . "\n") === false) {
            throw new RuntimeException('Failed to append stored message');
        }

        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function update_stored_message_delivery(string $path, string $leadId, string $deliveryStatus, string $deliveryError = ''): void
{
    ensure_storage_file($path);

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Failed to open storage file');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Failed to lock storage file');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $contents = $contents === false ? '' : $contents;
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $rewritten = ["<?php exit; ?>"];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === '<?php exit; ?>') {
                continue;
            }

            if (str_starts_with($trimmed, '<?php exit; ?>')) {
                $trimmed = trim(substr($trimmed, strlen('<?php exit; ?>')));
            }

            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                // Preserve unexpected lines verbatim so delivery updates never destroy stored data.
                $rewritten[] = $trimmed;
                continue;
            }

            if (($decoded['id'] ?? '') === $leadId) {
                $decoded['delivery_status'] = $deliveryStatus;
                $decoded['delivery_error'] = $deliveryError;
                $decoded['updated_at'] = gmdate('c');
                if ($deliveryStatus === 'sent') {
                    $decoded['delivered_at'] = gmdate('c');
                }
            }

            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $rewritten[] = $encoded;
            }
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, implode("\n", $rewritten) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function read_stored_messages(string $path, int $limit = 50): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $messages = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        if ($trimmed === '<?php exit; ?>') {
            continue;
        }

        if (str_starts_with($trimmed, '<?php exit; ?>')) {
            $trimmed = trim(substr($trimmed, strlen('<?php exit; ?>')));
        }

        if ($trimmed === '') {
            continue;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $messages[] = $decoded;
        }
    }

    return array_slice(array_reverse($messages), 0, max(1, $limit));
}
