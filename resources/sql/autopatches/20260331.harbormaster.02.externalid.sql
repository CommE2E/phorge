ALTER TABLE {$NAMESPACE}_harbormaster.harbormaster_buildtarget
  ADD COLUMN `externalID` VARCHAR(64) COLLATE {$COLLATE_TEXT};
