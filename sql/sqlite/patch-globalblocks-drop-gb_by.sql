-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/GlobalBlocking/sql/abstractSchemaChanges/patch-globalblocks-drop-gb_by.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TEMPORARY TABLE /*_*/__temp__globalblocks AS
SELECT
  gb_id,
  gb_address,
  gb_target_central_id,
  gb_by_central_id,
  gb_by_wiki,
  gb_reason,
  gb_timestamp,
  gb_anon_only,
  gb_expiry,
  gb_range_start,
  gb_range_end
FROM /*_*/globalblocks;
DROP TABLE /*_*/globalblocks;


CREATE TABLE /*_*/globalblocks (
    gb_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    gb_address VARCHAR(255) NOT NULL,
    gb_target_central_id INTEGER UNSIGNED DEFAULT 0 NOT NULL,
    gb_by_central_id INTEGER UNSIGNED NOT NULL,
    gb_by_wiki BLOB NOT NULL,
    gb_reason BLOB NOT NULL,
    gb_timestamp BLOB NOT NULL,
    gb_anon_only SMALLINT DEFAULT 0 NOT NULL,
    gb_expiry BLOB NOT NULL,
    gb_range_start BLOB NOT NULL,
    gb_range_end BLOB NOT NULL
  );
INSERT INTO /*_*/globalblocks (
    gb_id, gb_address, gb_target_central_id,
    gb_by_central_id, gb_by_wiki, gb_reason,
    gb_timestamp, gb_anon_only, gb_expiry,
    gb_range_start, gb_range_end
  )
SELECT
  gb_id,
  gb_address,
  gb_target_central_id,
  gb_by_central_id,
  gb_by_wiki,
  gb_reason,
  gb_timestamp,
  gb_anon_only,
  gb_expiry,
  gb_range_start,
  gb_range_end
FROM
  /*_*/__temp__globalblocks;
DROP TABLE /*_*/__temp__globalblocks;

CREATE UNIQUE INDEX gb_address ON /*_*/globalblocks (gb_address, gb_anon_only);

CREATE INDEX gb_target_central_id ON /*_*/globalblocks (gb_target_central_id);

CREATE INDEX gb_range ON /*_*/globalblocks (gb_range_start, gb_range_end);

CREATE INDEX gb_timestamp ON /*_*/globalblocks (gb_timestamp);

CREATE INDEX gb_expiry ON /*_*/globalblocks (gb_expiry);
