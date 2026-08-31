<?php
declare(strict_types=1);

/**
 * Customer profiles live outside the public document root. A profile is a
 * customer.json file within its own folder, so it can be maintained by hand.
 */
function customerKey(string $value): string {
    return strtolower(trim($value));
}

function loadCustomerProfiles(array $config): array {
    $root = (string) ($config['customers_dir'] ?? '');
    if ($root === '' && !empty($config['tickets_dir'])) {
        $root = dirname((string) $config['tickets_dir']) . DIRECTORY_SEPARATOR . 'customers';
    }
    if ($root === '' || !is_dir($root)) return [];
    $profiles = [];
    foreach (glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'customer.json') ?: [] as $path) {
        $profile = json_decode((string) @file_get_contents($path), true);
        if (!is_array($profile) || empty($profile['id']) || empty($profile['name'])) continue;
        $profile['_dir'] = dirname($path);
        $profiles[(string) $profile['id']] = $profile;
    }
    return $profiles;
}

function profileHasValue(array $values, string $needle): bool {
    $needle = customerKey($needle);
    if ($needle === '') return false;
    foreach ($values as $value) {
        if (customerKey((string) $value) === $needle) return true;
    }
    return false;
}

function resolveCustomer(array $profiles, string $clientId, string $computer, string $user): ?array {
    foreach ($profiles as $profile) {
        $match = is_array($profile['match'] ?? null) ? $profile['match'] : [];
        if (profileHasValue((array) ($match['client_ids'] ?? []), $clientId)) return $profile;
    }
    foreach ($profiles as $profile) {
        $match = is_array($profile['match'] ?? null) ? $profile['match'] : [];
        if (profileHasValue((array) ($match['computers'] ?? []), $computer)) return $profile;
        foreach ((array) ($profile['contacts'] ?? []) as $contact) {
            if (is_array($contact) && profileHasValue((array) ($contact['computers'] ?? []), $computer)) return $profile;
        }
    }
    foreach ($profiles as $profile) {
        $match = is_array($profile['match'] ?? null) ? $profile['match'] : [];
        if (profileHasValue((array) ($match['windows_users'] ?? []), $user)) return $profile;
        foreach ((array) ($profile['contacts'] ?? []) as $contact) {
            if (is_array($contact) && profileHasValue((array) ($contact['windows_users'] ?? []), $user)) return $profile;
        }
    }
    return null;
}

function customerContact(array $profile, string $computer, string $user): ?array {
    foreach ((array) ($profile['contacts'] ?? []) as $contact) {
        if (!is_array($contact)) continue;
        if (profileHasValue((array) ($contact['computers'] ?? []), $computer) || profileHasValue((array) ($contact['windows_users'] ?? []), $user)) return $contact;
    }
    return null;
}

function customerLogoDataUri(?array $profile): string {
    if ($profile === null || empty($profile['_dir']) || empty($profile['logo'])) return '';
    $name = basename((string) $profile['logo']);
    $path = (string) $profile['_dir'] . DIRECTORY_SEPARATOR . $name;
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? '';
    if ($mime === '' || !is_file($path) || filesize($path) > 524288) return '';
    $bytes = @file_get_contents($path);
    return $bytes === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($bytes);
}
