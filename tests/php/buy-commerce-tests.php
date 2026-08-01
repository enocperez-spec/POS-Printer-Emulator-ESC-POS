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

$refundEvent = paypal_reversal_event([
    'id' => 'WH-REFUND-12345678',
    'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
    'resource' => [
        'supplementary_data' => [
            'related_ids' => [
                'order_id' => '54P31732R3501353M',
                'capture_id' => '5YY727363P031880X',
            ],
        ],
    ],
]);
$expectSame('refund', $refundEvent['reversalType'] ?? null, 'A verified refund event must map to refund reconciliation.');
$expectSame('54P31732R3501353M', $refundEvent['providerOrderId'] ?? null, 'Refund reconciliation lost the PayPal order ID.');
$expectSame('5YY727363P031880X', $refundEvent['providerCaptureId'] ?? null, 'Refund reconciliation lost the PayPal capture ID.');
$linkedRefundEvent = paypal_reversal_event([
    'id' => 'WH-LINKED-12345678',
    'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
    'resource' => [
        'id' => '0AB40423VK1090813',
        'links' => [
            [
                'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/9CA92542VH120702K',
                'rel' => 'up',
                'method' => 'GET',
            ],
        ],
    ],
]);
$expectSame('9CA92542VH120702K', $linkedRefundEvent['providerCaptureId'] ?? null, 'PayPal refund up-links must resolve the original capture ID.');
$legacyRefundEvent = paypal_reversal_event([
    'id' => 'WH-LEGACY-12345678',
    'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
    'resource' => ['sale_id' => '9CA92542VH120702K'],
]);
$expectSame('9CA92542VH120702K', $legacyRefundEvent['providerCaptureId'] ?? null, 'Legacy PayPal refund resources must resolve the original transaction ID.');
$disputeEvent = paypal_reversal_event([
    'id' => 'WH-DISPUTE-12345678',
    'event_type' => 'CUSTOMER.DISPUTE.CREATED',
    'resource' => [
        'disputed_transactions' => [
            ['seller_transaction_id' => '5YY727363P031880X'],
        ],
    ],
]);
$expectSame('chargeback', $disputeEvent['reversalType'] ?? null, 'A customer dispute must enter chargeback review.');
$expectSame('5YY727363P031880X', $disputeEvent['providerCaptureId'] ?? null, 'Dispute reconciliation lost the seller transaction ID.');
$expectSame(null, paypal_reversal_event([
    'id' => 'WH-IGNORED-12345678',
    'event_type' => 'CHECKOUT.ORDER.APPROVED',
]), 'Unrelated PayPal events must be acknowledged without changing commerce state.');

$captureEndpoint=file_get_contents(dirname(__DIR__,2).'/buy-website/api/capture-order.php')?:'';
$portalOrderCreate=file_get_contents(dirname(__DIR__,2).'/buy-website/api/create-portal-order.php')?:'';
$portalOrderCapture=file_get_contents(dirname(__DIR__,2).'/buy-website/api/capture-portal-order.php')?:'';
$paypalWebhook=file_get_contents(dirname(__DIR__,2).'/buy-website/api/paypal-webhook.php')?:'';
$portalCommerce=file_get_contents(dirname(__DIR__,2).'/admin-website/api/v1/portal-commerce.php')?:'';
$buyBootstrap=file_get_contents(dirname(__DIR__,2).'/buy-website/includes/bootstrap.php')?:'';
$expectSame(true,str_contains($portalOrderCreate,'custom_id'),'Account checkout must pass the journey correlation ID to PayPal.');
$expectSame(true,str_contains($portalOrderCapture,'hash_equals($correlationId, $providerCorrelationId)'),'Capture must reject a mismatched PayPal correlation ID.');
$expectSame(true,str_contains($portalOrderCapture,'$captured = paypal_request(\'GET\', $paypalPath);'),'Capture verification must use PayPal’s authoritative order response.');
$expectSame(true,str_contains($portalCommerce,'customer_id=:expected_customer_id'),'License linking must use distinct native PDO parameter names.');
$expectSame(true,str_contains($portalCommerce,"'expected_customer_id' => \$intent['customer_id']"),'License linking must bind the distinct customer guard parameter.');
$expectSame(true,str_contains($captureEndpoint,"['create_time']"),'Renewal coverage must use PayPal capture time instead of local retry time.');
$expectSame(true,str_contains($captureEndpoint,"paypal_request('GET',\$paypalPath)"),'A lost capture response must be reconcilable without charging again.');
$expectSame(true,str_contains($paypalWebhook,'paypal_verify_webhook($event)'),'PayPal reversals must pass provider signature verification.');
$expectSame(true,str_contains($paypalWebhook,"'record-provider-reversal'"),'Verified PayPal reversals must use the protected Admin reconciliation channel.');
$expectSame(true,str_contains($buyBootstrap,"'/v1/notifications/verify-webhook-signature'"),'PayPal webhook verification must use the provider verification API.');
$expectSame(true,str_contains($buyBootstrap,"preg_match('/(?:^|\\.)paypal\\.com$/i'"),'Webhook certificate URLs must be restricted to PayPal HTTPS hosts.');
$expectSame(true,str_contains($buyBootstrap,"\$environment !== 'sandbox'"),'PayPal negative testing must be restricted to the sandbox environment.');
$expectSame(true,str_contains($buyBootstrap,"https://api-m.sandbox.paypal.com"),'PayPal negative testing must be restricted to the sandbox API host.');
$expectSame(true,str_contains($buyBootstrap,'PayPal-Mock-Response:'),'The sandbox certification path must use PayPal’s official negative-testing header.');
$expectSame(true,str_contains($portalOrderCreate,"'retryable' => true"),'A provider create failure must return a safe retry response.');
$expectSame(true,str_contains($portalOrderCreate,'No payment was taken'),'A provider create failure must explicitly reassure the customer that no payment was taken.');
$expectSame(true,str_contains($portalOrderCapture,'PORTAL_PROVIDER_FAILURE'),'A provider capture failure must be recorded for audit review.');
$expectSame(true,str_contains($portalOrderCapture,'Do not submit another payment'),'A capture uncertainty must warn the customer not to pay twice.');
$configExample=file_get_contents(dirname(__DIR__,2).'/buy-website/private/config.example.php')?:'';
$expectSame(true,str_contains($configExample,'REPLACE_WITH_DISTINCT_MAINTENANCE_SERVICE_TOKEN'),'The Buy-to-Admin maintenance credential must be explicitly distinct.');
$expectSame(true,str_contains($configExample,'REPLACE_WITH_PAYPAL_WEBHOOK_ID'),'PayPal webhook registration must expose its deployment-specific identifier.');
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
