<?php

/**
 * OpenRegister BulkObjectSlugs
 *
 * Applies the slug rules to the prepared rows of a bulk save, one register and
 * schema group at a time (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Closure;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Decide the object slugs of a prepared bulk batch.
 *
 * Prepared rows come in two shapes: the flat selfData shape of the single-
 * schema fast path (metadata at the top, business data under `object`) and
 * the `@self` shape of the mixed-schema path. Both are normalised for
 * ObjectSlugHandler::decideBatch() and the decision is written back as
 * `slug`, explicitly null when there is none. Schemas without
 * `objectSlugField` are left exactly as they are (BC1).
 *
 * The group is the table the rows are WRITTEN to: with a caller schema (the
 * fast path) persistChunk() writes every row into the caller's register and
 * schema, whatever a row's advisory `@self.register` says, so the decision
 * is made against that table too. Only the mixed path groups per row,
 * falling back to the caller's register as ultraFastBulkSave() does. A
 * managed group whose register cannot be resolved gets no slug rather than
 * an unchecked client value.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
class BulkObjectSlugs {

	/**
	 * Key that carries a top-level client `slug` past extractBusinessData().
	 *
	 * The fast path strips an undeclared top-level `slug` from the business data;
	 * the row's metadata carries clientSlugToCarry() here so the slug decision still
	 * sees it, and apply() removes it again before anything is written.
	 */
	public const CLIENT_SLUG_KEY = '__clientSlug';

	/**
	 * Constructor.
	 *
	 * @param ObjectSlugHandler $slugHandler    The slug rules.
	 * @param RegisterMapper    $registerMapper Resolves a group's register when the caller did not pass it.
	 * @param LoggerInterface   $logger         Receives the unresolved-group warnings.
	 * @param Closure           $schemaOf       fn(string $schemaId): Schema — the bulk save's cached schema lookup.
	 */
	public function __construct(
		private readonly ObjectSlugHandler $slugHandler,
		private readonly RegisterMapper $registerMapper,
		private readonly LoggerInterface $logger,
		private readonly Closure $schemaOf,
	) {
	}//end __construct()

	/**
	 * The top-level client `slug` extractBusinessData() would drop (fast path), to carry under CLIENT_SLUG_KEY.
	 *
	 * @param mixed $selfData The row's metadata.
	 * @param array $object   The row as sent.
	 *
	 * @return string|null The slug, or null when `@self` has one or none was sent.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function clientSlugToCarry(mixed $selfData, array $object): ?string {
		$slug = ($object['slug'] ?? null);
		if (isset($selfData['slug']) === true || is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end clientSlugToCarry()

	/**
	 * Decide the object slugs of a prepared batch, one register+schema group at a time.
	 *
	 * @param array                    $objects  The prepared rows.
	 * @param Register|string|int|null $register The caller's register, if given.
	 * @param Schema|string|int|null   $schema   The caller's schema, if given.
	 *
	 * @return array The rows, each with its slug decided and without the carrier key.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function apply(array $objects, Register|string|int|null $register=null, Schema|string|int|null $schema=null): array {
		foreach ($this->groups(objects: $objects, register: $register, schema: $schema) as $group) {
			$this->decideGroup(objects: $objects, group: $group);
		}

		foreach (array_keys($objects) as $index) {
			unset($objects[$index][self::CLIENT_SLUG_KEY]);
			if (isset($objects[$index]['@self']) === true && is_array($objects[$index]['@self']) === true) {
				unset($objects[$index]['@self'][self::CLIENT_SLUG_KEY]);
			}
		}

		return $objects;
	}//end apply()

	/**
	 * The register+schema groups of a batch: the table each row is written to.
	 *
	 * @param array                    $objects  The prepared rows.
	 * @param Register|string|int|null $register The caller's register, if given.
	 * @param Schema|string|int|null   $schema   The caller's schema, if given.
	 *
	 * @return array<string, array<string, mixed>> Group key => registerId, schemaId, indexes, and the register and
	 *                                             schema entities when the caller passed the ones the group is written to.
	 */
	private function groups(array $objects, Register|string|int|null $register, Schema|string|int|null $schema): array {
		$callerRegisterId = $this->idOf(entity: $register);
		$callerSchemaId = $this->idOf(entity: $schema);

		$groups = [];
		foreach ($objects as $index => $object) {
			$self = ($object['@self'] ?? $object);
			[$registerId, $schemaId] = [($self['register'] ?? $callerRegisterId), ($self['schema'] ?? null)];
			if ($callerSchemaId !== null) {
				[$registerId, $schemaId] = [($callerRegisterId ?? $registerId), $callerSchemaId];
			}

			if ($registerId === null || $schemaId === null) {
				continue;
			}

			$key = $registerId . '|' . $schemaId;
			$groups[$key] ??= [
				'registerId' => (string)$registerId,
				'schemaId' => (string)$schemaId,
				'indexes' => [],
				'register' => $this->given(entity: $register, id: (string)$registerId),
				'schema' => $this->given(entity: $schema, id: (string)$schemaId),
			];
			$groups[$key]['indexes'][] = $index;
		}//end foreach

		return $groups;
	}//end groups()

	/**
	 * The id of a register or schema the caller passed, as an entity or an id.
	 *
	 * @param Register|Schema|string|int|null $entity The entity or id.
	 *
	 * @return string|int|null The id.
	 */
	private function idOf(Register|Schema|string|int|null $entity): string|int|null {
		if ($entity instanceof Register || $entity instanceof Schema) {
			return $entity->getId();
		}

		return $entity;
	}//end idOf()

	/**
	 * The entity the caller passed, when it is the one a group is written to.
	 *
	 * @param Register|Schema|string|int|null $entity The caller's register or schema.
	 * @param string                          $id     The group's id for it.
	 *
	 * @return Register|Schema|null The entity, or null when the caller passed only an id or another one.
	 */
	private function given(Register|Schema|string|int|null $entity, string $id): Register|Schema|null {
		if (($entity instanceof Register || $entity instanceof Schema) && (string)$entity->getId() === $id) {
			return $entity;
		}

		return null;
	}//end given()

	/**
	 * Decide the slugs of one register+schema group and write them back.
	 *
	 * @param array                $objects The prepared rows, by reference.
	 * @param array<string, mixed> $group   The group, as groups() builds it.
	 *
	 * @return void
	 */
	private function decideGroup(array &$objects, array $group): void {
		$schema = $this->groupSchema(group: $group);
		if ($schema === null || $this->slugHandler->isManaged(schema: $schema) === false) {
			return;
		}

		$register = $this->groupRegister(group: $group);
		if ($register === null) {
			// Fail closed: without the table there is no uniqueness check, so no
			// client slug is written unchecked.
			foreach ($group['indexes'] as $index) {
				$this->writeDecision(objects: $objects, index: $index, slug: null);
			}

			return;
		}

		$rows = [];
		foreach ($group['indexes'] as $index) {
			$rows[$index] = $this->slugRow(object: $objects[$index]);
		}

		foreach ($this->slugHandler->decideBatch(rows: $rows, register: $register, schema: $schema) as $index => $slug) {
			$this->writeDecision(objects: $objects, index: $index, slug: $slug);
		}
	}//end decideGroup()

	/**
	 * The schema of a group, or null (logged) when it does not resolve.
	 *
	 * @param array<string, mixed> $group The group, as groups() builds it.
	 *
	 * @return Schema|null The schema.
	 */
	private function groupSchema(array $group): ?Schema {
		if ($group['schema'] instanceof Schema) {
			return $group['schema'];
		}

		try {
			return ($this->schemaOf)($group['schemaId']);
		} catch (Exception $e) {
			$this->logger->warning(
				'[ObjectSlug] Could not resolve schema ' . $group['schemaId'] . ' for the bulk slug check: ' . $e->getMessage(),
				['app' => 'openregister']
			);
			return null;
		}
	}//end groupSchema()

	/**
	 * The register of a group, or null (logged) when it does not resolve.
	 *
	 * @param array<string, mixed> $group The group, as groups() builds it.
	 *
	 * @return Register|null The register.
	 */
	private function groupRegister(array $group): ?Register {
		if ($group['register'] instanceof Register) {
			return $group['register'];
		}

		try {
			return $this->registerMapper->find(id: $group['registerId'], _rbac: false, _multitenancy: false);
		} catch (Exception $e) {
			$this->logger->warning(
				'[ObjectSlug] Could not resolve register ' . $group['registerId'] . ' for the bulk slug check; '
				. 'the rows of schema ' . $group['schemaId'] . ' are saved without a slug: ' . $e->getMessage(),
				['app' => 'openregister']
			);
			return null;
		}
	}//end groupRegister()

	/**
	 * One prepared row in the shape ObjectSlugHandler::decideBatch() reads.
	 *
	 * @param array $object The prepared row (flat selfData or `@self` shape).
	 *
	 * @return array{uuid: ?string, clientSlug: ?string, data: array}
	 */
	private function slugRow(array $object): array {
		$self = $object;
		$data = ($object['object'] ?? []);
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			$data = $object;
			unset($data['@self']);
		}

		$clientSlug = ($self['slug'] ?? $self[self::CLIENT_SLUG_KEY] ?? $data['slug'] ?? null);
		$uuid = ($self['uuid'] ?? $self['id'] ?? null);

		if (is_array($data) === false) {
			$data = [];
		}

		return [
			'uuid' => $this->nonEmptyString(value: $uuid, allowEmpty: true),
			'clientSlug' => $this->nonEmptyString(value: $clientSlug, allowEmpty: false),
			'data' => $data,
		];
	}//end slugRow()

	/**
	 * A string value, or null when it is not one.
	 *
	 * @param mixed $value      The value.
	 * @param bool  $allowEmpty Whether an empty string counts.
	 *
	 * @return string|null The string, or null.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A uuid keeps '' (as before), a client slug does not.
	 */
	private function nonEmptyString(mixed $value, bool $allowEmpty): ?string {
		if (is_string($value) === false || ($allowEmpty === false && $value === '')) {
			return null;
		}

		return $value;
	}//end nonEmptyString()

	/**
	 * Write a slug decision back into its row, as an explicit null when there is none.
	 *
	 * @param array       $objects The prepared rows, by reference.
	 * @param int|string  $index   The row.
	 * @param string|null $slug    The decision.
	 *
	 * @return void
	 */
	private function writeDecision(array &$objects, int|string $index, ?string $slug): void {
		if (isset($objects[$index]['@self']) === true && is_array($objects[$index]['@self']) === true) {
			$objects[$index]['@self']['slug'] = $slug;
			return;
		}

		$objects[$index]['slug'] = $slug;
	}//end writeDecision()
}//end class
