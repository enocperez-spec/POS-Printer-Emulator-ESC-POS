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
$expectSame(false,is_file(dirname(__DIR__,2).'/buy-website/includes/license_keys.php'),'The Buy website must not retain an activation-key generator.');

$captureEndpoint=file_get_contents(dirname(__DIR__,2).'/buy-website/api/capture-order.php')?:'';
$portalOrderCreate=file_get_contents(dirname(__DIR__,2).'/buy-website/api/create-portal-order.php')?:'';
$portalOrderCapture=file_get_contents(dirname(__DIR__,2).'/buy-website/api/capture-portal-order.php')?:'';
$portalCommerce=file_get_contents(dirname(__DIR__,2).'/admin-website/api/v1/portal-commerce.php')?:'';
$expectSame(true,str_contains($portalOrderCreate,'custom_id'),'Account checkout must pass the journey correlation ID to PayPal.');
$expectSame(true,str_contains($portalOrderCapture,'hash_equals($correlationId, $providerCorrelationId)'),'Capture must reject a mismatched PayPal correlation ID.');
$expectSame(true,str_contains($portalOrderCapture,'$captured = paypal_request(\'GET\', $paypalPath);'),'Capture verification must use PayPal’s authoritative order response.');
$expectSame(true,str_contains($portalCommerce,'customer_id=:expected_customer_id'),'License linking must use distinct native PDO parameter names.');
$expectSame(true,str_contains($portalCommerce,"'expected_customer_id' => \$intent['customer_id']"),'License linking must bind the distinct customer guard parameter.');
$expectSame(true,str_contains($captureEndpoint,"['create_time']"),'Renewal coverage must use PayPal capture time instead of local retry time.');
$expectSame(true,str_contains($captureEndpoint,"paypal_request('GET',\$paypalPath)"),'A lost capture response must be reconcilable without charging again.');
$configExample=file_get_contents(dirname(__DIR__,2).'/buy-website/private/config.example.php')?:'';
$expectSame(true,str_contains($configExample,'REPLACE_WITH_DISTINCT_MAINTENANCE_SERVICE_TOKEN'),'The Buy-to-Admin maintenance credential must be explicitly distinct.');
$buyBootstrap=file_get_contents(dirname(__DIR__,2).'/buy-website/includes/bootstrap.php')?:'';
$expectSame(true,str_contains($buyBootstrap,"if (\$orderType === 'LICENSE')"),'The secure checkout must accept a distinct new-license order.');
$expectSame(true,str_contains($buyBootstrap,'return license_offer($targetTier);'),'A new or additional license must use its full configured price.');
$purchasePage=file_get_contents(dirname(__DIR__,2).'/buy-website/index.php')?:'';
$successPage=file_get_contents(dirname(__DIR__,2).'/buy-website/success.php')?:'';
$selfServicePage=file_get_contents(dirname(__DIR__,2).'/buy-website/self-service.php')?:'';
$selfServiceJs=file_get_contents(dirname(__DIR__,2).'/buy-website/assets/self-service.js')?:'';
$publicPricingEndpoint=file_get_contents(dirname(__DIR__,2).'/buy-website/api/public-pricing.php')?:'';
$websiteCatalogJs=file_get_contents(dirname(__DIR__,2).'/website/license-catalog.js')?:'';
$expectSame(true,str_contains($purchasePage,'Log In to Purchase'),'The public purchase page must send customers to portal authentication.');
$expectSame(true,str_contains($purchasePage,'userportal.posprinteremulator.com'),'The purchase page must use the secure Customer Portal handoff.');
$expectSame(false,str_contains(strtolower($purchasePage),'permanent desktop license'),'Legacy Permanent Desktop License wording must be removed.');
$expectSame(false,str_contains($purchasePage,'paypal-button'),'The public purchase page must not accept payment directly.');
$expectSame(true,str_contains($successPage,'userportal.posprinteremulator.com/index.php?return=billing'),'Completed purchases must return to account-bound Customer Portal billing history.');
$expectSame(true,str_contains($successPage,'View Purchase &amp; Billing History'),'The payment confirmation must clearly label the Customer Portal destination.');
$expectSame(true,str_contains($selfServiceJs,'return { orderId: result.orderId };'),'PayPal Web SDK v6 must receive an order result object whose orderId property is a string.');
$expectSame(true,str_contains($selfServiceJs,"error?.status === 410"),'Expired checkout sessions must disable payment and offer a safe restart.');
$expectSame(true,str_contains($selfServicePage,'userportal-sandbox.posprinteremulator.com'),'Sandbox checkout must return customers to the sandbox Customer Portal.');
$expectSame(true,str_contains($publicPricingEndpoint,'license_offers()'),'Public pricing must read the same managed license offers used by checkout.');
$expectSame(true,str_contains($publicPricingEndpoint,'maintenance_offers()'),'Public pricing must read the same managed maintenance offers used by checkout.');
$expectSame(true,str_contains($publicPricingEndpoint,'Access-Control-Allow-Origin'),'Public pricing must restrict browser access to approved marketing origins.');
$expectSame(false,str_contains($publicPricingEndpoint,'require_admin_api_token'),'The public pricing feed must remain read-only and must not expose or require the Admin API credential.');
$expectSame(true,str_contains($websiteCatalogJs,'api/public-pricing.php'),'The marketing comparison must load Admin-managed pricing from the Buy website.');

if ($failures !== []) {
    fwrite(STDERR, "Buy commerce tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Buy commerce tests passed.\n";
