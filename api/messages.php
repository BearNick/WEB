<?php

declare(strict_types=1);

require __DIR__ . '/contact_lib.php';

const CONTACT_CONFIG_PATH = __DIR__ . '/../config/contact.env';
const CONTACT_STORAGE_PATH = __DIR__ . '/../var/contact_messages.php';

$config = load_contact_config(CONTACT_CONFIG_PATH);
$adminToken = trim((string) ($config['ADMIN_REVIEW_TOKEN'] ?? ''));

if ($adminToken === '') {
    respond_json(404, ['ok' => false, 'error' => 'not_found']);
}

$providedToken = trim((string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['token'] ?? '')));
if ($providedToken === '' || !hash_equals($adminToken, $providedToken)) {
    respond_json(403, ['ok' => false, 'error' => 'forbidden']);
}

$limit = (int) ($_GET['limit'] ?? 50);
$limit = max(1, min($limit, 200));

respond_json(200, [
    'ok' => true,
    'messages' => read_stored_messages(CONTACT_STORAGE_PATH, $limit),
]);
