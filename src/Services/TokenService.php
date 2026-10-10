<?php

namespace OnlineEfd\Services;

use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Tra\TraClient;

class TokenService
{
    public function __construct(private readonly TraClient $client)
    {
    }

    /** Returns a valid bearer token, requesting a new one only when the cached one is near expiry. */
    public function get(EfdProfile $profile, bool $forceRefresh = false): string
    {
        if (! $forceRefresh
            && filled($profile->access_token)
            && $profile->token_expires_at?->gt(now()->addSeconds(30))
        ) {
            return $profile->access_token;
        }

        $token = $this->client->token($profile->username, $profile->password);

        $profile->forceFill([
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds($token['expires_in']),
        ])->save();

        return $token['access_token'];
    }
}
