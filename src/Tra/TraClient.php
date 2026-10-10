<?php

namespace OnlineEfd\Tra;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Support\Certificate;
use SimpleXMLElement;

/** Thin HTTP layer over the TRA VFD API. No business logic lives here. */
class TraClient
{
    public function __construct(private readonly Http $http)
    {
    }

    public function register(string $signedXml, Certificate $certificate): SimpleXMLElement
    {
        $response = $this->send(fn () => $this->request($certificate)
            ->withBody($signedXml, 'application/xml')
            ->post($this->url('registration')));

        return $this->parseXml($response);
    }

    /** @return array{access_token: string, token_type: string, expires_in: int} */
    public function token(string $username, string $password): array
    {
        $response = $this->send(fn () => $this->base()
            ->asForm()
            ->post($this->url('token'), [
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password',
            ]));

        $json = json_decode($response, true);

        if (! is_array($json) || empty($json['access_token'])) {
            $message = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? null) : null;

            throw $message
                ? EfdException::rejected('token', $message)
                : EfdException::invalidResponse($response);
        }

        return [
            'access_token' => $json['access_token'],
            'token_type' => $json['token_type'] ?? 'bearer',
            'expires_in' => (int) ($json['expires_in'] ?? 3600),
        ];
    }

    /** @param  'receipt'|'zreport'  $kind */
    public function submit(string $kind, string $routingKey, string $signedXml, Certificate $certificate, string $token): SimpleXMLElement
    {
        $response = $this->send(fn () => $this->request($certificate)
            ->withHeaders([
                'Routing-Key' => $routingKey,
                'Authorization' => 'Bearer '.$token,
            ])
            ->withBody($signedXml, 'application/xml')
            ->post($this->url($kind)));

        return $this->parseXml($response);
    }

    private function base(): PendingRequest
    {
        return $this->http
            ->timeout((int) config('efd.api.timeout'))
            ->withOptions(['verify' => (bool) config('efd.api.verify_ssl')]);
    }

    private function request(Certificate $certificate): PendingRequest
    {
        return $this->base()->withHeaders([
            'Cert-Serial' => $certificate->serialHeader(),
            'Client' => config('efd.api.client'),
        ]);
    }

    private function url(string $endpoint): string
    {
        return rtrim(config('efd.api.base_url'), '/').config("efd.api.endpoints.{$endpoint}");
    }

    private function send(callable $call): string
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw EfdException::transport($e->getMessage());
        }

        if ($response->serverError()) {
            throw EfdException::transport("HTTP {$response->status()}");
        }

        return $response->body();
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw EfdException::invalidResponse($body);
        }

        return $xml;
    }
}
