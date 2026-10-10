<?php

namespace OnlineEfd\Services;

use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Support\Xml;
use OnlineEfd\Tra\TraClient;

class RegistrationService
{
    public function __construct(
        private readonly TraClient $client,
        private readonly TokenService $tokens,
    ) {
    }

    /** Registers the taxpayer with TRA and stores everything TRA returns (credentials, counters, tax rates). */
    public function register(EfdProfile $profile): EfdProfile
    {
        if (blank($profile->tin) || blank($profile->cert_key)) {
            throw EfdException::notConfigured('TIN and certificate key are required');
        }

        $certificate = $profile->certificate();

        $payload = '<REGDATA>'.Xml::tag('TIN', $profile->tin).Xml::tag('CERTKEY', $profile->cert_key).'</REGDATA>';
        $response = $this->client->register(Xml::envelope($payload, $certificate), $certificate);

        $ack = Xml::ack($response);
        if ($ack['code'] !== '0') {
            throw EfdException::rejected($ack['code'], $ack['message']);
        }

        $r = $response->EFDMSRESP;
        $info = json_decode(json_encode($r), true) ?: [];

        $rates = [];
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            if (isset($info['TAXCODES']["CODE{$letter}"])) {
                $rates[$letter] = (float) $info['TAXCODES']["CODE{$letter}"];
            }
        }

        unset($info['USERNAME'], $info['PASSWORD']); // credentials live in their own encrypted columns

        $profile->forceFill([
            'username' => (string) $r->USERNAME,
            'password' => (string) $r->PASSWORD,
            'regid' => (string) $r->REGID,
            'efd_serial' => (string) $r->SERIAL,
            'uin' => (string) $r->UIN,
            'vrn' => (string) $r->VRN,
            'name' => trim((string) $r->NAME),
            'mobile' => (string) $r->MOBILE,
            'address' => (string) $r->ADDRESS,
            'street' => (string) $r->STREET,
            'city' => (string) $r->CITY,
            'country' => (string) $r->COUNTRY,
            'region' => (string) $r->REGION,
            'tax_office' => (string) $r->TAXOFFICE,
            'receipt_code' => (string) $r->RECEIPTCODE,
            'routing_key' => (string) $r->ROUTINGKEY,
            'token_path' => (string) $r->TOKENPATH,
            'tax_rates' => $rates ?: null,
            'registration_info' => $info,
            'registered_at' => now(),
            'gc' => max((int) $profile->gc, (int) $r->GC), // never move the counter backwards
            'access_token' => null,
            'token_expires_at' => null,
        ])->save();

        return $profile;
    }

    /** Proves the stored username/password work by fetching a fresh token. */
    public function testConnection(EfdProfile $profile): void
    {
        if (! $profile->hasCredentials()) {
            throw EfdException::notConfigured('no TRA username/password');
        }

        $this->tokens->get($profile, forceRefresh: true);
    }
}
