-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: sql/abstractschemachanges/patch-global_block_whitelist-timestamps.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  global_block_whitelist ALTER gbw_expiry TYPE TIMESTAMPTZ;
ALTER TABLE  global_block_whitelist ALTER gbw_expiry
DROP  DEFAULT;
ALTER TABLE  global_block_whitelist ALTER gbw_expiry TYPE TIMESTAMPTZ;