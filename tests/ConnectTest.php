<?php

declare(strict_types=1);

namespace Namingo\Cardo\DRS\Core {
    // Replace only transport; exercise the installed Cardo adapters unchanged.
    final class Http {
        public static array $requests = [];
        public static array $responses = [];
        public static array $response = [200, '{}', ''];
        public static function request(string $method, string $url, array $options = [], int $timeout = 30): array {
            self::$requests[] = [$method, $url, $options];
            return self::$responses !== [] ? array_shift(self::$responses) : self::$response;
        }
    }
}

namespace {
    require __DIR__ . '/../namingo/vendor/autoload.php';

    class Registrar_Exception extends RuntimeException {
        public function __construct(string $message, ?array $params = null, int $code = 0) { parent::__construct($message, $code); }
    }
    class TestLog {
        public static array $errors = [];
        public function error($message): void { self::$errors[] = $message; }
    }
    class Registrar_AdapterAbstract {
        public function getLog(): object { return new TestLog(); }
    }
    class Registrar_Domain_Contact {
        public function __call(string $method, array $args): string {
            return ['getFirstName'=>'Ada', 'getLastName'=>'Lovelace', 'getEmail'=>'ada@example.test',
                'getAddress1'=>'1 Test Street', 'getCity'=>'London', 'getState'=>'London', 'getCountry'=>'gb', 'getZip'=>'TEST',
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
    $providers = Registrar_Adapter_Connect::getConfig()['form']['registrar'][1]['multiOptions'];
    check(count($providers) === 6, 'All Cardo providers selectable');
    foreach (['godaddy', 'namecheap', 'dynadot', 'namesilo'] as $brand) {
        $options = ['registrar'=>$brand, 'environment'=>'production', 'api_key'=>'key',
            'api_secret'=>'secret', 'api_user'=>'user', 'client_ip'=>'192.0.2.1'];
        $module = new Registrar_Adapter_Connect($options);
        $httpResponses = match ($brand) {
            'godaddy' => [200, json_encode(['domain'=>'example.test', 'available'=>true]), ''],
            'namesilo' => [200, json_encode(['reply'=>['code'=>300, 'available'=>['domain'=>'example.test']]]), ''],
            'dynadot' => [200, json_encode(['SearchResponse'=>['ResponseCode'=>0, 'SearchResults'=>[['DomainName'=>'example.test', 'Available'=>'yes']]]]), ''],
            'namecheap' => [200, '<ApiResponse Status="OK"><CommandResponse><DomainCheckResult Domain="example.test" Available="true" /></CommandResponse></ApiResponse>', ''],
        };
        Http::$response = $httpResponses;
        check($module->isDomainAvailable($domain), $brand . ' availability');
        $req = request();
        if ($brand === 'godaddy') check($req[2]['headers']['Authorization'] === 'sso-key key:secret', 'GoDaddy secret mapping');
        if ($brand === 'namecheap') check($req[2]['query']['ApiUser'] === 'user' && $req[2]['query']['ClientIp'] === '192.0.2.1', 'Namecheap credentials');
        if ($brand === 'dynadot') check($req[2]['query']['domain0'] === 'example.test', 'Dynadot search parameter');

        $success = match ($brand) {
            'godaddy' => [200, '{}', ''],
            'namesilo' => [200, '{"reply":{"code":300,"detail":"success"}}', ''],
            'dynadot' => [200, '{"RegisterResponse":{"ResponseCode":0,"Status":"success"}}', ''],
            'namecheap' => [200, '<ApiResponse Status="OK"><CommandResponse><DomainCreateResult Registered="true" /></CommandResponse></ApiResponse>', ''],
        };
        Http::$response = $success;
        Http::$requests = [];
        if ($brand === 'godaddy') {
            Http::$responses = [[200, '[{"agreementKey":"DNRA"},{"agreementKey":"TLD-SPECIAL"}]', ''], $success];
        }
        if ($brand === 'dynadot') {
            Http::$responses = [[200, '{"CreateContactResponse":{"ResponseCode":0,"Status":"success","CreateContactContent":{"ContactId":"42"}}}', ''], $success, $success];
        }
        check($module->registerDomain($domain), $brand . ' registration');
        $purchase = Http::$requests[in_array($brand, ['dynadot', 'godaddy'], true) ? 1 : 0];
        if ($brand === 'godaddy') {
            check($purchase[2]['json']['contactRegistrant']['nameFirst'] === 'Ada', 'GoDaddy contact format');
            check($purchase[2]['json']['contactRegistrant']['addressMailing']['country'] === 'GB', 'GoDaddy address');
            check($purchase[2]['json']['consent']['agreedBy'] === '192.0.2.1', 'Consent IP');
            check($purchase[2]['json']['consent']['agreementKeys'] === ['DNRA', 'TLD-SPECIAL'], 'TLD agreements forwarded');
            check(Http::$requests[0][0] === 'GET' && !isset(Http::$requests[0][2]['json']), 'Agreement lookup has no JSON body');
            check($purchase[2]['json']['renewAuto'] === false, 'GoDaddy auto-renew disabled');
        }
        if ($brand === 'namesilo') {
            check($purchase[2]['query']['fn'] === 'Ada' && $purchase[2]['query']['ad'] === '1 Test Street', 'NameSilo registrant mapping');
            check($purchase[2]['query']['ph'] === '12345', 'NameSilo subscriber phone');
            check(!isset($purchase[2]['query']['rr_fn']), 'No incorrect rr prefix');
            check(request()[2]['query']['ns1'] === 'ns1.example.test', 'NameSilo nameserver parameters');
        }
        if ($brand === 'dynadot') {
            check($purchase[2]['query']['registrant_contact'] === '42' && $purchase[2]['query']['technical_contact'] === '42', 'Dynadot per-customer contact IDs');
            check(count(Http::$requests) === 3, 'Identical Dynadot contacts reused within registration');
            check(request()[2]['query']['ns0'] === 'ns1.example.test', 'Dynadot nameserver parameters');
        }
        if ($brand === 'namecheap') check($purchase[2]['query']['RegistrantFirstName'] === 'Ada', 'Namecheap customer contact');
        check($module->transferDomain($domain), $brand . ' transfer');
        if ($brand === 'dynadot') check(request()[2]['query']['auth'] === 'secret', 'Dynadot transfer authorization parameter');
        check($module->renewDomain($domain), $brand . ' renew');
        check($module->modifyNs($domain), $brand . ' nameservers');
        if ($brand === 'godaddy') check(request()[0] === 'PATCH' && request()[2]['json']['nameServers'] === ['ns1.example.test', 'ns2.example.test'], 'GoDaddy nameserver endpoint');

        Http::$response = match ($brand) {
            'godaddy' => [200, '{"createdAt":"2025-01-01T00:00:00Z","expires":"2027-01-01T00:00:00Z","nameServers":["ns1.example.test"]}', ''],
            'namesilo' => [200, '{"reply":{"code":300,"created":"2025-01-01T00:00:00Z","expires":"2027-01-01T00:00:00Z","nameservers":{"nameserver":"ns1.example.test"}}}', ''],
            'dynadot' => [200, '{"DomainInfoResponse":{"ResponseCode":0,"DomainInfo":{"Registration":"1735689600000","Expiration":"1798761600000","NameServerSettings":{"NameServers":["ns1.example.test"]}}}}', ''],
            'namecheap' => [200, '<ApiResponse Status="OK"><CommandResponse><DomainGetInfoResult><DomainDetails><CreatedDate>2025-01-01T00:00:00Z</CreatedDate><ExpiredDate>2027-01-01T00:00:00Z</ExpiredDate></DomainDetails><DnsDetails><Nameserver>ns1.example.test</Nameserver></DnsDetails></DomainGetInfoResult></CommandResponse></ApiResponse>', ''],
        };
        $domain->values = [];
        $module->getDomainDetails($domain);
        check($domain->values['setRegistrationTime'] === 1735689600, $brand . ' creation date');
        check($domain->values['setExpirationTime'] === 1798761600, $brand . ' expiration date');
        check($domain->values['setNs1'] === 'ns1.example.test', $brand . ' remote nameservers');
        foreach (['getEpp', 'lock', 'unlock', 'enablePrivacyProtection', 'disablePrivacyProtection', 'modifyContact', 'deleteDomain'] as $method) {
            $count = count(Http::$requests);
            try { $module->$method($domain); throw new RuntimeException('Unsupported action accepted: ' . $method); }
            catch (Registrar_Exception $e) {}
            check(count(Http::$requests) === $count, 'Unsupported action sends no request');
        }
        Http::$response = match ($brand) {
            'godaddy' => [403, '{"code":"ACCESS_DENIED"}', ''],
            'namesilo' => [200, '{"reply":{"code":200,"detail":"Denied"}}', ''],
            'dynadot' => [200, '{"RenewResponse":{"ResponseCode":-1,"Status":"error","Error":"Denied"}}', ''],
            'namecheap' => [200, '<ApiResponse Status="ERROR"><Errors><Error>Denied</Error></Errors></ApiResponse>', ''],
        };
        foreach (['renewDomain', 'transferDomain', 'modifyNs', 'getDomainDetails'] as $method) {
            try { $module->$method($domain); throw new RuntimeException('Provider error accepted'); }
            catch (Registrar_Exception $e) {}
        }
        Http::$response = [0, '', 'Network unavailable'];
        try { $module->renewDomain($domain); throw new RuntimeException('Transport failure accepted'); }
        catch (Registrar_Exception $e) {}
    }
    foreach (['godaddy'=>'https://api.ote-godaddy.com/v1', 'dynadot'=>'https://api-sandbox.dynadot.com/api3.json', 'namecheap'=>'https://api.sandbox.namecheap.com/xml.response'] as $brand => $endpoint) {
        $module = new Registrar_Adapter_Connect(['registrar'=>$brand, 'api_key'=>'key', 'api_secret'=>'secret', 'api_user'=>'user', 'client_ip'=>'192.0.2.1']);
        Http::$response = [403, '{}', ''];
        try { $module->renewDomain($domain); } catch (Registrar_Exception $e) {}
        check(str_starts_with(request()[1], $endpoint), $brand . ' sandbox');
    }
    $module = new Registrar_Adapter_Connect(['registrar'=>'namecheap', 'api_key'=>'key', 'api_user'=>'user', 'client_ip'=>'192.0.2.1']);
    Http::$requests = [];
    Http::$responses = [[200, '<ApiResponse Status="OK"><CommandResponse><DomainCreateResult Registered="true" /></CommandResponse></ApiResponse>', ''], [503, '{}', '']];
    check($module->registerDomain($domain), 'Completed purchase remains successful after nameserver failure');
    check(count(Http::$requests) === 2, 'One purchase and one nameserver attempt');
    $module = new Registrar_Adapter_Connect(['registrar'=>'godaddy', 'api_key'=>'key', 'api_secret'=>'secret', 'client_ip'=>'192.0.2.1', 'endpoint'=>'https://example.test/v1']);
    Http::$responses = [[200, '[{"agreementKey":"SPECIAL"}]', ''], [200, '{}', '']];
    check($module->registerDomain($domain), 'GoDaddy purchase');
    check(request()[1] === 'https://example.test/v1/domains/purchase', 'Endpoint override and correct purchase path');
    $godaddy = new Registrar_Adapter_Connect(['registrar'=>'godaddy', 'api_key'=>'key', 'api_secret'=>'secret', 'client_ip'=>'192.0.2.1']);
    foreach ([[403, '{}', ''], [0, '', 'Transport error'], [200, '[]', ''], [200, '[{"title":"No key"}]', ''], [200, 'not-json', '']] as $failure) {
        Http::$requests = [];
        Http::$response = $failure;
        try { $godaddy->registerDomain($domain); throw new RuntimeException('Invalid agreements accepted'); }
        catch (Registrar_Exception $e) {}
        check(count(Http::$requests) === 1 && Http::$requests[0][0] === 'GET', 'No purchase after agreement lookup failure');
    }
    $compoundDomain = new class extends Registrar_Domain {
        public function getName(): string { return 'example.co.uk'; }
    };
    Http::$requests = [];
    Http::$responses = [[200, '[{"agreementKey":"COUK"}]', ''], [200, '{}', '']];
    check($godaddy->registerDomain($compoundDomain), 'Compound TLD registration');
    parse_str(parse_url(Http::$requests[0][1], PHP_URL_QUERY), $query);
    check($query['tlds'] === 'co.uk' && $query['privacy'] === 'false', 'Compound TLD and privacy agreement parameters');

    $namesilo = new Registrar_Adapter_Connect(['registrar'=>'namesilo', 'environment'=>'production', 'api_key'=>'key']);
    $formatter = new ReflectionMethod($namesilo, 'formatProviderPhone');
    foreach (['12345', '+44 12345', '+44.12345'] as $phone) {
        $contact = new class($phone) extends Registrar_Domain_Contact {
            public function __construct(private string $phone) {}
            public function getTel(): string { return $this->phone; }
        };
        check($formatter->invoke($namesilo, $contact) === '12345', 'Local and international NameSilo phones');
    }
    Http::$requests = [];
    Http::$response = [200, '{"reply":{"code":302,"detail":"Default contact used"}}', ''];
    try { $namesilo->registerDomain($domain); throw new RuntimeException('Default contact fallback accepted'); }
    catch (Registrar_Exception $e) {
        check(str_contains($e->getMessage(), 'Do not retry registration'), 'Already purchased warning');
    }
    check(count(Http::$requests) === 1, 'No retry or follow-up after contact fallback');
    foreach ([[200, '{"reply":{"code":300}}', ''], [200, '{"reply":{"code":200,"detail":"Nameserver update denied"}}', '']] as $repairResponse) {
        Http::$requests = [];
        TestLog::$errors = [];
        Http::$responses = [[200, '{"reply":{"code":301}}', ''], $repairResponse];
        check($namesilo->registerDomain($domain), 'NameSilo 301 remains a completed purchase');
        check(count(Http::$requests) === 2, 'One purchase and one nameserver repair');
        check(str_ends_with(Http::$requests[0][1], '/registerDomain'), 'Purchase occurs once');
        check(str_ends_with(Http::$requests[1][1], '/changeNameServers'), 'Safe nameserver repair');
        check(Http::$requests[1][2]['query']['ns1'] === 'ns1.example.test', 'Requested nameservers repaired');
        if ($repairResponse[1] !== '{"reply":{"code":300}}') {
            check((bool) array_filter(TestLog::$errors, fn ($message) => str_contains($message, 'registered example.test but nameservers require a manual update')), 'Explicit manual action after failed repair');
        } else {
            check(TestLog::$errors === [], 'Successful repair needs no manual action');
        }
    }
    Http::$requests = [];
    Http::$response = [200, '{"reply":{"code":300}}', ''];
    check($namesilo->registerDomain($domain), 'Normal NameSilo registration');
    check(count(Http::$requests) === 1, 'Code 300 does not trigger repair');
    foreach ([
        ['registrar'=>'namesilo', 'api_key'=>'key'],
        ['registrar'=>'godaddy', 'api_key'=>'key'],
        ['registrar'=>'namecheap', 'api_key'=>'key', 'api_user'=>'user', 'client_ip'=>'invalid'],
        ['registrar'=>'dynadot', 'api_key'=>'key', 'endpoint'=>'http://example.test'],
    ] as $options) {
        try { new Registrar_Adapter_Connect($options); throw new RuntimeException('Invalid configuration accepted'); }
        catch (Registrar_Exception $e) {}
    }
    echo "Connect integration tests passed\n";
}
