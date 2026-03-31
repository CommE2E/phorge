ALTER TABLE {$NAMESPACE}_harbormaster.harbormaster_buildtarget
  ADD COLUMN `externalSystem` VARCHAR(32) COLLATE {$COLLATE_TEXT};
