-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/GlobalBlocking/sql/abstractSchemaChanges/patch-modify-gb_by-default.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE globalblocks
  ALTER gb_by
SET
  DEFAULT '';
ALTER TABLE globalblocks
  ALTER gb_by_central_id
SET
  NOT NULL;
