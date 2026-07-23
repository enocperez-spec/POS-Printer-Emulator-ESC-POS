<?php
declare(strict_types=1);

$buyRoot = dirname(__DIR__, 2) . '/buy-website';
$testConfigPath = $buyRoot . '/private/config.php';
$createdTestConfig = false;
if (!is_file($testConfigPath)) {
    $exampleConfigPath = $buyRoot . '/private/config.example.php';
    if (!copy($exampleConfigPath, $testConfigPath)) {
        fwrite(STDERR, "Could not create the temporary Buy-site test configuration.\n");
        exit(1);
    }
    $createdTestConfig = true;
    register_shutdown_function(static function () use ($testConfigPath): void {
        if (is_file($testConfigPath)) {
            @unlink($testConfigPath);
        }
    });
}

require dirname(__DIR__, 2) . '/buy-website/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/buy-website/includes/license_keys.php';

$failures = [];
$expectSame = static function (mixed $expected, mixed $actual, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
    }
};
$expectThrows = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message . ' Expected an InvalidArgumentException.';
    } catch (InvalidArgumentException) {
    }
};

$expectSame(['Lite', 'Pro', 'Enterprise'], paid_license_tiers(), 'Paid tier order changed.');
$expectSame('Lite', clean_license_tier(' lite '), 'Lite tier normalization failed.');
$expectSame('Pro', clean_license_tier('PRO'), 'Pro tier normalization failed.');
$expectSame('Enterprise', clean_license_tier('enterprise'), 'Enterprise tier normalization failed.');
$expectThrows(static fn(): string => clean_license_tier('Trial'), 'Trial must not be accepted as a paid checkout tier.');
$expectSame('license',clean_purchase_product(' LICENSE '),'Permanent-license product normalization failed.');
$expectSame('maintenance',clean_purchase_product('Maintenance'),'Maintenance product normalization failed.');
$expectThrows(static fn(): string => clean_purchase_product('subscription'),'Recurring subscription products must not be accepted.');

$expectSame(1, activation_tier_value('Pro'), 'Pro activation byte changed.');
$expectSame(2, activation_tier_value('Enterprise'), 'Enterprise activation byte changed.');
$expectSame(3, activation_tier_value('Lite'), 'Lite activation byte is not 3.');
$expectThrows(static fn(): int => activation_tier_value('Trial'), 'Trial must not receive a paid activation byte.');

$available = ['Lite', 'Pro', 'Enterprise'];
$expectSame('Lite', select_purchase_tier($available, null), 'Lite should be the default paid offer.');
$expectSame('Pro', select_purchase_tier($available, 'pro'), 'Safe Pro query preselection failed.');
$expectSame('Enterprise', select_purchase_tier($available, 'Enterprise'), 'Safe Enterprise query preselection failed.');
$expectSame('Lite', select_purchase_tier($available, 'invalid'), 'Invalid query tiers must fall back safely.');
$expectSame('Pro', select_purchase_tier(['Pro', 'Enterprise'], 'Lite'), 'Unavailable query tiers must use the first configured fallback.');

$configuredOffers = configured_license_offers();
$expectSame('24.99', $configuredOffers['Lite']['price'] ?? null, 'Lite fallback price must be $24.99.');
$expectSame('USD', $configuredOffers['Lite']['currency'] ?? null, 'Lite fallback currency must be USD.');
$maintenanceOffers=configured_maintenance_offers();
$expectSame('9.99',$maintenanceOffers['Lite']['price']??null,'Lite maintenance fallback must be $9.99.');
$expectSame('19.99',$maintenanceOffers['Pro']['price']??null,'Pro maintenance fallback must be $19.99.');
$expectSame('59.99',$maintenanceOffers['Enterprise']['price']??null,'Enterprise maintenance fallback must be $59.99.');
$expectSame(
    hash('sha256',"ACME POS\nowner@example.com"),
    maintenance_registration_digest("  Acme\t POS ",' Owner@Example.COM '),
    'Privacy-minimized registration digest normalization changed.'
);
$expectSame(
    '3edccffc4c9e391af25c7d5c7b612cc192b2fb3872dac8588d83eb6ad075a47d',
    maintenance_registration_digest("  José\tCafé  ",' José@Example.COM '),
    'The shared José Café registration digest vector changed.'
);
$expectSame('e0c8551396be02bc6377ac3d893048aa',bin2hex(activation_registration_hash("  José\tCafé  ")),'The shared customer hash vector changed.');
$expectSame('b0a53cf19e34d05b57bced7365c6b00d',bin2hex(activation_email_hash(' José@Example.COM ')),'The shared email hash vector changed.');

$expiration='2030-07-20 23:59:59';
$licenseId='00112233-4455-6677-8899-aabbccddeeff';
$expirationTimestamp=(new DateTimeImmutable($expiration,new DateTimeZone('UTC')))->getTimestamp();
$fakeActivationPayload=chr(3).dotnet_guid_bytes($licenseId).pack_unix_seconds(1784505600)
    .substr(hash('sha256','CONTRACT TEST',true),0,16).substr(hash('sha256','contract@example.com',true),0,16)
    .chr(3).pack_unix_seconds($expirationTimestamp);
$fakeActivation=$fakeActivationPayload.str_repeat("\0",64);
$expectSame(130,strlen($fakeActivation),'Activation v3 must be a 66-byte payload plus 64-byte signature.');
$expectSame(3,ord($fakeActivation[0]),'New activation payloads must use version 3.');
$parts=unpack('Nhigh/Nlow',substr($fakeActivation,58,8));
$expectSame($expirationTimestamp,((int)$parts['high']*4294967296)+(int)$parts['low'],'Activation v3 maintenance expiration changed.');

$fakeTokenBytes=chr(1).dotnet_guid_bytes($licenseId).pack_unix_seconds(1784505600).pack_unix_seconds($expirationTimestamp).chr(3).str_repeat("\0",64);
$fakeToken='PPEM1-'.rtrim(strtr(base64_encode($fakeTokenBytes),'+/','-_'),'=');
$expectSame(98,strlen($fakeTokenBytes),'PPEM1 must be a 34-byte payload plus 64-byte signature.');
$expectSame(true,maintenance_token_claims_match($fakeToken,$licenseId,'Lite',$expiration),'Valid maintenance token claims were rejected.');
$expectSame(false,maintenance_token_claims_match($fakeToken,$licenseId,'Pro',$expiration),'Maintenance token must be bound to its license tier.');
$expectSame(false,maintenance_token_claims_match($fakeToken,'00000000-0000-0000-0000-000000000001','Lite',$expiration),'Maintenance token must be bound to its License ID.');
$tamperedBytes=$fakeTokenBytes;$tamperedBytes[33]=chr(2);
$tampered='PPEM1-'.rtrim(strtr(base64_encode($tamperedBytes),'+/','-_'),'=');
$expectSame(false,maintenance_token_claims_match($tampered,$licenseId,'Lite',$expiration),'Tampered maintenance claims must be rejected.');

$captureEndpoint=file_get_contents(dirname(__DIR__,2).'/buy-website/api/capture-order.php')?:'';
$expectSame(true,str_contains($captureEndpoint,"['create_time']"),'Renewal coverage must use PayPal capture time instead of local retry time.');
$expectSame(true,str_contains($captureEndpoint,"paypal_request('GET',\$paypalPath)"),'A lost capture response must be reconcilable without charging again.');
$configExample=file_get_contents(dirname(__DIR__,2).'/buy-website/private/config.example.php')?:'';
$expectSame(true,str_contains($configExample,'REPLACE_WITH_DISTINCT_MAINTENANCE_SERVICE_TOKEN'),'The Buy-to-Admin maintenance credential must be explicitly distinct.');

if(function_exists('openssl_pkey_new')){
    $keyResource=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
    $privatePem='';
    if($keyResource===false||!openssl_pkey_export($keyResource,$privatePem)){
        $failures[]='Could not create an ephemeral P-256 key for entitlement tests.';
    }else{
    $details=openssl_pkey_get_details($keyResource);$publicPem=is_array($details)?(string)($details['key']??''):'';
    $issued=issue_activation_key('Contract Test','contract@example.com','Lite',$expiration,$privatePem);
    $encoded=substr((string)$issued['activation_key'],5);$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
    $decoded=base64_decode(strtr($encoded,'-_','+/'),true);
    $expectSame(130,is_string($decoded)?strlen($decoded):0,'Activation v3 must be a 66-byte payload plus 64-byte signature.');
    $expectSame(3,is_string($decoded)?ord($decoded[0]):0,'New activation keys must use payload version 3.');
    if(is_string($decoded)&&strlen($decoded)===130){
        $parts=unpack('Nhigh/Nlow',substr($decoded,58,8));
        $actualExpiration=((int)$parts['high']*4294967296)+(int)$parts['low'];
        $expectSame((new DateTimeImmutable($expiration,new DateTimeZone('UTC')))->getTimestamp(),$actualExpiration,'Activation v3 maintenance expiration changed.');
    }
    $token=(string)$issued['maintenance_token'];
    $tokenEncoded=substr($token,6);$tokenEncoded.=str_repeat('=',(4-strlen($tokenEncoded)%4)%4);
    $tokenBytes=base64_decode(strtr($tokenEncoded,'-_','+/'),true);
    $expectSame(98,is_string($tokenBytes)?strlen($tokenBytes):0,'PPEM1 must be a 34-byte payload plus 64-byte signature.');
    $expectSame(true,maintenance_token_matches($token,(string)$issued['license_id'],'Lite',$expiration,$publicPem),'Valid maintenance token was rejected.');
    $expectSame(false,maintenance_token_matches($token,(string)$issued['license_id'],'Pro',$expiration,$publicPem),'Maintenance token must be bound to its license tier.');
    $expectSame(false,maintenance_token_matches($token,'00000000-0000-0000-0000-000000000001','Lite',$expiration,$publicPem),'Maintenance token must be bound to its License ID.');
    if(is_string($tokenBytes)){
        $tokenBytes[40]=chr(ord($tokenBytes[40])^1);
        $tampered='PPEM1-'.rtrim(strtr(base64_encode($tokenBytes),'+/','-_'),'=');
        $expectSame(false,maintenance_token_matches($tampered,(string)$issued['license_id'],'Lite',$expiration,$publicPem),'Tampered maintenance token must fail signature validation.');
    }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Buy commerce tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Buy commerce tests passed.\n";
