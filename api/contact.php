<?php

declare(strict_types=1);

require __DIR__ . '/contact_lib.php';

const CONTACT_CONFIG_PATH = __DIR__ . '/../config/contact.env';
const CONTACT_STORAGE_PATH = __DIR__ . '/../var/contact_messages.php';
const DEFAULT_CONTACT_RECIPIENT = 'nikolay.ishchenko@gmail.com';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$config = load_contact_config(CONTACT_CONFIG_PATH);
if (!isset($config['CONTACT_RECIPIENT_EMAIL']) || trim((string) $config['CONTACT_RECIPIENT_EMAIL']) === '') {
    $config['CONTACT_RECIPIENT_EMAIL'] = DEFAULT_CONTACT_RECIPIENT;
}

$payload = extract_payload();

$name = clean_string($payload['name'] ?? '');
$contactMethod = clean_string($payload['contactMethod'] ?? '');
$contact = clean_string($payload['contact'] ?? '');
$projectType = clean_string($payload['projectType'] ?? '');
$message = clean_multiline_string($payload['message'] ?? '');
$language = clean_string($payload['language'] ?? '');
$page = clean_string($payload['page'] ?? '');

$validationError = validate_lead_payload($name, $contactMethod, $contact, $projectType, $message);
if ($validationError !== null) {
    respond_json(422, ['ok' => false, 'error' => $validationError]);
}

$lead = [
    'id' => generate_lead_id(),
    'timestamp' => gmdate('c'),
    'name' => $name,
    'contact_method' => $contactMethod,
    'contact' => $contact,
    'project_type' => $projectType,
    'message' => $message,
    'language' => $language,
    'page' => $page,
    'delivery_status' => 'stored',
    'delivery_error' => '',
    'updated_at' => gmdate('c'),
    'stored_at' => gmdate('c'),
];

try {
    append_stored_message(CONTACT_STORAGE_PATH, $lead);
} catch (Throwable $exception) {
    error_log('[contact.php] storage failed: ' . $exception->getMessage());
    respond_json(500, ['ok' => false, 'error' => 'storage_failed']);
}

$deliveryResult = attempt_php_mail_delivery($config, $lead);

try {
    update_stored_message_delivery(
        CONTACT_STORAGE_PATH,
        $lead['id'],
        (string) ($deliveryResult['status'] ?? 'pending'),
        (string) ($deliveryResult['error'] ?? '')
    );
} catch (Throwable $exception) {
    error_log('[contact.php] delivery status update failed: ' . $exception->getMessage());
}

if (($deliveryResult['status'] ?? 'stored') !== 'sent' && ($deliveryResult['error'] ?? '') !== '') {
    error_log('[contact.php] email notification not delivered for lead ' . $lead['id'] . ': ' . $deliveryResult['error']);
}

respond_json(200, [
    'ok' => true,
    'stored' => true,
    'leadId' => $lead['id'],
    'deliveryStatus' => (string) ($deliveryResult['status'] ?? 'pending'),
]);
