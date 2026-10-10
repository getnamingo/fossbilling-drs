<?php

declare(strict_types=1);

namespace Namingo\Cardo\DRS\Core {
    // Replace only transport; exercise the installed Cardo adapters unchanged.
    final class Http {
        public static array $requests = [];
        public static array $response = [200, '{}', ''];
        public static function request(string $method, string $url, array $options = [], int $timeout = 30): array {
            self::$requests[] = [$method, $url, $options];
            return self::$response;
        }
    }
}

namespace {
    require __DIR__ . '/../namingo/vendor/autoload.php';

    class Registrar_Exception extends RuntimeException {
        public function __construct(string $message, ?array $params = null, int $code = 0) { parent::__construct($message, $code); }
    }
    class Registrar_AdapterAbstract {
        public function getLog(): object { return new class { public function error($message): void {} }; }
    }
    class Registrar_Domain_Contact {
        public function __call(string $method, array $args): string {
            return ['getFirstName'=>'Ada', 'getLastName'=>'Lovelace', 'getEmail'=>'ada@example.test',
                'getAddress1'=>'1 Test Street', 'getCity'=>'London', 'getCountry'=>'gb', 'getZip'=>'TEST',
                'getTel'=>'12345', 'getTelCc'=>'44', 'getCompany'=>'Test Ltd'][$method] ?? '';
        }
    }
    class Registrar_Domain {
        public array $values = [];
        public function __call(string $method, array $args): mixed {
            if (str_starts_with($method, 'set')) { $this->values[$method] = $args[0]; return $this; }
            return match ($method) {
                'getName' => 'example.test', 'getRegistrationPeriod' => 2, 'getEpp' => 'secret',
                'getNs1' => 'NS1.EXAMPLE.TEST.', 'getNs2' => 'ns2.example.test',
                'getContactRegistrar' => new Registrar_Domain_Contact(),
                default => null,
            };
        }
    }
    require __DIR__ . '/../Connect.php';

    use Namingo\Cardo\DRS\Core\Http;

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new RuntimeException($message); }
    }
    function response(array $data, int $code = 200): void { Http::$response = [$code, json_encode($data), '']; }
    function request(): array { return Http::$requests[array_key_last(Http::$requests)]; }

    $domain = new Registrar_Domain();
    foreach (['test' => 'https://api.dev.name.com', 'production' => 'https://api.name.com'] as $environment => $base) {
        $module = new Registrar_Adapter_Connect(['registrar'=>'namecom', 'environment'=>$environment, 'username'=>'user', 'api_key'=>'token']);
        response(['results'=>[['domainName'=>'example.test', 'purchasable'=>true]]]);
        check($module->isDomainAvailable($domain), 'Available domain');
        check(str_starts_with(request()[1], $base . '/core/v1/'), 'Endpoint selection');
        check(request()[2]['auth'] === ['user', 'token'], 'Credentials');
        response(['results'=>[['domainName'=>'example.test', 'purchasable'=>false]]]);
        check(!$module->isDomainAvailable($domain), 'Unavailable domain');
        response([]);
        check($module->registerDomain($domain), 'Register success');
        $body = request()[2]['json'];
        check($body['years'] === 2, 'Registration period');
        check($body['domain']['nameservers'] === ['ns1.example.test', 'ns2.example.test'], 'Nameserver normalization');
        check($body['domain']['contacts']['registrant']['firstName'] === 'Ada', 'Registrant mapping');
        check($body['domain']['contacts']['billing']['companyName'] === 'Test Ltd', 'Contact fallback and organization');
        $module->transferDomain($domain);
        check(request()[2]['json']['authCode'] === 'secret', 'Transfer code');
        $module->renewDomain($domain);
        check(request()[2]['json']['years'] === 2, 'Renew period');
        $module->modifyNs($domain);
        check(request()[2]['json']['nameservers'] === ['ns1.example.test', 'ns2.example.test'], 'Nameserver update');
        response(['createDate'=>'2025-01-01T00:00:00Z', 'expireDate'=>'2027-01-01T00:00:00Z', 'nameservers'=>['ns3.example.test']]);
        check($module->getDomainDetails($domain) === $domain, 'Domain identity');
        check($domain->values['setRegistrationTime'] === strtotime('2025-01-01T00:00:00Z'), 'Creation date');
        check($domain->values['setExpirationTime'] === strtotime('2027-01-01T00:00:00Z'), 'Expiration date');
        check($domain->values['setNs1'] === 'ns3.example.test', 'Remote nameservers');
        response(['authCode'=>'remote-secret']);
        check($module->getEpp($domain) === 'remote-secret', 'Auth response');
        foreach (['lock'=>'lock', 'unlock'=>'unlock', 'enablePrivacyProtection'=>'enableWhoisPrivacy', 'disablePrivacyProtection'=>'disableWhoisPrivacy'] as $method => $action) {
            response([]);
            check($module->$method($domain), $method);
            check(request()[0] === 'POST' && str_ends_with(request()[1], ':' . $action), 'Management endpoint');
        }
        response(['message'=>'Denied'], 403);
        foreach (['registerDomain', 'renewDomain', 'transferDomain', 'modifyNs', 'getDomainDetails', 'getEpp', 'lock', 'disablePrivacyProtection', 'isDomainAvailable'] as $method) {
            try { $module->$method($domain); throw new RuntimeException('Failure was accepted: ' . $method); }
            catch (Registrar_Exception $e) { check(str_contains($e->getMessage(), 'Connect (Name.com) failed'), 'API error conversion'); }
        }
        response([]);
        try { $module->getEpp($domain); throw new RuntimeException('Empty auth code accepted'); }
        catch (Registrar_Exception $e) {}
    }
    foreach (['test'=>'horizon.opensrs.net', 'production'=>'rr-n1-tor.opensrs.net'] as $environment => $host) {
        $module = new Registrar_Adapter_Connect(['registrar'=>'opensrs', 'environment'=>$environment, 'username'=>'user', 'api_key'=>'key', 'password'=>'password']);
        Http::$response = [200, '<OPS_envelope><body><data_block><dt_assoc><item key="response_code">200</item></dt_assoc></data_block></body></OPS_envelope>', ''];
        check($module->registerDomain($domain), 'OpenSRS registration');
        check(request()[1] === 'https://' . $host . ':55443', 'OpenSRS endpoint');
        $xml = simplexml_load_string(request()[2]['body']);
        $contacts = $xml->xpath('//item[@key="contact_set"]/dt_assoc/item');
        check(array_map(fn ($item) => (string) $item['key'], $contacts) === ['owner', 'admin', 'tech', 'billing'], 'OpenSRS contact roles');
        check(str_contains(request()[2]['body'], 'Test Ltd'), 'OpenSRS company');
        $module->transferDomain($domain);
        check(str_contains(request()[2]['body'], '<item key="auth_info">secret</item>'), 'OpenSRS transfer code');
        Http::$response = [200, '<OPS_envelope><body><data_block><dt_assoc><item key="response_code">400</item><item key="response_text">Denied</item></dt_assoc></data_block></body></OPS_envelope>', ''];
        try { $module->modifyNs($domain); throw new RuntimeException('OpenSRS failure accepted'); }
        catch (Registrar_Exception $e) { check(str_contains($e->getMessage(), 'Denied'), 'OpenSRS error'); }
    }
    echo "Connect integration tests passed\n";
}
