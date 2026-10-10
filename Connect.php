<?php

declare(strict_types=1);

/**
 * FOSSBilling registrar adapter for namingo/cardo-drs.
 *
 *
 * SPDX-License-Identifier: MIT
 */

$autoload = __DIR__ . '/../../../namingo/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

use Namingo\Cardo\DRS\RegistrarAPI;
use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;
use Namingo\Cardo\DRS\Adapters\GoDaddy;

class Registrar_Adapter_Connect extends Registrar_AdapterAbstract
{
    private const REGISTRAR_OPENSRS = 'opensrs';
    private const REGISTRAR_NAMECOM = 'namecom';

    private const PROVIDERS = [
        'opensrs' => 'OpenSRS', 'namecom' => 'Name.com', 'godaddy' => 'GoDaddy',
        'namesilo' => 'NameSilo', 'dynadot' => 'Dynadot', 'namecheap' => 'Namecheap',
        'opusdns' => 'OpusDNS',
    ];

    private const ENVIRONMENT_TEST = 'test';
    private const ENVIRONMENT_PRODUCTION = 'production';

    public array $config = [
        'registrar' => self::REGISTRAR_OPENSRS,
        'environment' => self::ENVIRONMENT_TEST,
        'username' => '',
        'api_key' => '',
        'password' => '',
        'api_secret' => '',
        'api_user' => '',
        'client_ip' => '',
        'endpoint' => '',
    ];

    private ?BaseAdapter $registrarClient = null;

    public function __construct($options)
    {
        if (!class_exists(RegistrarAPI::class)) {
            throw new Registrar_Exception(
                'The Cardo DRS Composer package is not installed. Run "composer require namingo/cardo-drs" from the namingo directory in the FOSSBilling installation.',
            );
        }

        $options = is_array($options) ? $options : [];

        $this->config['registrar'] = strtolower(trim((string) ($options['registrar'] ?? self::REGISTRAR_OPENSRS)));
        $this->config['environment'] = strtolower(trim((string) ($options['environment'] ?? self::ENVIRONMENT_TEST)));
        $this->config['username'] = trim((string) ($options['username'] ?? ''));
        $this->config['api_key'] = trim((string) ($options['api_key'] ?? ''));
        $this->config['password'] = (string) ($options['password'] ?? '');

        foreach (['api_secret', 'api_user', 'client_ip', 'endpoint'] as $key) {
            $this->config[$key] = trim((string) ($options[$key] ?? ''));
        }

        if (!isset(self::PROVIDERS[$this->config['registrar']])) {
            throw new Registrar_Exception('Connect has an invalid registrar selection.');
        }

        if (!in_array($this->config['environment'], [self::ENVIRONMENT_TEST, self::ENVIRONMENT_PRODUCTION], true)) {
            throw new Registrar_Exception('Connect has an invalid environment selection.');
        }

        if (in_array($this->config['registrar'], ['opensrs', 'namecom'], true)) {
            $this->requireSetting('username', 'Username');
        }
        $this->requireSetting('api_key', 'API key / API token');
        if ($this->config['registrar'] === 'opusdns'
            && !class_exists(\Namingo\Cardo\DRS\Adapters\OpusDNS::class)) {
            throw new Registrar_Exception('OpusDNS requires a Cardo DRS version containing the OpusDNS adapter. Update namingo/cardo-drs in the namingo directory.');
        }

        if ($this->config['registrar'] === self::REGISTRAR_OPENSRS) {
            $this->requireSetting('password', 'Password');
        }
        if ($this->config['registrar'] === 'godaddy') {
            $this->requireSetting('api_secret', 'GoDaddy API secret');
            $this->requireSetting('client_ip', 'GoDaddy registration consent IP');
        }
        if ($this->config['registrar'] === 'godaddy'
            && filter_var($this->config['client_ip'], FILTER_VALIDATE_IP) === false) {
            throw new Registrar_Exception('GoDaddy registration consent requires a valid IP address.');
        }
        if ($this->config['registrar'] === 'namecheap') {
            $this->config['api_user'] = $this->config['api_user'] ?: $this->config['username'];
            $this->requireSetting('api_user', 'Namecheap API user');
            $this->requireSetting('client_ip', 'Namecheap whitelisted server IPv4');
            if (filter_var($this->config['client_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new Registrar_Exception('Namecheap requires a valid whitelisted server IPv4 address.');
            }
        }
        if ($this->config['registrar'] === 'namesilo'
            && ($this->config['environment'] === self::ENVIRONMENT_TEST || $this->config['endpoint'] !== '')) {
            throw new Registrar_Exception('The installed Cardo NameSilo adapter uses a fixed production endpoint. Select Production; sandbox and endpoint overrides are not supported.');
        }
        if ($this->config['endpoint'] !== ''
            && (filter_var($this->config['endpoint'], FILTER_VALIDATE_URL) === false
                || parse_url($this->config['endpoint'], PHP_URL_SCHEME) !== 'https')) {
            throw new Registrar_Exception('Connect endpoint must be a valid HTTPS URL.');
        }

    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Connect domains through the Cardo DRS library. Supports OpenSRS, Name.com, GoDaddy, NameSilo, Dynadot, Namecheap and OpusDNS. NameSilo supports production only.',
            'form' => [
                'username' => ['text', [
                    'label' => 'Username',
                    'description' => 'Required for OpenSRS and Name.com; optional fallback for Namecheap API user.',
                    'required' => false,
                ]],
                'api_key' => ['password', [
                    'label' => 'API Key / API Token',
                    'description' => 'The API key or token used for registrar authentication.',
                ]],
                'password' => ['password', [
                    'label' => 'OpenSRS Password',
                    'description' => 'Required only for OpenSRS.',
                    'required' => false,
                ]],
                'api_secret' => ['password', [
                    'label' => 'GoDaddy API Secret', 'required' => false,
                    'description' => 'Required only for GoDaddy, together with API Key.',
                ]],
                'api_user' => ['text', [
                    'label' => 'Namecheap API User', 'required' => false,
                    'description' => 'Namecheap account with API access. Defaults to Username if empty.',
                ]],
                'client_ip' => ['text', [
                    'label' => 'Client IP', 'required' => false,
                    'description' => 'Namecheap: whitelisted outgoing server IPv4. GoDaddy: IP used for registration consent; configure only after obtaining customer agreement.',
                ]],
                'endpoint' => ['text', [
                    'label' => 'API Endpoint Override', 'required' => false,
                    'description' => 'Optional HTTPS base URL including API path. Empty uses the selected environment. Not supported by NameSilo.',
                ]],
                'registrar' => ['select', [
                    'label' => 'Registrar',
                    'description' => 'Choose the registrar adapter used for this connection.',
                    'multiOptions' => self::PROVIDERS,
                ]],
                'environment' => ['select', [
                    'label' => 'Environment',
                    'description' => 'Test uses the sandbox endpoint; production performs live operations. NameSilo requires Production. An endpoint override takes precedence.',
                    'multiOptions' => [
                        self::ENVIRONMENT_TEST => 'Test',
                        self::ENVIRONMENT_PRODUCTION => 'Production',
                    ],
                ]],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] === 'dynadot') {
            $result = $this->callApi('check availability for ' . $domain->getName(),
                fn (): array => $this->getRegistrarClient()->raw('search', ['domain0' => $domain->getName()]));
            foreach ($result['raw']['SearchResponse']['SearchResults'] ?? [] as $row) {
                if (strtolower((string) ($row['DomainName'] ?? '')) === strtolower($domain->getName())) {
                    return strtolower((string) ($row['Available'] ?? '')) === 'yes';
                }
            }
            throw new Registrar_Exception('Dynadot returned no availability result for the requested domain.');
        }

        $result = $this->callApi(
            'check availability for ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->checkAvailability([$domain->getName()]),
        );

        return in_array(strtolower($domain->getName()), $result['available'] ?? [], true);
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        /*
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());
        */

        return true;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'update nameservers for ' . $domain->getName(),
            fn (): array => $this->config['registrar'] === 'dynadot'
                ? $this->getRegistrarClient()->raw('set_ns', ['domain' => $domain->getName()] + $this->indexedNameservers($domain, 'ns', 0))
                : ($this->config['registrar'] === 'namesilo'
                    ? $this->getRegistrarClient()->raw('changeNameServers', ['domain' => $domain->getName()] + $this->indexedNameservers($domain, 'ns', 1))
                    : $this->getRegistrarClient()->setNameServers($domain->getName(), $this->getNameservers($domain))),
        );

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'transfer ' . $domain->getName(),
            fn (): array => $this->config['registrar'] === 'dynadot'
                ? $this->getRegistrarClient()->raw('transfer', ['domain' => $domain->getName(), 'auth' => (string) $domain->getEpp()])
                : $this->getRegistrarClient()->transferDomain($domain->getName(), [
                    'auth_code' => (string) $domain->getEpp(),
                    'years' => $this->getPeriod($domain),
                ] + ($this->config['registrar'] === 'opusdns' ? $this->registrationOptions($domain) : [])),
        );

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $details = $this->callApi(
            'get details for ' . $domain->getName(),
            fn () => $this->config['registrar'] === 'dynadot'
                ? $this->getRegistrarClient()->raw('domain_info', ['domain' => $domain->getName()])
                : $this->getRegistrarClient()->getDomain($domain->getName()),
        );

        $details = $this->normalizeDetails($details);
        $createdAt = $details['created_at'] ?? null;
        $expiresAt = $details['expires_at'] ?? null;
        $remoteNameservers = $details['nameservers'] ?? null;

        if ($createdAt !== null && ($timestamp = $this->dateTimestamp($createdAt)) !== false) {
            $domain->setRegistrationTime($timestamp);
        }

        if ($expiresAt !== null && ($timestamp = $this->dateTimestamp($expiresAt)) !== false) {
            $domain->setExpirationTime($timestamp);
        }

        if (is_array($remoteNameservers)) {
            $nameservers = array_values(array_filter(
                $remoteNameservers,
                static fn ($nameserver): bool => is_string($nameserver) && trim($nameserver) !== '',
            ));

            foreach (array_slice($nameservers, 0, 4) as $index => $nameserver) {
                $setter = 'setNs' . ($index + 1);
                $domain->{$setter}($nameserver);
            }
        }

        return $domain;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('This operation is not implemented by Connect for the selected Cardo provider.');
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $options = $this->registrationOptions($domain);
        if ($this->config['registrar'] === 'namesilo') {
            $options = $this->namesiloRegistrationParams($domain, $options);
        }
        if ($this->config['registrar'] === 'dynadot') {
            $options = ['domain' => $domain->getName(), 'duration' => $this->getPeriod($domain)]
                + $this->createDynadotContacts($domain);
        }

        $result = $this->callApi(
            'register ' . $domain->getName(),
            fn () => $this->config['registrar'] === 'dynadot'
                ? $this->getRegistrarClient()->raw('register', $options)
                : ($this->config['registrar'] === 'namesilo'
                    ? $this->getRegistrarClient()->raw('registerDomain', $options)
                    : $this->getRegistrarClient()->registerDomain($domain->getName(), $options)),
        );

        // NameSilo 301 means the purchase completed with default nameservers.
        // Other Cardo registration methods omit nameservers entirely. The domain
        // has already been purchased: never report a retryable registration
        // failure for a subsequent nameserver update.
        $namesiloFallback = $this->config['registrar'] === 'namesilo'
            && (int) ($result['raw']['reply']['code'] ?? 0) === 301;
        if ($namesiloFallback || (in_array($this->config['registrar'], ['dynadot', 'namecheap'], true)
            && $this->getNameservers($domain) !== [])) {
            try {
                if ($this->getNameservers($domain) === []) {
                    throw new Registrar_Exception('No requested nameservers are configured.');
                }
                $this->modifyNs($domain);
            } catch (Registrar_Exception $exception) {
                $this->getLog()->error('Connect registered ' . $domain->getName()
                    . ' but nameservers require a manual update: ' . $exception->getMessage());
            }
        }

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'renew ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->renewDomain($domain->getName(), $this->getPeriod($domain)),
        );

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('This operation is not implemented by Connect for the selected Cardo provider.');
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            throw new Registrar_Exception('Privacy management is not exposed by Connect for this Cardo provider.');
        }

        $this->callApi(
            'enable privacy protection for ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->raw('domains/' . rawurlencode($domain->getName()) . ':enableWhoisPrivacy', ['_method' => 'POST']),
        );

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            throw new Registrar_Exception('Privacy management is not exposed by Connect for this Cardo provider.');
        }

        $this->callApi(
            'disable privacy protection for ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->raw('domains/' . rawurlencode($domain->getName()) . ':disableWhoisPrivacy', ['_method' => 'POST']),
        );

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        if (!method_exists($this->getRegistrarClient(), 'getAuthCode')) {
            throw new Registrar_Exception('Transfer code retrieval is not exposed by this Cardo provider. Retrieve it through the provider account.');
        }

        $result = $this->callApi(
            'retrieve the transfer code for ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->getAuthCode($domain->getName()),
        );
        if (empty($result['auth_code'])) {
            throw new Registrar_Exception('Connect received an empty transfer code.');
        }

        return (string) $result['auth_code'];
    }

    public function lock(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            throw new Registrar_Exception('Registrar locking is not exposed by Connect for this Cardo provider.');
        }

        $this->callApi(
            'lock ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->raw('domains/' . rawurlencode($domain->getName()) . ':lock', ['_method' => 'POST']),
        );

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            throw new Registrar_Exception('Registrar locking is not exposed by Connect for this Cardo provider.');
        }

        $this->callApi(
            'unlock ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->raw('domains/' . rawurlencode($domain->getName()) . ':unlock', ['_method' => 'POST']),
        );

        return true;
    }

    private function getRegistrarClient(): BaseAdapter
    {
        if ($this->registrarClient instanceof BaseAdapter) {
            return $this->registrarClient;
        }

        $production = $this->config['environment'] === self::ENVIRONMENT_PRODUCTION;
        $endpoint = $this->config['endpoint'] ?: match ($this->config['registrar']) {
            'namecom' => $production ? 'https://api.name.com' : 'https://api.dev.name.com',
            'opensrs' => $production ? 'https://rr-n1-tor.opensrs.net:55443' : 'https://horizon.opensrs.net:55443',
            'godaddy' => $production ? 'https://api.godaddy.com/v1' : 'https://api.ote-godaddy.com/v1',
            'dynadot' => $production ? 'https://api.dynadot.com/api3.json' : 'https://api-sandbox.dynadot.com/api3.json',
            'namecheap' => $production ? 'https://api.namecheap.com/xml.response' : 'https://api.sandbox.namecheap.com/xml.response',
            'namesilo' => 'https://www.namesilo.com/api',
            'opusdns' => $production ? 'https://api.opusdns.com' : 'https://sandbox.opusdns.com',
        };

        $credentials = [
            'username' => $this->config['username'],
            'api_user' => $this->config['api_user'],
            'api_key' => $this->config['api_key'],
            'api_secret' => $this->config['api_secret'],
            'password' => $this->config['password'],
            'client_ip' => $this->config['client_ip'],
            'base' => $endpoint,
        ];
        $this->registrarClient = $this->config['registrar'] === 'godaddy'
            ? new Registrar_Connect_GoDaddy($credentials)
            : RegistrarAPI::make($this->config['registrar'], $credentials);

        return $this->registrarClient;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getContacts(Registrar_Domain $domain): array
    {
        $registrant = $domain->getContactRegistrar();

        if (!$registrant instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Connect requires a registrant contact for :domain.', [
                ':domain' => $domain->getName(),
            ]);
        }

        $contacts = [
            'registrant' => $registrant,
            'admin' => $domain->getContactAdmin() ?? $registrant,
            'tech' => $domain->getContactTech() ?? $registrant,
            'billing' => $domain->getContactBilling() ?? $registrant,
        ];

        return array_map(fn (Registrar_Domain_Contact $contact): array => [
            'first_name' => (string) $contact->getFirstName(),
            'last_name' => (string) $contact->getLastName(),
            'phone' => $this->formatProviderPhone($contact),
            'email' => (string) $contact->getEmail(),
            'address1' => (string) $contact->getAddress1(),
            'street' => $this->config['registrar'] === 'opusdns'
                ? implode(', ', array_filter([(string) $contact->getAddress1(), (string) $contact->getAddress2(), (string) $contact->getAddress3()],
                    static fn (string $line): bool => trim($line) !== ''))
                : (string) $contact->getAddress1(),
            'address2' => trim((string) $contact->getAddress2() . ' ' . (string) $contact->getAddress3()),
            'city' => (string) $contact->getCity(),
            'state' => (string) $contact->getState(),
            'country' => strtoupper((string) $contact->getCountry()),
            'postal_code' => (string) $contact->getZip(),
            'organization' => (string) $contact->getCompany(),
        ], $contacts);
    }

    /**
     * @return string[]
     */
    private function getNameservers(Registrar_Domain $domain): array
    {
        $nameservers = [
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ];

        $nameservers = array_map(
            static fn ($nameserver): string => strtolower(rtrim(trim((string) $nameserver), '.')),
            $nameservers,
        );

        return array_values(array_unique(array_filter(
            $nameservers,
            static fn (string $nameserver): bool => $nameserver !== '',
        )));
    }

    private function indexedNameservers(Registrar_Domain $domain, string $prefix, int $start): array
    {
        $params = [];
        foreach ($this->getNameservers($domain) as $index => $server) {
            $params[$prefix . ($index + $start)] = $server;
        }
        return $params;
    }

    private function registrationOptions(Registrar_Domain $domain): array
    {
        $contacts = $this->getContacts($domain);
        if ($this->config['registrar'] === 'godaddy') {
            $contacts = array_map(static fn (array $contact): array => [
                'nameFirst' => $contact['first_name'], 'nameLast' => $contact['last_name'],
                'email' => $contact['email'], 'phone' => $contact['phone'],
                'organization' => $contact['organization'],
                'addressMailing' => [
                    'address1' => $contact['address1'], 'address2' => $contact['address2'],
                    'city' => $contact['city'], 'state' => $contact['state'],
                    'postalCode' => $contact['postal_code'], 'country' => $contact['country'],
                ],
            ], $contacts);
        }
        $registrant = $contacts['registrant'];
        if ($this->config['registrar'] === 'namesilo') {
            $registrant['address'] = $registrant['address1'];
            $registrant['zip'] = $registrant['postal_code'];
        }

        return [
            'contacts' => $contacts, 'registrant' => $registrant,
            'years' => $this->getPeriod($domain), 'nameservers' => $this->getNameservers($domain),
            'client_ip' => $this->config['client_ip'],
            // Billing performs renewals; avoid provider-side duplicate billing.
            'auto_renew' => false,
        ];
    }

    private function createDynadotContacts(Registrar_Domain $domain): array
    {
        $ids = [];
        $params = [];
        foreach ($this->getContacts($domain) as $role => $contact) {
            $fingerprint = json_encode($contact);
            if (!isset($ids[$fingerprint])) {
                $source = match ($role) {
                    'registrant' => $domain->getContactRegistrar(),
                    'admin' => $domain->getContactAdmin() ?? $domain->getContactRegistrar(),
                    'tech' => $domain->getContactTech() ?? $domain->getContactRegistrar(),
                    'billing' => $domain->getContactBilling() ?? $domain->getContactRegistrar(),
                };
                $countryCode = preg_replace('/\D+/', '', (string) $source->getTelCc());
                $subscriber = preg_replace('/\D+/', '', (string) $source->getTel());
                if (str_starts_with(trim((string) $source->getTel()), '+') && $countryCode !== ''
                    && str_starts_with($subscriber, $countryCode)) {
                    $subscriber = substr($subscriber, strlen($countryCode));
                }
                $result = $this->callApi('create Dynadot contact', fn (): array => $this->getRegistrarClient()->raw('create_contact', [
                    'name' => trim($contact['first_name'] . ' ' . $contact['last_name']),
                    'organization' => $contact['organization'], 'email' => $contact['email'],
                    'phonecc' => $countryCode,
                    'phonenum' => $subscriber,
                    'address1' => $contact['address1'], 'address2' => $contact['address2'],
                    'city' => $contact['city'], 'state' => $contact['state'],
                    'zip' => $contact['postal_code'], 'country' => $contact['country'],
                ]));
                $id = $result['raw']['CreateContactResponse']['CreateContactContent']['ContactId'] ?? null;
                if ($id === null || (string) $id === '') {
                    throw new Registrar_Exception('Dynadot did not return a contact ID. Registration has not been submitted.');
                }
                $ids[$fingerprint] = (string) $id;
            }
            $params[($role === 'registrant' ? 'registrant' : ($role === 'tech' ? 'technical' : $role)) . '_contact'] = $ids[$fingerprint];
        }
        return $params;
    }

    private function namesiloRegistrationParams(Registrar_Domain $domain, array $options): array
    {
        $params = ['domain' => $domain->getName(), 'years' => $options['years'], 'auto_renew' => '0', 'private' => '1'];
        $contact = $options['registrant'];
        foreach (['fn' => 'first_name', 'ln' => 'last_name', 'ad' => 'address1', 'cy' => 'city',
            'st' => 'state', 'zp' => 'postal_code', 'ct' => 'country', 'em' => 'email', 'ph' => 'phone'] as $field => $key) {
            if (trim($contact[$key]) === '') {
                throw new Registrar_Exception('NameSilo requires all registrant fields; missing ' . $key . '. Registration has not been submitted.');
            }
            $params[$field] = $contact[$key];
        }
        $params['cp'] = $contact['organization'];
        $params['ad2'] = $contact['address2'];
        return $params + $this->indexedNameservers($domain, 'ns', 1);
    }

    private function normalizeDetails(array $result): array
    {
        $raw = $result['raw'] ?? [];
        $data = match ($this->config['registrar']) {
            'godaddy' => ['created_at' => $raw['createdAt'] ?? null, 'expires_at' => $raw['expires'] ?? null, 'nameservers' => $raw['nameServers'] ?? []],
            'namesilo' => ['created_at' => $raw['reply']['created'] ?? null, 'expires_at' => $raw['reply']['expires'] ?? null,
                'nameservers' => $raw['reply']['nameservers']['nameserver'] ?? $raw['reply']['nameservers'] ?? []],
            'dynadot' => ['created_at' => $raw['DomainInfoResponse']['DomainInfo']['Registration'] ?? null,
                'expires_at' => $raw['DomainInfoResponse']['DomainInfo']['Expiration'] ?? null,
                'nameservers' => $raw['DomainInfoResponse']['DomainInfo']['NameServerSettings']['NameServers'] ?? []],
            'namecheap' => ['created_at' => $raw['CommandResponse']['DomainGetInfoResult']['DomainDetails']['CreatedDate'] ?? null,
                'expires_at' => $raw['CommandResponse']['DomainGetInfoResult']['DomainDetails']['ExpiredDate'] ?? null,
                'nameservers' => $raw['CommandResponse']['DomainGetInfoResult']['DnsDetails']['Nameserver'] ?? []],
            default => [],
        };
        $result += $data;
        if (isset($result['nameservers']) && is_string($result['nameservers'])) {
            $result['nameservers'] = [$result['nameservers']];
        }
        return $result;
    }

    private function dateTimestamp(mixed $date): int|false
    {
        if (is_numeric($date)) {
            $number = (float) $date;
            return (int) ($number > 100000000000 ? $number / 1000 : $number);
        }
        return strtotime((string) $date);
    }

    private function assertProviderSuccess(array $result): void
    {
        if (isset($result['http']) && ((int) $result['http'] <= 0 || (int) $result['http'] >= 400)) {
            throw new RuntimeException((string) (($result['err'] ?? '') ?: 'Registrar HTTP request failed'));
        }
        $raw = $result['raw'] ?? [];
        if ($this->config['registrar'] === 'namesilo') {
            $reply = $raw['reply'] ?? [];
            if ((int) ($reply['code'] ?? 0) === 302) {
                throw new Registrar_Exception('NameSilo registered the domain using the account default contact instead of the supplied registrant. Do not retry registration; correct the contact in NameSilo and reconcile this order.');
            }
            if (!isset($reply['code']) || !in_array((int) $reply['code'], [300, 301], true)) {
                throw new RuntimeException((string) ($reply['detail'] ?? 'Invalid NameSilo response'));
            }
        }
        if ($this->config['registrar'] === 'dynadot') {
            $response = is_array($raw) ? reset($raw) : false;
            if (!is_array($response) || !isset($response['ResponseCode'])
                || (int) $response['ResponseCode'] !== 0
                || (isset($response['Status']) && strtolower((string) $response['Status']) === 'error')) {
                throw new RuntimeException(is_array($response) ? (string) ($response['Error'] ?? 'Dynadot command failed') : 'Invalid Dynadot response');
            }
        }
    }

    private function getPeriod(Registrar_Domain $domain): int
    {
        return max(1, (int) ($domain->getRegistrationPeriod() ?? 1));
    }

    private function formatProviderPhone(Registrar_Domain_Contact $contact): string
    {
        $phone = $this->formatPhone($contact);
        $code = preg_replace('/\D+/', '', (string) $contact->getTelCc());
        if ($this->config['registrar'] === 'namesilo') {
            $number = trim((string) $contact->getTel());
            if (str_starts_with($number, '+')) {
                if ($code === '' || !str_starts_with($phone, '+' . $code)) {
                    throw new Registrar_Exception('NameSilo requires the telephone country code separately to format an international phone number.');
                }
                return substr($phone, strlen($code) + 1);
            }
            return (string) preg_replace('/\D+/', '', $number);
        }
        if (in_array($this->config['registrar'], ['godaddy', 'namecheap'], true)
            && $code !== '' && str_starts_with($phone, '+' . $code)) {
            return '+' . $code . '.' . substr($phone, strlen($code) + 1);
        }
        return $phone;
    }

    private function formatPhone(Registrar_Domain_Contact $contact): string
    {
        $number = trim((string) $contact->getTel());

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '+')) {
            return '+' . preg_replace('/\D+/', '', substr($number, 1));
        }

        $countryCode = preg_replace('/\D+/', '', (string) $contact->getTelCc());
        $subscriber = preg_replace('/\D+/', '', $number);

        return $countryCode !== '' ? '+' . $countryCode . $subscriber : $subscriber;
    }

    private function requireSetting(string $key, string $label): void
    {
        if (trim((string) $this->config[$key]) === '') {
            throw new Registrar_Exception('The Connect registrar is not fully configured. Please configure :missing.', [
                ':missing' => $label,
            ], 3001);
        }
    }

    private function callApi(string $operation, callable $callback)
    {
        try {
            $result = $callback();
            if (is_array($result)) {
                $this->assertProviderSuccess($result);
            }
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new RuntimeException(is_array($result)
                    ? (string) (($result['err'] ?? '') ?: ($result['text'] ?? 'Registrar API request failed'))
                    : 'Invalid registrar API response');
            }

            return $result;
        } catch (Registrar_Exception $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $registrar = self::PROVIDERS[$this->config['registrar']];
            $message = sprintf('Connect (%s) failed to %s: %s', $registrar, $operation, $exception->getMessage());
            $this->getLog()->error($message);

            $code = $exception->getCode();
            throw new Registrar_Exception($message, null, is_int($code) ? $code : 0);
        }
    }
}

/**
 * Keep the Cardo interface while correcting its current GoDaddy purchase and
 * nameserver endpoints. Remove these overrides when Cardo provides them.
 */
class Registrar_Connect_GoDaddy extends GoDaddy
{
    public function registerDomain(string $domain, array $opts): array
    {
        $contacts = $opts['contacts'];
        // Domain input is registrable (e.g. example.co.uk); remove only its
        // first label so multi-label TLDs remain intact.
        $tld = explode('.', $domain, 2)[1] ?? '';
        if ($tld === '') {
            throw new RuntimeException('GoDaddy registration requires a domain with a TLD.');
        }
        $privacy = (bool) ($opts['privacy'] ?? false);
        $agreements = $this->send('GET', '/domains/agreements?' . http_build_query([
            'tlds' => $tld, 'privacy' => $privacy ? 'true' : 'false', 'forTransfer' => 'false',
        ]), []);
        if (!$agreements['ok']) {
            return $agreements;
        }
        $keys = [];
        foreach ($agreements['raw'] as $agreement) {
            if (!is_array($agreement) || !is_string($agreement['agreementKey'] ?? null)
                || trim($agreement['agreementKey']) === '') {
                throw new RuntimeException('GoDaddy returned invalid agreement data. Purchase has not been submitted.');
            }
            $keys[] = $agreement['agreementKey'];
        }
        if ($keys === []) {
            throw new RuntimeException('GoDaddy returned no required agreements. Purchase has not been submitted.');
        }
        return $this->send('POST', '/domains/purchase', [
            'domain' => $domain, 'period' => $opts['years'] ?? 1,
            'consent' => ['agreedAt' => gmdate('c'), 'agreedBy' => $opts['client_ip'], 'agreementKeys' => array_values(array_unique($keys))],
            'contactRegistrant' => $contacts['registrant'], 'contactAdmin' => $contacts['admin'],
            'contactTech' => $contacts['tech'], 'contactBilling' => $contacts['billing'],
            'nameServers' => $opts['nameservers'] ?? [],
            'privacy' => (bool) ($opts['privacy'] ?? false), 'renewAuto' => false,
        ]);
    }

    public function setNameServers(string $domain, array $nameservers): array
    {
        return $this->send('PATCH', '/domains/' . rawurlencode($domain), ['nameServers' => array_values($nameservers)]);
    }

    private function send(string $method, string $path, array $payload): array
    {
        $options = [
            'headers' => ['Authorization' => 'sso-key ' . $this->creds['api_key'] . ':' . $this->creds['api_secret'], 'Accept' => 'application/json'],
        ];
        if ($method !== 'GET') {
            $options['json'] = $payload;
        }
        [$code, $body, $error] = Http::request($method, rtrim($this->creds['base'], '/') . $path, $options);
        $raw = $body === '' ? [] : json_decode($body, true);
        return ['ok' => $code >= 200 && $code < 300 && $error === '', 'http' => $code,
            'raw' => is_array($raw) ? $raw : [], 'err' => $error ?: (string) ($raw['message'] ?? '')];
    }
}
