import json, sys
M = [
 ("src register missing ignored", "if ($run->source->isLoaded() === false || $run->target->isLoaded() === false) {", "if ($run->target->isLoaded() === false) {"),
 ("tgt missing ignored", "if ($run->source->isLoaded() === false || $run->target->isLoaded() === false) {", "if ($run->source->isLoaded() === false) {"),
 ("isLoaded register", "return $this->registerEntity !== null && $this->schemaEntity !== null;", "return $this->schemaEntity !== null;"),
 ("isLoaded schema", "return $this->registerEntity !== null && $this->schemaEntity !== null;", "return $this->registerEntity !== null;"),
 ("load order", "foreach ([$run->source, $run->target] as $side) {", "foreach ([$run->target, $run->source] as $side) {"),
 ("side swapped", "$this->target = new MigrationSide(register: $target[0], schema: $target[1]);", "$this->target = new MigrationSide(register: $target[0], schema: $target[0]);"),
 ("schema loaded as register", "normalizeEntity(entity: $side->schema, type: 'schema')", "normalizeEntity(entity: $side->schema, type: 'register')"),
 ("remaining by uuid only", "fn ($id) => $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId()", "fn ($id) => $id !== $sourceObject->getUuid()"),
 ("remaining by id only", "fn ($id) => $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId()", "fn ($id) => $id !== $sourceObject->getId()"),
 ("remaining loose", "fn ($id) => $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId()", "fn ($id) => $id != $sourceObject->getUuid() && $id != $sourceObject->getId()"),
 ("title uuid fallback", "$objectDetail['objectTitle'] = $sourceObject->getName() ?? $sourceObject->getUuid();", "$objectDetail['objectTitle'] = $sourceObject->getName();"),
 ("check off", "$this->assertInSource(sourceObject: $sourceObject, run: $run);", ""),
 ("check against target", "$source = $run->source;", "$source = $run->target;"),
 ("register check off", "if ((int)$actualRegister === (int)$source->register && (int)$actualSchema", "if (true && (int)$actualSchema"),
 ("schema check off", "&& (int)$actualSchema === (int)$source->schema) {", ") {"),
 ("check against entity id", "(int)$actualSchema === (int)$source->schema)", "(int)$actualSchema === (int)$source->schemaEntity->getId())"),
 ("mapped count", "$this->report['statistics']['propertiesMapped'] += count($mappedData);", "$this->report['statistics']['propertiesMapped'] += 1;"),
 ("discarded count", "+= (count($sourceData) - count($mappedData));", "+= count($sourceData);"),
 ("raw data kept", "$sourceObject->setObject($mappedData);", "$sourceObject->setObject($sourceData);"),
 ("register from request", "$sourceObject->setRegister($run->target->registerEntity->getId());", "$sourceObject->setRegister($run->target->register);"),
 ("schema not moved", "$sourceObject->setSchema($run->target->schemaEntity->getId());", ""),
 ("new id from source", "$objectDetail['newObjectId'] = $savedObject->getUuid();", "$objectDetail['newObjectId'] = $objectId;"),
 ("migrated not counted", "$this->report['statistics']['objectsMigrated']++;", ""),
 ("success not set", "$objectDetail['success'] = true;", ""),
 ("failed not counted", "$this->report['statistics']['objectsFailed']++;", ""),
 ("error line", "$this->report['errors'][] = \"Failed to migrate object {$objectId}: \" . $reason;", ""),
 ("not found skipped", "foreach ($remainingObjectIds as $notFoundId) {", "foreach ([] as $notFoundId) {"),
 ("not found reason", "$this->failed(objectId: $notFoundId, reason: 'Object not found');", "$this->failed(objectId: $notFoundId, reason: 'gone');"),
 ("success always", "$report['success'] = $report['statistics']['objectsMigrated'] > 0;", "$report['success'] = true;"),
 ("warning always", "if ($report['statistics']['objectsFailed'] > 0) {", "if (true) {"),
 ("debug log", "message: '[MigrationHandler] Object properties mapped',", "message: '[MigrationHandler] Object properties mapped!',"),
 ("mapping not passed", "mapping: $run->mapping\n", "mapping: []\n"),
 ("mapping not set", "$run->mapping = $mapping;", ""),
 ("detail not added", "$run->addDetail(detail: $objectDetail);", ""),
]
files=sys.argv[1:]; src={f:open(f).read() for f in files}; out={}
for l,a,b in M:
    tot=sum(src[f].count(a) for f in files)
    if tot!=1: print("COUNT",tot,l); continue
    f=[f for f in files if src[f].count(a)==1][0]; out.setdefault(f,[]).append([l,a,b])
for f,ms in out.items(): json.dump(ms,open('.w01b/m-mig-new-'+f.split('/')[-1]+'.json','w')); print(f,len(ms))
