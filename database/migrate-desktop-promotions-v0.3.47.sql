-- POS Printer Emulator v0.3.47
-- Adds encrypted entitlement recovery for idempotent desktop promotional-trial requests.

ALTER TABLE portal_promotions
    ADD COLUMN IF NOT EXISTS entitlement_token_ciphertext VARBINARY(768) NULL AFTER entitlement_token_hash,
    ADD COLUMN IF NOT EXISTS entitlement_token_nonce BINARY(12) NULL AFTER entitlement_token_ciphertext,
    ADD COLUMN IF NOT EXISTS entitlement_token_tag BINARY(16) NULL AFTER entitlement_token_nonce;
