<?php

namespace App\Services;

/**
 * Creates configured Gmail API clients.
 */
class GmailClientFactory
{
    public function make(string $clientId, string $clientSecret, string $refreshToken, ?GmailLogger $logger = null): GmailClient
    {
        $client = new GmailClient($clientId, $clientSecret, $refreshToken);

        if ($logger) {
            $client->setLogger($logger);
        }

        return $client;
    }
}
