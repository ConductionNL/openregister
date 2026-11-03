import re, sys
p = 'lib/Service/Object/RelationHandler.php'
s = open(p).read()
S = '@spec openspec/specs/linked-entity-types/spec.md'

def body(name):
    start = s.index('\tpublic function %s(' % name)
    open_ = s.index('\t): array {\n', start) + len('\t): array {\n')
    end = s.index('\t}//end %s()' % name)
    return open_, end

uses_body = '''		try {
			// Register and schema are loaded without RBAC/multitenancy: the user's
			// access to the related objects is checked by filterByRbac() below.
			$pinned = $this->pinnedTable(registerId: $_registerId, schemaId: $_schemaId);
			if ($pinned->failure !== null) {
				$this->logger->debug(
					message: '[RelationHandler::getUses] Could not load register/schema for magic table lookup',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'registerId' => $_registerId,
						'schemaId' => $_schemaId,
						'error' => $pinned->failure->getMessage(),
					]
				);
			}

			$object = $this->objectEntityMapper->find(
				identifier: $objectId,
				register: $pinned->register,
				schema: $pinned->schema
			);

			// Pre-scanned relations, populated during save/import by scanForRelations().
			$relations = $object->getRelations() ?? [];
			$uniqueIds = $this->relatedUuids(relations: $relations, ownUuid: $object->getUuid());
			if (empty($uniqueIds) === true) {
				return $this->emptyPage(query: $query);
			}

			$relatedObjects = $this->locateRelated(uuids: $uniqueIds);

			// The per-table reads apply RBAC at query level, but the fallback rows
			// were located without it, so every result is filtered here.
			if ($_rbac === true) {
				$relatedObjects = $this->filterByRbac(objects: $relatedObjects);
			}

			$this->logger->debug(
				message: '[RelationHandler::getUses] Found related objects',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'searchedIds' => $uniqueIds,
					'foundCount' => count($relatedObjects),
				]
			);

			$limit = $query['_limit'] ?? 30;
			$offset = $query['_offset'] ?? 0;
			$total = count($relatedObjects);
			$page = array_slice($relatedObjects, $offset, $limit);

			// Rows leave serialised because the relation descriptor has nowhere
			// to live on an entity; ObjectsController::stampObjectUrls() accepts either shape.
			$source = new RelationSource(
				relations: $relations,
				schema: ($pinned->schema ?? $this->schemaOf(schemaId: $object->getSchema()))
			);

			return [
				'results' => array_map(fn ($related) => $this->outgoingRow(related: $related, source: $source), $page),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			];
		} catch (\\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get uses',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'objectId' => $objectId,
				]
			);
			return $this->emptyPage(query: $query);
		}//end try
'''

uses_helpers = f'''	}}//end getUses()

	/**
	 * Load the register and schema a lookup was pinned to, without RBAC or multitenancy.
	 *
	 * Nothing is loaded unless both ids are given. Loading stops at the first failure,
	 * which is kept on the result for the caller to log.
	 *
	 * @param int|null $registerId The register id.
	 * @param int|null $schemaId   The schema id.
	 *
	 * @return PinnedTable
	 *
	 * {S}
	 */
	private function pinnedTable(?int $registerId, ?int $schemaId): PinnedTable {{
		$pinned = new PinnedTable();
		if ($registerId === null || $schemaId === null) {{
			return $pinned;
		}}

		try {{
			$pinned->register = $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
			$pinned->schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
		}} catch (\\Exception $e) {{
			$pinned->failure = $e;
		}}

		return $pinned;
	}}//end pinnedTable()

	/**
	 * An empty page that echoes the requested limit and offset.
	 *
	 * @param array<string, mixed> $query The request's paging parameters.
	 *
	 * @return array{{results: array<never, never>, total: 0, limit: mixed, offset: mixed}}
	 *
	 * {S}
	 */
	private function emptyPage(array $query): array {{
		return [
			'results' => [],
			'total' => 0,
			'limit' => $query['_limit'] ?? 30,
			'offset' => $query['_offset'] ?? 0,
		];
	}}//end emptyPage()

	/**
	 * The distinct uuids a relation map names, without the object's own uuid.
	 *
	 * Relations are stored as ['field' => 'uuid'] or as lists of uuids; empty
	 * strings and non-string values are skipped. Keys are kept as array_unique() leaves them.
	 *
	 * @param array<mixed, mixed> $relations The stored relation map.
	 * @param string|null         $ownUuid   The object's own uuid.
	 *
	 * @return array<int, string>
	 *
	 * {S}
	 */
	private function relatedUuids(array $relations, ?string $ownUuid): array {{
		$ids = [];
		foreach ($relations as $value) {{
			$candidates = [$value];
			if (is_array($value) === true) {{
				$candidates = $value;
			}}

			foreach ($candidates as $candidate) {{
				if (is_string($candidate) === true && empty($candidate) === false) {{
					$ids[] = $candidate;
				}}
			}}
		}}

		$uniqueIds = array_unique($ids);
		if ($ownUuid !== null) {{
			$uniqueIds = array_filter($uniqueIds, fn ($id) => $id !== $ownUuid);
		}}

		return $uniqueIds;
	}}//end relatedUuids()

	/**
	 * Load the related objects: locate them in one UNION query, then read each table.
	 *
	 * The UNION replaced loading every register and schema and querying the tables
	 * one by one (/uses on a pipelinq lead took 4.5 to 7 seconds). Each table that
	 * holds a match is read through the filtered query, and a uuid no table read
	 * returns falls back to the located row.
	 *
	 * @param array<mixed, string> $uuids The related uuids.
	 *
	 * @return ObjectEntity[]
	 *
	 * {S}
	 */
	private function locateRelated(array $uuids): array {{
		$located = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(uuids: array_values($uuids));
		$found = new RelatedObjectSet(wanted: $uuids);
		foreach ($this->tablesOf(objects: $located) as $table) {{
			$this->readRelatedTable(table: $table, found: $found);
		}}

		return $found->withFallback(located: $located);
	}}//end locateRelated()

	/**
	 * Read one table's related objects into the set; a table that cannot be read is skipped.
	 *
	 * @param array{{registerId: int, schemaId: int, uuids: array<int, string>}} $table The table and its uuids.
	 * @param RelatedObjectSet                                                $found The set being filled.
	 *
	 * @return void
	 *
	 * {S}
	 */
	private function readRelatedTable(array $table, RelatedObjectSet $found): void {{
		$pair = $this->registerSchemaPair(registerId: $table['registerId'], schemaId: $table['schemaId']);
		if ($pair === null) {{
			return;
		}}

		try {{
			$results = $this->objectEntityMapper->findAllInRegisterSchemaTable(
				register: $pair['register'],
				schema: $pair['schema'],
				filters: ['_ids' => $table['uuids'], '_limit' => 200]
			);
			foreach ($results as $obj) {{
				$found->offer(object: $obj);
			}}
		}} catch (\\Exception $e) {{
			// Table might not exist or query failed, continue.
		}}
	}}//end readRelatedTable()

	/**
	 * One related object as a serialised row carrying its outgoing relation block.
	 *
	 * @param mixed          $related The related object.
	 * @param RelationSource $source  The source object's relation map and schema.
	 *
	 * @return mixed The row, or the object itself when it does not serialise to an array.
	 *
	 * {S}
	 */
	private function outgoingRow(mixed $related, RelationSource $source): mixed {{
		$row = $related;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {{
			$row = $row->jsonSerialize();
		}}

		if (is_array($row) === false) {{
			return $related;
		}}

		return $this->withRelation(
			row: $row,
			link: RelationLink::outgoing(
				schema: $source->schema,
				path: $this->pathHolding(relations: $source->relations, uuid: (string)$related->getUuid())
			)
		);
	}}//end outgoingRow()
'''

used_body = '''		try {
			// Register and schema are loaded without RBAC/multitenancy; access to
			// the referrers is applied by the per-table reads.
			$pinned = $this->pinnedTable(registerId: $_registerId, schemaId: $_schemaId);
			if ($pinned->failure !== null) {
				$this->logger->warning(
					message: '[RelationHandler] Failed to load register/schema for getUsedBy magic table support',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $pinned->failure->getMessage()]
				);
			}

			$object = $this->objectEntityMapper->find(
				identifier: $objectId,
				register: $pinned->register,
				schema: $pinned->schema
			);
			$page = new ReferrerPage(targetUuid: $object->getUuid(), query: $query);

			// One UNION query says which tables hold an object whose _relations name
			// this uuid. It replaced a JSON query in every magic table (/used on a
			// pipelinq lead took 6.4 to 8 seconds).
			$tables = $this->tablesOf(
				objects: $this->objectEntityMapper->findByRelationAcrossAllMagicTables(uuid: (string)$page->targetUuid)
			);
			foreach ($tables as $tableInfo) {
				if ($page->isFull() === true) {
					break;
				}

				$this->readReferrerTable(table: $tableInfo, page: $page);
			}

			return [
				'results' => $page->rows(),
				'total' => count($page->rows()),
				'limit' => $page->limit,
				'offset' => $page->offset,
			];
		} catch (\\Exception $e) {
			$this->logger->error(
				message: '[RelationHandler] Failed to get used by',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'objectId' => $objectId,
				]
			);
			return $this->emptyPage(query: $query);
		}//end try
'''

used_helpers = f'''	}}//end getUsedBy()

	/**
	 * Read one table's referrers into the page, newest first.
	 *
	 * Each referrer's own relation map says which of ITS properties holds the uuid,
	 * and that property lives on ITS schema, so the label is resolved from the table's
	 * schema rather than from the schema of the object being read. A table that cannot
	 * be read is logged and skipped.
	 *
	 * @param array{{registerId: int, schemaId: int, uuids: array<int, string>}} $table The table.
	 * @param ReferrerPage                                                    $page  The page being filled.
	 *
	 * @return void
	 *
	 * {S}
	 */
	private function readReferrerTable(array $table, ReferrerPage $page): void {{
		try {{
			$pair = $this->registerSchemaPair(registerId: $table['registerId'], schemaId: $table['schemaId']);
			if ($pair === null) {{
				return;
			}}

			$searchResults = $this->objectEntityMapper->findAllInRegisterSchemaTable(
				register: $pair['register'],
				schema: $pair['schema'],
				limit: $page->remaining(),
				offset: $page->nextOffset(),
				filters: ['_relations_contains' => $page->targetUuid],
				sort: ['_updated' => 'DESC']
			);

			foreach ($searchResults as $resultObject) {{
				if ($resultObject->getUuid() === $page->targetUuid) {{
					continue;
				}}

				$page->add(
					row: $this->withRelation(
						row: $resultObject->jsonSerialize(),
						link: RelationLink::incoming(
							schema: $pair['schema'],
							path: $this->pathHolding(
								relations: ($resultObject->getRelations() ?? []),
								uuid: (string)$page->targetUuid
							)
						)
					)
				);
			}}

			$page->countRead(count: count($searchResults));
		}} catch (\\Exception $e) {{
			$this->logger->debug(
				message: '[RelationHandler] Error searching magic table for usedBy',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'table' => $table['registerId'].'_'.$table['schemaId'],
					'error' => $e->getMessage(),
				]
			);
		}}//end try
	}}//end readReferrerTable()
'''

a, b = body('getUsedBy')
s = s[:a] + used_body + used_helpers + s[b + len('\t}//end getUsedBy()'):]
a, b = body('getUses')
s = s[:a] + uses_body + uses_helpers + s[b + len('\t}//end getUses()'):]

old_wr = s[s.index('\t * @param array<string, mixed> $row The serialised object.'):s.index('\t}//end withRelation()')]
new_wr = '''	 * @param array<string, mixed> $row  The serialised object.
	 * @param RelationLink         $link The declaring schema, stored path and direction.
	 *
	 * @return array<string, mixed> The row, with `relation` attached.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private function withRelation(array $row, RelationLink $link): array {
		if ($this->relationTypes === null) {
			return $row;
		}

		$descriptor = null;
		if ($link->path !== null) {
			$descriptor = $this->relationTypes->descriptorFor(schema: $link->schema, property: $link->path);
		}

		$row['relation'] = $this->relationTypes->row(
			descriptor: $descriptor,
			direction: $link->direction,
			path: $link->path
		);

		return $row;
'''
assert '$direction One of' in old_wr
s = s.replace(old_wr, new_wr)
open(p, 'w').write(s)
