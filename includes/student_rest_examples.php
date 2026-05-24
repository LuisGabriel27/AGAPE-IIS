<?php
/**
 * Supabase REST API query examples for the students table.
 * Uses cURL + PostgREST headers. Constants are defined in config/config.php.
 * This file is a reference — not included in the request lifecycle.
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Minimal cURL wrapper for Supabase PostgREST (read-only GET).
 *
 * @return array<int, array<string, mixed>>
 */
function supabase_get(string $resource, array $query = []): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . ltrim($resource, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: '        . SUPABASE_ANON_KEY,
            'Authorization: Bearer ' . SUPABASE_ANON_KEY,
            'Accept: application/json',
        ],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log('Supabase REST error ' . $code . ': ' . $body);
        return [];
    }

    return json_decode($body, true) ?? [];
}

// ── Example 1: All students sorted by last_name ASC, then first_name ASC ──
$students = supabase_get('students', [
    'select' => '*',
    'order'  => 'last_name.asc,first_name.asc',
]);

// ── Example 2: Case-insensitive prefix search on last_name ──────────────
$search   = 'dela'; // user-supplied input
$students = supabase_get('students', [
    'select'    => '*',
    'last_name' => 'ilike.' . $search . '*',
    'order'     => 'last_name.asc,first_name.asc',
]);

// ── Example 3: Exact match by last_name ────────────────────────────────
$lastName = 'Santos';
$students = supabase_get('students', [
    'select'    => '*',
    'last_name' => 'eq.' . $lastName,
]);
