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

class Registrar_Adapter_Connect extends Registrar_AdapterAbstract
{
    private const REGISTRAR_OPENSRS = 'opensrs';
    private const REGISTRAR_NAMECOM = 'namecom';

    private const ENVIRONMENT_TEST = 'test';
    private const ENVIRONMENT_PRODUCTION = 'production';

    public array $config = [
        'registrar' => self::REGISTRAR_OPENSRS,
        'environment' => self::ENVIRONMENT_TEST,
        'username' => '',
        'api_key' => '',
        'password' => '',
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

        if (!in_array($this->config['registrar'], [self::REGISTRAR_OPENSRS, self::REGISTRAR_NAMECOM], true)) {
            throw new Registrar_Exception('Connect has an invalid registrar selection.');
        }

        if (!in_array($this->config['environment'], [self::ENVIRONMENT_TEST, self::ENVIRONMENT_PRODUCTION], true)) {
            throw new Registrar_Exception('Connect has an invalid environment selection.');
        }

        $this->requireSetting('username', 'Username');
        $this->requireSetting('api_key', 'API key / API token');

        if ($this->config['registrar'] === self::REGISTRAR_OPENSRS) {
            $this->requireSetting('password', 'Password');
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Connect domains through the Cardo DRS library. Supports OpenSRS and Name.com, with separate test and production endpoints.',
            'form' => [
                'username' => ['text', [
                    'label' => 'Username',
                    'description' => 'The username used for registrar authentication, such as a reseller or API username.',
                ]],
                'api_key' => ['password', [
                    'label' => 'API Key / API Token',
                    'description' => 'The API key or token used for registrar authentication.',
                ]],
                'password' => ['password', [
                    'label' => 'Password / Secret Key',
                    'description' => 'The password or secret key, if required by the registrar.',
                    'required' => false,
                ]],
                'registrar' => ['select', [
                    'label' => 'Registrar',
                    'description' => 'Choose the registrar adapter used for this connection.',
                    'multiOptions' => [
                        self::REGISTRAR_OPENSRS => 'OpenSRS',
                        self::REGISTRAR_NAMECOM => 'Name.com',
                    ],
                ]],
                'environment' => ['select', [
                    'label' => 'Environment',
                    'description' => 'Test uses the registrar sandbox endpoint; production performs live operations.',
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
            fn (): array => $this->getRegistrarClient()->setNameServers($domain->getName(), $this->getNameservers($domain)),
        );

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'transfer ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->transferDomain($domain->getName(), [
                'auth_code' => (string) $domain->getEpp(),
                'years' => $this->getPeriod($domain),
            ]),
        );

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $details = $this->callApi(
            'get details for ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->getDomain($domain->getName()),
        );

        $createdAt = $details['created_at'] ?? null;
        $expiresAt = $details['expires_at'] ?? null;
        $remoteNameservers = $details['nameservers'] ?? null;

        if ($createdAt !== null && ($timestamp = strtotime((string) $createdAt)) !== false) {
            $domain->setRegistrationTime($timestamp);
        }

        if ($expiresAt !== null && ($timestamp = strtotime((string) $expiresAt)) !== false) {
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
        /*
        $this->getLog()->debug('Removing domain: ' . $domain->getName());
        */

        return true;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'register ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->registerDomain($domain->getName(), [
                'contacts' => $this->getContacts($domain),
                'years' => $this->getPeriod($domain),
                'nameservers' => $this->getNameservers($domain),
            ]),
        );

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
        /*
        $this->getLog()->debug('Updating contact info: ' . $domain->getName());
        */

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            /*
            OpenSRS privacy protection is not currently implemented by the
            Cardo DRS adapter.
            */
            return true;
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
            /*
            OpenSRS privacy protection is not currently implemented by the
            Cardo DRS adapter.
            */
            return true;
        }

        $this->callApi(
            'disable privacy protection for ' . $domain->getName(),
            fn (): array => $this->getRegistrarClient()->raw('domains/' . rawurlencode($domain->getName()) . ':disableWhoisPrivacy', ['_method' => 'POST']),
        );

        return true;
    }

    public function getEpp(Registrar_Domain $domain): string
    {
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
            /*
            OpenSRS registrar locking is not currently implemented by the
            Cardo DRS adapter.
            */
            return true;
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
            /*
            OpenSRS registrar unlocking is not currently implemented by the
            Cardo DRS adapter.
            */
            return true;
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

        $endpoint = $this->config['registrar'] === self::REGISTRAR_NAMECOM
            ? ($this->config['environment'] === self::ENVIRONMENT_PRODUCTION ? 'https://api.name.com' : 'https://api.dev.name.com')
            : ($this->config['environment'] === self::ENVIRONMENT_PRODUCTION ? 'https://rr-n1-tor.opensrs.net:55443' : 'https://horizon.opensrs.net:55443');

        $this->registrarClient = RegistrarAPI::make($this->config['registrar'], [
            'username' => $this->config['username'],
            'api_key' => $this->config['api_key'],
            'password' => $this->config['password'],
            'endpoint' => $endpoint,
        ]);

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
            'phone' => $this->formatPhone($contact),
            'email' => (string) $contact->getEmail(),
            'address1' => (string) $contact->getAddress1(),
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

    private function getPeriod(Registrar_Domain $domain): int
    {
        return max(1, (int) ($domain->getRegistrationPeriod() ?? 1));
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
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                throw new RuntimeException(is_array($result)
                    ? (string) (($result['err'] ?? '') ?: ($result['text'] ?? 'Registrar API request failed'))
                    : 'Invalid registrar API response');
            }

            return $result;
        } catch (Registrar_Exception $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $registrar = $this->config['registrar'] === self::REGISTRAR_NAMECOM ? 'Name.com' : 'OpenSRS';
            $message = sprintf('Connect (%s) failed to %s: %s', $registrar, $operation, $exception->getMessage());
            $this->getLog()->error($message);

            $code = $exception->getCode();
            throw new Registrar_Exception($message, null, is_int($code) ? $code : 0);
        }
    }
}