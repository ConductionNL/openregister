import json, sys
M = [
 ("blank string kept", "if (is_string($candidate) === true && empty($candidate) === false) {", "if (is_string($candidate) === true) {"),
 ("lists ignored", "if (is_array($value) === true) {\n\t\t\t\t$candidates = $value;", "if (false) {\n\t\t\t\t$candidates = $value;"),
 ("self kept", "if ($ownUuid !== null) {", "if (false) {"),
 ("empty check off", "if (empty($uniqueIds) === true) {\n\t\t\t\treturn $this->emptyPage(query: $query);", "if (false) {\n\t\t\t\treturn $this->emptyPage(query: $query);"),
 ("unasked kept", "if (in_array($uuid, $this->wanted, true) === true && in_array($uuid, $this->foundUuids, true) === false) {", "if (in_array($uuid, $this->foundUuids, true) === false) {"),
 ("repeats kept", "if (in_array($uuid, $this->wanted, true) === true && in_array($uuid, $this->foundUuids, true) === false) {", "if (in_array($uuid, $this->wanted, true) === true) {"),
 ("found not marked", "$this->foundUuids[] = $uuid;", "$this->foundUuids[] = 'none';"),
 ("table limit", "'_limit' => 200]", "'_limit' => 100]"),
 ("no fallback", "$objects = $this->objects;\n\t\tforeach ($located as $candidate) {", "return $this->objects;\n\t\tforeach ($located as $candidate) {"),
 ("fallback inverted", "if (in_array($candidate->getUuid(), $missingUuids, true) === true) {", "if (in_array($candidate->getUuid(), $missingUuids, true) === false) {"),
 ("pair null read anyway", "if ($pair === null) {\n\t\t\treturn;\n\t\t}\n\n\t\ttry {", "if ($pair === null && false) {\n\t\t\treturn;\n\t\t}\n\n\t\ttry {"),
 ("rbac always", "if ($_rbac === true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac", "if (true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac"),
 ("rbac never", "if ($_rbac === true) {\n\t\t\t\t$relatedObjects = $this->filterByRbac", "if (false) {\n\t\t\t\t$relatedObjects = $this->filterByRbac"),
 ("admin skip off", "if ($this->rbacHandler->isAdmin() === true) {\n\t\t\treturn $objects;", "if (false) {\n\t\t\treturn $objects;"),
 ("forged _owner", "$objectData['_owner'] = $object->getOwner();", ""),
 ("forged _organisation", "$objectData['_organisation'] = $object->getOrganisation();", ""),
 ("owner from session", "objectOwner: $object->getOwner(),", "objectOwner: $this->rbacHandler->getCurrentUserId(),"),
 ("draft rule for owner", "userId: $this->rbacHandler->getCurrentUserId(),\n\t\t\t\tisAdmin: false", "userId: $object->getOwner(),\n\t\t\t\tisAdmin: false"),
 ("draft rule off", "isAdmin: false\n\t\t\t) === true", "isAdmin: true\n\t\t\t) === true"),
 ("uses default limit", "$limit = $query['_limit'] ?? 30;\n\t\t\t$offset = $query['_offset'] ?? 0;\n\t\t\t$total", "$limit = $query['_limit'] ?? 20;\n\t\t\t$offset = $query['_offset'] ?? 0;\n\t\t\t$total"),
 ("uses default offset", "$offset = $query['_offset'] ?? 0;\n\t\t\t$total", "$offset = $query['_offset'] ?? 1;\n\t\t\t$total"),
 ("uses no offset", "array_slice($relatedObjects, $offset, $limit)", "array_slice($relatedObjects, 0, $limit)"),
 ("total after slice", "'total' => $total,", "'total' => count($page),"),
 ("source schema always looked up", "schema: ($pinned->schema ?? $this->schemaOf(schemaId: $object->getSchema()))", "schema: $this->schemaOf(schemaId: $object->getSchema())"),
 ("uses direction", "link: RelationLink::outgoing(", "link: RelationLink::incoming("),
 ("outgoing factory direction", "$link->direction = RelationTypeResolver::DIRECTION_OUTGOING;", "$link->direction = RelationTypeResolver::DIRECTION_INCOMING;"),
 ("pinned rbac", "$pinned->register = $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);", "$pinned->register = $this->registerMapper->find($registerId, _rbac: true, _multitenancy: false);"),
 ("pinned multitenancy", "$pinned->schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);", "$pinned->schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: true);"),
 ("pin needs both", "if ($registerId === null || $schemaId === null) {", "if ($registerId === null) {"),
 ("failure not kept", "$pinned->failure = $e;", ""),
 ("empty page limit", "'limit' => $query['_limit'] ?? 30,\n\t\t\t'offset' => $query['_offset'] ?? 0,\n\t\t];\n\t}//end emptyPage()", "'limit' => 30,\n\t\t\t'offset' => $query['_offset'] ?? 0,\n\t\t];\n\t}//end emptyPage()"),
 ("empty page offset", "'offset' => $query['_offset'] ?? 0,\n\t\t];\n\t}//end emptyPage()", "'offset' => 0,\n\t\t];\n\t}//end emptyPage()"),
 ("uses error message", "message: '[RelationHandler] Failed to get uses',", "message: '[RelationHandler] Failed to get uses!',"),
 ("usedBy break at >", "return count($this->rows) >= $this->limit;", "return count($this->rows) > $this->limit;"),
 ("usedBy full limit", "return $this->limit - count($this->rows);", "return $this->limit;"),
 ("usedBy offset", "return max(0, $this->offset - $this->read);", "return $this->offset;"),
 ("usedBy self kept", "if ($resultObject->getUuid() === $page->targetUuid) {", "if ($resultObject->getUuid() === null) {"),
 ("usedBy total counter", "$this->read += $count;", "$this->read += 0;"),
 ("usedBy total", "'total' => count($page->rows()),", "'total' => 0,"),
 ("usedBy sort", "sort: ['_updated' => 'DESC']", "sort: ['_updated' => 'ASC']"),
 ("usedBy direction", "link: RelationLink::incoming(", "link: RelationLink::outgoing("),
 ("incoming factory direction", "$link->direction = RelationTypeResolver::DIRECTION_INCOMING;", "$link->direction = RelationTypeResolver::DIRECTION_OUTGOING;"),
 ("usedBy pinned warning", "$this->logger->warning(\n\t\t\t\t\tmessage: '[RelationHandler] Failed to load register/schema for getUsedBy", "$this->logger->debug(\n\t\t\t\t\tmessage: '[RelationHandler] Failed to load register/schema for getUsedBy"),
 ("usedBy table schema", "schema: $pair['schema'],\n\t\t\t\t\t\t\tpath:", "schema: null,\n\t\t\t\t\t\t\tpath:"),
 ("usedBy pair null", "if ($pair === null) {\n\t\t\t\treturn;\n\t\t\t}\n\n\t\t\t$searchResults", "if ($pair === null) {\n\t\t\t\tthrow new \\Exception('x');\n\t\t\t}\n\n\t\t\t$searchResults"),
 ("usedBy page default limit", "$this->limit = $query['_limit'] ?? 30;", "$this->limit = $query['_limit'] ?? 31;"),
 ("usedBy page default offset", "$this->offset = $query['_offset'] ?? 0;", "$this->offset = $query['_offset'] ?? 1;"),
 ("usedBy table log", "message: '[RelationHandler] Error searching magic table for usedBy',", "message: '[RelationHandler] Error searching magic table for usedBy!',"),
 ("path index", "return $path.'.'.((string)$index);", "return $path;"),
 ("descriptor path null", "if ($link->path !== null) {", "if (true) {"),
]
files = sys.argv[1:]
src = {f: open(f).read() for f in files}
out = {}
for l, a, b in M:
    hits = [f for f in files if src[f].count(a) == 1]
    tot = sum(src[f].count(a) for f in files)
    if tot != 1: print("COUNT", tot, l); continue
    out.setdefault(hits[0], []).append([l, a, b])
for f, ms in out.items():
    json.dump(ms, open('.w01b/m-new-' + f.split('/')[-1] + '.json', 'w')); print(f, len(ms))
