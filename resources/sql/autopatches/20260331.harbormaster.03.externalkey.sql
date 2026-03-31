ALTER TABLE {$NAMESPACE}_harbormaster.harbormaster_buildtarget
  ADD KEY `key_external` (`externalSystem`, `externalID`);
