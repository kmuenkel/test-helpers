<?php

namespace TestHelper\Tools;

use Firebase\JWT\JWT;
use TestHelper\PassportModels;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\File;

trait TokenHelpers
{
    /**
     * @param array $payload
     * @param array $scopes
     * @param string $oauthRoute
     * @return string
     */
    protected function generateOauthToken(array $payload, array $scopes, string $oauthRoute = 'passport.token'): string
    {
        $assertion = JWT::encode($payload, $this->oauthKeys()['private'], 'RS256');

        $credentials = [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'scope' => implode(',', $scopes),
            'client_assertion' => $assertion
        ];

        PassportModels\DummyClient::$staticAttributes = [
            'id' => 1,
            'name' => $payload['iss']
        ];
        Passport::$clientModel = PassportModels\DummyClient::class;
        PassportModels\DummyToken::$dummyClient = app(PassportModels\DummyClient::class);
        Passport::$tokenModel = PassportModels\DummyToken::class;

        $response = $this->post(route($oauthRoute), $credentials);

        return $response->json('token_type').' '.$response->json('access_token');
    }

    /**
     * @param bool $fresh
     * @return array
     */
    protected function oauthKeys(bool $fresh = false): array
    {
        if ($this->oauthKeys && !$fresh) {
            return $this->oauthKeys;
        }

        Passport::$keyPath = __DIR__ . '/Resources/credentials';
        $this->artisan('passport:keys'/*, ['--force' => true]*/);
        $privateKey = File::get(Passport::$keyPath.'/oauth-private.key');
        $publicKey = File::get(Passport::$keyPath.'/oauth-public.key');

        return $this->oauthKeys = [
            'private' => $privateKey,
            'public' => $publicKey
        ];
    }
}
