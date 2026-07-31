INSERT INTO customers(
    customer_id, display_name, company_name, canonical_email, email_hash, email_verified_at, status
) VALUES
    ('00000000-0000-4000-8000-000000000001','Trial Certification','POS Printer Emulator Certification','cert-trial@example.invalid',UNHEX(SHA2('cert-trial@example.invalid',256)),UTC_TIMESTAMP(6),'Active'),
    ('00000000-0000-4000-8000-000000000002','Lite Certification','POS Printer Emulator Certification','cert-lite@example.invalid',UNHEX(SHA2('cert-lite@example.invalid',256)),UTC_TIMESTAMP(6),'Active'),
    ('00000000-0000-4000-8000-000000000003','Pro Certification','POS Printer Emulator Certification','cert-pro@example.invalid',UNHEX(SHA2('cert-pro@example.invalid',256)),UTC_TIMESTAMP(6),'Active'),
    ('00000000-0000-4000-8000-000000000004','Enterprise Certification','POS Printer Emulator Certification','cert-enterprise@example.invalid',UNHEX(SHA2('cert-enterprise@example.invalid',256)),UTC_TIMESTAMP(6),'Active'),
    ('00000000-0000-4000-8000-000000000005','Expired Maintenance Certification','POS Printer Emulator Certification','cert-expired@example.invalid',UNHEX(SHA2('cert-expired@example.invalid',256)),UTC_TIMESTAMP(6),'Active')
ON DUPLICATE KEY UPDATE
    display_name=VALUES(display_name),
    company_name=VALUES(company_name),
    canonical_email=VALUES(canonical_email),
    email_hash=VALUES(email_hash),
    email_verified_at=VALUES(email_verified_at),
    status='Active';

INSERT INTO issued_licenses(
    license_id, customer_id, customer_name, email_address, license_tier, issued_at,
    created_by, control_state, license_source, source_reference, maintenance_expires_at
) VALUES
    ('20000000-0000-4000-8000-000000000002','00000000-0000-4000-8000-000000000002','Lite Certification','cert-lite@example.invalid','Lite',UTC_TIMESTAMP(6),'certification-seed','Enabled','Manual','certification:lite',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR)),
    ('20000000-0000-4000-8000-000000000003','00000000-0000-4000-8000-000000000003','Pro Certification','cert-pro@example.invalid','Pro',UTC_TIMESTAMP(6),'certification-seed','Enabled','Manual','certification:pro',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR)),
    ('20000000-0000-4000-8000-000000000004','00000000-0000-4000-8000-000000000004','Enterprise Certification','cert-enterprise@example.invalid','Enterprise',UTC_TIMESTAMP(6),'certification-seed','Enabled','Manual','certification:enterprise',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR)),
    ('20000000-0000-4000-8000-000000000005','00000000-0000-4000-8000-000000000005','Expired Maintenance Certification','cert-expired@example.invalid','Lite',UTC_TIMESTAMP(6),'certification-seed','Enabled','Manual','certification:expired',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE
    customer_id=VALUES(customer_id),
    customer_name=VALUES(customer_name),
    email_address=VALUES(email_address),
    license_tier=VALUES(license_tier),
    control_state='Enabled',
    maintenance_expires_at=VALUES(maintenance_expires_at);

INSERT INTO installations(
    installation_uuid, customer_id, token_hash, customer_name, email_address, app_version,
    windows_version, license_mode, license_id, maintenance_status, maintenance_expires_at,
    country_code, region_code, last_launch_at, launch_count
) VALUES
    ('10000000-0000-4000-8000-000000000001','00000000-0000-4000-8000-000000000001',UNHEX(SHA2('10000000-0000-4000-8000-000000000001',256)),'Trial Certification','cert-trial@example.invalid','0.3.55','Windows 11 Pro','Trial',NULL,'NotApplicable',NULL,'US','GA',UTC_TIMESTAMP(6),1),
    ('10000000-0000-4000-8000-000000000002','00000000-0000-4000-8000-000000000002',UNHEX(SHA2('10000000-0000-4000-8000-000000000002',256)),'Lite Certification','cert-lite@example.invalid','0.3.55','Windows 11 Pro','Lite','20000000-0000-4000-8000-000000000002','Active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR),'US','GA',UTC_TIMESTAMP(6),1),
    ('10000000-0000-4000-8000-000000000003','00000000-0000-4000-8000-000000000003',UNHEX(SHA2('10000000-0000-4000-8000-000000000003',256)),'Pro Certification','cert-pro@example.invalid','0.3.55','Windows 11 Pro','Pro','20000000-0000-4000-8000-000000000003','Active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR),'US','GA',UTC_TIMESTAMP(6),1),
    ('10000000-0000-4000-8000-000000000004','00000000-0000-4000-8000-000000000004',UNHEX(SHA2('10000000-0000-4000-8000-000000000004',256)),'Enterprise Certification','cert-enterprise@example.invalid','0.3.55','Windows 11 Pro','Enterprise','20000000-0000-4000-8000-000000000004','Active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 YEAR),'US','GA',UTC_TIMESTAMP(6),1),
    ('10000000-0000-4000-8000-000000000005','00000000-0000-4000-8000-000000000005',UNHEX(SHA2('10000000-0000-4000-8000-000000000005',256)),'Expired Maintenance Certification','cert-expired@example.invalid','0.3.55','Windows 11 Pro','Lite','20000000-0000-4000-8000-000000000005','Expired',DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 DAY),'US','GA',UTC_TIMESTAMP(6),1)
ON DUPLICATE KEY UPDATE
    customer_id=VALUES(customer_id),
    customer_name=VALUES(customer_name),
    email_address=VALUES(email_address),
    app_version=VALUES(app_version),
    windows_version=VALUES(windows_version),
    license_mode=VALUES(license_mode),
    license_id=VALUES(license_id),
    maintenance_status=VALUES(maintenance_status),
    maintenance_expires_at=VALUES(maintenance_expires_at),
    portal_deactivated_at=NULL;

INSERT INTO license_device_bindings(
    binding_id, license_id, customer_id, installation_id, binding_state,
    activation_method, activated_at
)
SELECT '30000000-0000-4000-8000-000000000002','20000000-0000-4000-8000-000000000002','00000000-0000-4000-8000-000000000002',id,'Active','AdminRecovery',UTC_TIMESTAMP(6)
FROM installations WHERE installation_uuid='10000000-0000-4000-8000-000000000002'
ON DUPLICATE KEY UPDATE binding_state='Active',activated_at=VALUES(activated_at),deactivated_at=NULL;

INSERT INTO license_device_bindings(
    binding_id, license_id, customer_id, installation_id, binding_state,
    activation_method, activated_at
)
SELECT '30000000-0000-4000-8000-000000000003','20000000-0000-4000-8000-000000000003','00000000-0000-4000-8000-000000000003',id,'Active','AdminRecovery',UTC_TIMESTAMP(6)
FROM installations WHERE installation_uuid='10000000-0000-4000-8000-000000000003'
ON DUPLICATE KEY UPDATE binding_state='Active',activated_at=VALUES(activated_at),deactivated_at=NULL;

INSERT INTO license_device_bindings(
    binding_id, license_id, customer_id, installation_id, binding_state,
    activation_method, activated_at
)
SELECT '30000000-0000-4000-8000-000000000004','20000000-0000-4000-8000-000000000004','00000000-0000-4000-8000-000000000004',id,'Active','AdminRecovery',UTC_TIMESTAMP(6)
FROM installations WHERE installation_uuid='10000000-0000-4000-8000-000000000004'
ON DUPLICATE KEY UPDATE binding_state='Active',activated_at=VALUES(activated_at),deactivated_at=NULL;

INSERT INTO license_device_bindings(
    binding_id, license_id, customer_id, installation_id, binding_state,
    activation_method, activated_at
)
SELECT '30000000-0000-4000-8000-000000000005','20000000-0000-4000-8000-000000000005','00000000-0000-4000-8000-000000000005',id,'Active','AdminRecovery',UTC_TIMESTAMP(6)
FROM installations WHERE installation_uuid='10000000-0000-4000-8000-000000000005'
ON DUPLICATE KEY UPDATE binding_state='Active',activated_at=VALUES(activated_at),deactivated_at=NULL;
