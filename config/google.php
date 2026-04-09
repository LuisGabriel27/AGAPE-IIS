<?php
/**
 * Google OAuth 2.0 Client Factory
 * Returns a configured Google_Client instance.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

function getGoogleClient(): Google_Client
{
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
