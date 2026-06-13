<?php

namespace App\Http\Integrations\Portal;

use App\Services\PortalAuth;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon-Connector für die EINUNDZWANZIG-Portal-API. Authentifiziert
 * Requests per Bearer-Token aus dem Geräte-Keystore, sobald die App
 * mit dem Portal verbunden ist; öffentliche Endpunkte funktionieren
 * auch ohne Token.
 */
class PortalConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    public ?int $tries = 2;

    public ?int $retryInterval = 500;

    public ?bool $useExponentialBackoff = true;

    public ?bool $throwOnMaxTries = false;

    protected int $connectTimeout = 10;

    protected int $requestTimeout = 15;

    public function __construct(private readonly PortalAuth $portalAuth) {}

    public function resolveBaseUrl(): string
    {
        return $this->portalAuth->baseUrl().'/api';
    }

    protected function defaultAuth(): ?Authenticator
    {
        $token = $this->portalAuth->token();

        return $token !== null ? new TokenAuthenticator($token) : null;
    }
}
