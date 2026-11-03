p='lib/Service/Object/MigrationHandler.php'; s=open(p).read()
S='@spec openspec/specs/object-lifecycle/spec.md'
start=s.index('\t): array {\n', s.index('public function migrateObjects(')) + len('\t): array {\n')
end=s.index('\t}//end migrateObjects()')
new_body='''		$run = new MigrationRun(
			source: new MigrationSide(register: $sourceRegister, schema: $sourceSchema),
			target: new MigrationSide(register: $targetRegister, schema: $targetSchema)
		);
		$run->mapping = $mapping;

		$this->loadSide(side: $run->source);
		$this->loadSide(side: $run->target);
		if ($run->source->isLoaded() === false || $run->target->isLoaded() === false) {
			throw new OcpDoesNotExistException('One or more registers/schemas not found');
		}

		$sourceObjects = $this->objectMapper->findMultiple($objectIds);

		// An id stays here until an object with that uuid or id is returned, so whatever is
		// left was not found.
		$remainingObjectIds = $objectIds;
		foreach ($sourceObjects as $sourceObject) {
			$remainingObjectIds = array_filter(
				$remainingObjectIds,
				fn ($id) => $id !== $sourceObject->getUuid() && $id !== $sourceObject->getId()
			);
			$this->migrateOne(sourceObject: $sourceObject, run: $run);
		}

		foreach ($remainingObjectIds as $notFoundId) {
			$run->notFound(notFoundId: $notFoundId);
		}

		return $run->report();
'''
helpers=f'''	}}//end migrateObjects()

	/**
	 * Load the register and then the schema one end of a migration names.
	 *
	 * @param MigrationSide $side The end to load.
	 *
	 * @return void
	 *
	 * {S}
	 */
	private function loadSide(MigrationSide $side): void {{
		$side->registerEntity = $this->utilityHandler->normalizeEntity(entity: $side->register, type: 'register');
		$side->schemaEntity = $this->utilityHandler->normalizeEntity(entity: $side->schema, type: 'schema');
	}}//end loadSide()

	/**
	 * Move one object to the target register and schema with its mapped data, and report it.
	 *
	 * Any failure is recorded on the run and the next object is tried.
	 *
	 * @param ObjectEntity $sourceObject The object as loaded.
	 * @param MigrationRun $run          The migration.
	 *
	 * @return void
	 *
	 * {S}
	 */
	private function migrateOne(ObjectEntity $sourceObject, MigrationRun $run): void {{
		$objectId = $sourceObject->getUuid();
		$objectDetail = [
			'objectId' => $objectId,
			'objectTitle' => null,
			'success' => false,
			'error' => null,
		];

		try {{
			$objectDetail['objectTitle'] = $sourceObject->getName() ?? $sourceObject->getUuid();
			$this->assertInSource(sourceObject: $sourceObject, source: $run->source);

			$sourceData = $sourceObject->getObject();
			$mappedData = $this->dataManipHandler->mapObjectProperties(
				sourceData: $sourceData,
				mapping: $run->mapping
			);
			$run->countMapping(sourceData: $sourceData, mappedData: $mappedData);

			$this->logger->debug(
				message: '[MigrationHandler] Object properties mapped',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'mappedData' => $mappedData,
				]
			);

			// Files and relations stay attached to the object, so moving it moves them.
			$sourceObject->setRegister($run->target->registerEntity->getId());
			$sourceObject->setSchema($run->target->schemaEntity->getId());
			$sourceObject->setObject($mappedData);
			$savedObject = $this->objectMapper->update($sourceObject);

			$objectDetail['success'] = true;
			$objectDetail['newObjectId'] = $savedObject->getUuid();
			$run->migrated();
		}} catch (Exception $e) {{
			$objectDetail['error'] = $e->getMessage();
			$run->failed(objectId: $objectId, reason: $e->getMessage());
		}}//end try

		$run->addDetail(detail: $objectDetail);
	}}//end migrateOne()

	/**
	 * Refuse an object that does not live in the requested source register and schema.
	 *
	 * The stored register and schema are compared, as integers, with the values as
	 * requested, so a source named by slug never matches.
	 *
	 * @param ObjectEntity  $sourceObject The object as loaded.
	 * @param MigrationSide $source       The requested source.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the object lives elsewhere.
	 *
	 * {S}
	 */
	private function assertInSource(ObjectEntity $sourceObject, MigrationSide $source): void {{
		$actualRegister = $sourceObject->getRegister();
		$actualSchema = $sourceObject->getSchema();
		if ((int)$actualRegister === (int)$source->register && (int)$actualSchema === (int)$source->schema) {{
			return;
		}}

		$expected = "register='{{$source->register}}', schema='{{$source->schema}}'";
		$actual = "register='{{$actualRegister}}', schema='{{$actualSchema}}'";
		throw new InvalidArgumentException(
			sprintf(
				'Object %s does not belong to the specified source register/schema. Expected: %s. Actual: %s',
				$sourceObject->getUuid(),
				$expected,
				$actual
			)
		);
	}}//end assertInSource()
'''
s=s[:start]+new_body+helpers+s[end+len('\t}//end migrateObjects()'):]
s=s.replace("use OCA\\OpenRegister\\Db\\MagicMapper;\n","use OCA\\OpenRegister\\Db\\MagicMapper;\nuse OCA\\OpenRegister\\Db\\ObjectEntity;\n",1)
open(p,'w').write(s)
