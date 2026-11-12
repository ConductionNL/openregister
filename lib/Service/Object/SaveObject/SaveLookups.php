<?php

/**
 * OpenRegister SaveLookups
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Finds the schemas and registers a save names, and keeps them for the request.
 *
 * A cascade creates many sub-objects of the same type, so every entity and every
 * resolved reference is kept to avoid repeating the lookup.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveLookups {

	/**
	 * Schemas found, by id.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemaCache = [];

	/**
	 * Registers found, by id.
	 *
	 * @var array<string, Register>
	 */
	private array $registerCache = [];

	/**
	 * Schema ids found, by the reference that named them.
	 *
	 * @var array<string, string|null>
	 */
	private array $schemaReferenceCache = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface   $schemaMapper   Finds schemas.
	 * @param RegisterMapperInterface $registerMapper Finds registers.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()

	/**
	 * Forget everything kept so far.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clear(): void {
		$this->schemaCache = [];
		$this->registerCache = [];
		$this->schemaReferenceCache = [];
	}//end clear()

	/**
	 * Keep a schema the caller already holds.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function rememberSchema(Schema $schema): void {
		$this->schemaCache[(string)$schema->getId()] = $schema;
	}//end rememberSchema()

	/**
	 * Keep a register the caller already holds.
	 *
	 * @param Register $register The register.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function rememberRegister(Register $register): void {
		$this->registerCache[(string)$register->getId()] = $register;
	}//end rememberRegister()

	/**
	 * A schema by id, kept after the first lookup.
	 *
	 * @param int|string $schemaId The schema id.
	 *
	 * @return Schema The schema.
	 *
	 * @throws DoesNotExistException If the schema is not found.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function schema(int|string $schemaId): Schema {
		$cacheKey = (string)$schemaId;
		if (isset($this->schemaCache[$cacheKey]) === false) {
			$this->schemaCache[$cacheKey] = $this->schemaMapper->find(id: $schemaId);
		}

		return $this->schemaCache[$cacheKey];
	}//end schema()

	/**
	 * A register by id, kept after the first lookup.
	 *
	 * @param int|string $registerId The register id.
	 *
	 * @return Register The register.
	 *
	 * @throws DoesNotExistException If the register is not found.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function register(int|string $registerId): Register {
		$cacheKey = (string)$registerId;
		if (isset($this->registerCache[$cacheKey]) === false) {
			$this->registerCache[$cacheKey] = $this->registerMapper->find(id: $registerId);
		}

		return $this->registerCache[$cacheKey];
	}//end register()

	/**
	 * The id of the schema a reference names, or null.
	 *
	 * A reference is an id, a uuid, a slug, a path such as `#/components/schemas/Contact`
	 * or a URL. Path and URL references match on their last part, case-insensitive.
	 *
	 * @param string $reference The reference.
	 *
	 * @return null|numeric-string The schema id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function schemaId(string $reference): ?string {
		if (empty($reference) === true) {
			return null;
		}

		$text = new ReferenceText();
		$clean = $text->withoutQuery(reference: $reference);
		$slug = $text->lastSegment(reference: $clean);
		$schemaId = ($this->schemaReferenceCache[$reference] ?? $this->schemaReferenceCache[$clean]
			?? $this->schemaIdByIdentifier(identifier: $clean)
			?? $this->schemaIdFromAll(slug: $slug)
			?? $this->schemaIdBySlug(slug: $slug));
		$this->schemaReferenceCache[$reference] = $schemaId;

		return $schemaId;
	}//end schemaId()

	/**
	 * The id of the register a reference names, or null.
	 *
	 * A reference is an id, a uuid, a slug or a URL. A URL matches on its last part,
	 * case-insensitive.
	 *
	 * @param string $reference The reference.
	 *
	 * @return null|numeric-string The register id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function registerId(string $reference): ?string {
		return (new RegisterReferenceLookup(registerMapper: $this->registerMapper))->find(reference: $reference);
	}//end registerId()

	/**
	 * The schema a save names and its id.
	 *
	 * @param Schema|string|int $schema The schema, a reference to it, or its id.
	 *
	 * @return array{0: Schema, 1: int|string|null} The schema and its id as given or resolved.
	 *
	 * @throws Exception When a reference resolves to nothing.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function schemaWithId(Schema|string|int $schema): array {
		if ($schema instanceof Schema === true) {
			$this->rememberSchema(schema: $schema);

			return [$schema, $schema->getId()];
		}

		$schemaId = $this->requiredSchemaId(schema: $schema);

		return [$this->schema(schemaId: $schemaId), $schemaId];
	}//end schemaWithId()

	/**
	 * The id of a schema given by id or reference.
	 *
	 * @param string|int $schema The id or reference.
	 *
	 * @return string|int The id.
	 *
	 * @throws Exception When a reference resolves to nothing.
	 */
	private function requiredSchemaId(string|int $schema): string|int {
		if (is_string($schema) === false) {
			return $schema;
		}

		$schemaId = $this->schemaId(reference: $schema);
		if ($schemaId === null) {
			throw new Exception("Could not resolve schema reference: $schema");
		}

		return $schemaId;
	}//end requiredSchemaId()

	/**
	 * The register a save names and its id; null for both when it names none.
	 *
	 * @param Register|string|int|null $register The register, a reference to it, or its id.
	 *
	 * @return array{0: Register|null, 1: int|string|null} The register and its id as given or resolved.
	 *
	 * @throws Exception When a reference resolves to nothing.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function registerWithId(Register|string|int|null $register): array {
		if ($register === null) {
			return [null, null];
		}

		if ($register instanceof Register === true) {
			$this->rememberRegister(register: $register);

			return [$register, $register->getId()];
		}

		$registerId = $this->requiredRegisterId(register: $register);

		return [$this->register(registerId: $registerId), $registerId];
	}//end registerWithId()

	/**
	 * The id of a register given by id or reference.
	 *
	 * @param string|int $register The id or reference.
	 *
	 * @return string|int The id.
	 *
	 * @throws Exception When a reference resolves to nothing.
	 */
	private function requiredRegisterId(string|int $register): string|int {
		if (is_string($register) === false) {
			return $register;
		}

		$registerId = $this->registerId(reference: $register);
		if ($registerId === null) {
			throw new Exception("Could not resolve register reference: $register");
		}

		return $registerId;
	}//end requiredRegisterId()

	/**
	 * The id of the schema an id or uuid names; null for anything else or when it is not found.
	 *
	 * @param string $identifier The id or uuid.
	 *
	 * @return string|null The schema id.
	 */
	private function schemaIdByIdentifier(string $identifier): ?string {
		if ((new ReferenceText())->isIdentifier(value: $identifier) === false) {
			return null;
		}

		try {
			return (string)$this->schema(schemaId: $identifier)->getId();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end schemaIdByIdentifier()

	/**
	 * The id of the schema with this slug, keeping every schema read on the way.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The schema id; null when none matches or the read fails.
	 */
	private function schemaIdFromAll(string $slug): ?string {
		try {
			return $this->scanSchemas(slug: $slug);
		} catch (Exception $e) {
			return null;
		}
	}//end schemaIdFromAll()

	/**
	 * Walk the schemas until one has this slug; a schema without a slug cannot match.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The schema id.
	 */
	private function scanSchemas(string $slug): ?string {
		foreach ($this->schemaMapper->findAll() as $schema) {
			if ($schema->getSlug() === null) {
				continue;
			}

			$this->rememberSlugOf(schema: $schema);
			if (strcasecmp($schema->getSlug(), $slug) === 0) {
				return (string)$schema->getId();
			}
		}

		return null;
	}//end scanSchemas()

	/**
	 * Keep a schema with the three spellings a reference may use for its slug.
	 *
	 * @param Schema $schema The schema, which has a slug.
	 *
	 * @return void
	 */
	private function rememberSlugOf(Schema $schema): void {
		$schemaId = (string)$schema->getId();
		$slug = $schema->getSlug();
		$this->schemaCache[$schemaId] = $schema;
		$this->schemaReferenceCache['#/components/schemas/' . $slug] = $schemaId;
		$this->schemaReferenceCache[$slug] = $schemaId;
		$this->schemaReferenceCache[strtolower($slug)] = $schemaId;
	}//end rememberSlugOf()

	/**
	 * The id of the schema the mapper finds under this slug, outside the caller's scope.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The schema id.
	 */
	private function schemaIdBySlug(string $slug): ?string {
		try {
			$schema = $this->schemaMapper->find(id: $slug, _rbac: false, _multitenancy: false);
			$this->rememberSchema(schema: $schema);

			return (string)$schema->getId();
		} catch (Exception $e) {
			return null;
		}
	}//end schemaIdBySlug()
}//end class
