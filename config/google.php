<?php
/**
 * Google OAuth 2.0 Client Factory
 * Returns a configured Google_Client instance.
 */

require_once __DIR__ . '/config.php';

$googleAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($googleAutoload)) {
    require_once $googleAutoload;
}

/**
 * Check whether Google OAuth dependencies are available.
 */
function googleOauthAvailable(): bool
{
    return class_exists('Google_Client');
}

/**
 * Build a configured Google client instance.
 *
 * @throws RuntimeException when Google SDK dependencies are missing.
 */
function getGoogleClient()
{
    if (!googleOauthAvailable()) {
        throw new RuntimeException('Google OAuth dependencies are missing. Run composer install.');
    }

    $client = new Google_Client();
    $client->setClientId(GOOGLE_CLIENT_ID);
    $client->setClientSecret(GOOGLE_CLIENT_SECRET);
    $client->setRedirectUri(GOOGLE_REDIRECT_URI);
    $client->addScope('email');
    $client->addScope('profile');
    $client->setAccessType('online');
    $client->setPrompt('select_account');
    return $client;
}
