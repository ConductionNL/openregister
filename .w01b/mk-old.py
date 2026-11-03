import json
M = [
 ("blank string kept", "if (is_string($value) === true && empty($value) === false) {\n\t\t\t\t\t$relationshipIds[] = $value;", "if (is_string($value) === true) {\n\t\t\t\t\t$relationshipIds[] = $value;"),
 ("blank sub kept", "if (is_string($subValue) === true && empty($subValue) === false) {", "if (is_string($subValue) === true) {"),
 ("lists ignored", "} elseif (is_array($value) === true) {", "} elseif (false) {"),
 ("self kept", "if ($objectUuid !== null) {\n\t\t\t\t$uniqueIds", "if (false) {\n\t\t\t\t$uniqueIds"),
 ("second empty check off", "// Re-check if we have any IDs left after filtering.\n\t\t\tif (empty($uniqueIds) === true) {", "// Re-check if we have any IDs left after filtering.\n\t\t\tif (false) {"),
 ("unasked kept", "if (in_array($uuid, $uniqueIds, true) === true && in_array($uuid, $foundUuids, true) === false) {", "if (in_array($uuid, $foundUuids, true) === false) {"),
 ("repeats kept", "if (in_array($uuid, $uniqueIds, true) === true && in_array($uuid, $foundUuids, true) === false) {", "if (in_array($uuid, $uniqueIds, true) === true) {"),
 ("found not marked", "\t$foundUuids[] = $uuid;", "\t$foundUuids[] = 'none';"),
 ("table limit", "filters: ['_ids' => $table['uuids'], '_limit' => 200]", "filters: ['_ids' => $table['uuids'], '_limit' => 100]"),
 ("no fallback", "if (empty($missingUuids) === false) {", "if (false) {"),
 ("fallback inverted", "if (in_array($candidate->getUuid(), $missingUuids, true) === true) {", "if (in_array($candidate->getUuid(), $missingUuids, true) === false) {"),
 ("rbac always", "if ($_rbac === true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac", "if (true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac"),
 ("rbac never", "if ($_rbac === true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac", "if (false) {\n\t\t\t\t$relatedObjects = $this->filterByRbac"),
 ("admin skip off", "if ($this->rbacHandler->isAdmin() === true) {\n\t\t\treturn $objects;", "if (false) {\n\t\t\treturn $objects;"),
 ("forged _owner", "$objectData['_owner'] = $object->getOwner();", ""),
 ("forged _organisation", "$objectData['_organisation'] = $object->getOrganisation();", ""),
 ("owner from session", "objectOwner: $object->getOwner(),", "objectOwner: $this->rbacHandler->getCurrentUserId(),"),
 ("draft rule for owner", "userId: $this->rbacHandler->getCurrentUserId(),\n\t\t\t\tisAdmin: false", "userId: $object->getOwner(),\n\t\t\t\tisAdmin: false"),
 ("draft rule off", "isAdmin: false\n\t\t\t) === true", "isAdmin: true\n\t\t\t) === true"),
 ("uses default limit", "$limit = $query['_limit'] ?? 30;\n\t\t\t$offset = $query['_offset'] ?? 0;\n\t\t\t$total = count", "$limit = $query['_limit'] ?? 20;\n\t\t\t$offset = $query['_offset'] ?? 0;\n\t\t\t$total = count"),
 ("uses default offset", "$offset = $query['_offset'] ?? 0;\n\t\t\t$total = count", "$offset = $query['_offset'] ?? 1;\n\t\t\t$total = count"),
 ("uses no offset", "array_slice($relatedObjects, $offset, $limit)", "array_slice($relatedObjects, 0, $limit)"),
 ("total after slice", "$total = count($relatedObjects);\n\n\t\t\t$relatedObjects = array_slice($relatedObjects, $offset, $limit);", "$relatedObjects = array_slice($relatedObjects, $offset, $limit);\n\t\t\t$total = count($relatedObjects);"),
 ("source schema always looked up", "if ($sourceSchema === null) {", "if (true) {"),
 ("uses direction", "direction: RelationTypeResolver::DIRECTION_OUTGOING", "direction: RelationTypeResolver::DIRECTION_INCOMING"),
 ("uses pinned rbac", "$register = $registerMapper->find($_registerId, _rbac: false, _multitenancy: false);\n\t\t\t\t\t$schema = $this->schemaMapper->find($_schemaId, _rbac: false, _multitenancy: false);\n\t\t\t\t} catch (\\Exception $e) {\n\t\t\t\t\t$this->logger->debug(", "$register = $registerMapper->find($_registerId, _rbac: true, _multitenancy: false);\n\t\t\t\t\t$schema = $this->schemaMapper->find($_schemaId, _rbac: false, _multitenancy: false);\n\t\t\t\t} catch (\\Exception $e) {\n\t\t\t\t\t$this->logger->debug("),
 ("uses pin needs both", "if ($_registerId !== null && $_schemaId !== null) {\n\t\t\t\ttry {\n\t\t\t\t\t$registerMapper = $this->registerMapper;\n\t\t\t\t\t$register = $registerMapper->find($_registerId, _rbac: false, _multitenancy: false);\n\t\t\t\t\t$schema = $this->schemaMapper->find($_schemaId, _rbac: false, _multitenancy: false);\n\t\t\t\t} catch (\\Exception $e) {\n\t\t\t\t\t$this->logger->debug(", "if ($_registerId !== null) {\n\t\t\t\ttry {\n\t\t\t\t\t$registerMapper = $this->registerMapper;\n\t\t\t\t\t$register = $registerMapper->find($_registerId, _rbac: false, _multitenancy: false);\n\t\t\t\t\t$schema = $this->schemaMapper->find((int)$_schemaId, _rbac: false, _multitenancy: false);\n\t\t\t\t} catch (\\Exception $e) {\n\t\t\t\t\t$this->logger->debug("),
 ("uses error limit", "'message' => '[RelationHandler] Failed to get uses',", "'message' => '[RelationHandler] Failed to get uses!',"),
 ("usedBy break at >", "if (count($results) >= $limit) {", "if (count($results) > $limit) {"),
 ("usedBy full limit", "limit: $limit - count($results),", "limit: $limit,"),
 ("usedBy offset", "offset: max(0, $offset - $totalResults),", "offset: $offset,"),
 ("usedBy self kept", "if ($resultObject->getUuid() === $targetUuid) {\n\t\t\t\t\t\t\tcontinue;", "if ($resultObject->getUuid() === null) {\n\t\t\t\t\t\t\tcontinue;"),
 ("usedBy total counter", "$totalResults += count($searchResults);", "$totalResults += 0;"),
 ("usedBy total", "'total' => count($results),", "'total' => $totalResults,"),
 ("usedBy sort", "sort: ['_updated' => 'DESC']", "sort: ['_updated' => 'ASC']"),
 ("usedBy direction", "direction: RelationTypeResolver::DIRECTION_INCOMING", "direction: RelationTypeResolver::DIRECTION_OUTGOING"),
 ("usedBy pinned warning", "$this->logger->warning(\n\t\t\t\t\t\tmessage: '[RelationHandler] Failed to load register/schema for getUsedBy", "$this->logger->debug(\n\t\t\t\t\t\tmessage: '[RelationHandler] Failed to load register/schema for getUsedBy"),
 ("usedBy table schema", "schema: $tableSchema,\n\t\t\t\t\t\t\tpath:", "schema: $schema,\n\t\t\t\t\t\t\tpath:"),
 ("usedBy pair null", "if ($pair === null) {\n\t\t\t\t\t\tcontinue;\n\t\t\t\t\t}\n\n\t\t\t\t\t$tableRegister", "if ($pair === null) {\n\t\t\t\t\t\tbreak;\n\t\t\t\t\t}\n\n\t\t\t\t\t$tableRegister"),
 ("usedBy error offset", "'error' => $e->getMessage(),\n\t\t\t\t\t'objectId' => $objectId,\n\t\t\t\t]\n\t\t\t);\n\t\t\treturn [\n\t\t\t\t'results' => [],\n\t\t\t\t'total' => 0,\n\t\t\t\t'limit' => $query['_limit'] ?? 30,\n\t\t\t\t'offset' => $query['_offset'] ?? 0,\n\t\t\t];\n\t\t}//end try\n\t}//end getUsedBy()", "'error' => $e->getMessage(),\n\t\t\t\t\t'objectId' => $objectId,\n\t\t\t\t]\n\t\t\t);\n\t\t\treturn [\n\t\t\t\t'results' => [],\n\t\t\t\t'total' => 0,\n\t\t\t\t'limit' => $query['_limit'] ?? 30,\n\t\t\t\t'offset' => 0,\n\t\t\t];\n\t\t}//end try\n\t}//end getUsedBy()"),
 ("path index", "return $path.'.'.((string)$index);", "return $path;"),
]
# fix the message mutant: log message is passed as named arg, not array key
M = [m if m[0] != "uses error limit" else ("uses error message", "message: '[RelationHandler] Failed to get uses',", "message: '[RelationHandler] Failed to get uses!',") for m in M]
import sys
src = open(sys.argv[1]).read()
for l,a,b in M:
    c = src.count(a)
    if c != 1: print("COUNT", c, l)
json.dump(M, open(sys.argv[2], "w"))
print(len(M), "mutants")
