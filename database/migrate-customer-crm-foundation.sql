-- v0.3.42 is applied by admin-website/includes/customer_crm.php so existing
-- databases can add columns conditionally and backfill without changing any
-- license tier, activation key, maintenance date, or installation entitlement.
-- Run the protected Admin setup endpoint, then open /customers.php to execute
-- the idempotent customer-link backfill and review duplicate candidates.

INSERT IGNORE INTO development_migrations (migration_key)
VALUES ('customer-identity-consent-crm-v0.3.42');
