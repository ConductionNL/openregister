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
use OCA\OpenRegister\Db\RegisterMapperInterface;
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
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
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
	 * @param RegisterMapperInterface    $registerMapper Resolves a group's register when the caller did not pass it.
	 * @param LoggerInterface   $logger         Receives the unresolved-group warnings.
	 * @param Closure           $schemaOf       fn(string $schemaId): Schema — the bulk save's cached schema lookup.
	 */
	public function __construct(
		private readonly ObjectSlugHandler $slugHandler,
		private readonly RegisterMapperInterface $registerMapper,
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function clientSlugToCarry(mixed $selfData, array $object): string|null {
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function apply(array $objects, Register|string|int|null $register=null, Schema|string|int|null $schema=null): array {
		foreach ($this->groups(objects: $objects, caller: new BulkSlugCaller(register: $register, schema: $schema)) as $group) {
			$this->decideGroup(objects: $objects, group: $group);
		}

		foreach (array_keys($objects) as $index) {
			unset($objects[$index][self::CLIENT_SLUG_KEY]);
			if ($this->hasSelf(row: $objects[$index]) === true) {
				unset($objects[$index]['@self'][self::CLIENT_SLUG_KEY]);
			}
		}

		return $objects;
	}//end apply()

	/**
	 * Whether a prepared row has the `@self` shape.
	 *
	 * @param mixed $row The prepared row.
	 *
	 * @return bool True when `@self` is an array.
	 */
	private function hasSelf(mixed $row): bool {
		return isset($row['@self']) === true && is_array($row['@self']) === true;
	}//end hasSelf()

	/**
	 * The register+schema groups of a batch: the table each row is written to.
	 *
	 * @param array          $objects The prepared rows.
	 * @param BulkSlugCaller $caller  The register and schema the caller passed.
	 *
	 * @return array<string, array<string, mixed>> Group key => registerId, schemaId, indexes, and the register and
	 *                                             schema entities when the caller passed the ones the group is written to.
	 */
	private function groups(array $objects, BulkSlugCaller $caller): array {
		$groups = [];
		foreach ($objects as $index => $object) {
			[$registerId, $schemaId] = $caller->idsOf(self: ($object['@self'] ?? $object));
			if ($registerId === null || $schemaId === null) {
				continue;
			}

			$key = $registerId . '|' . $schemaId;
			$groups[$key] ??= $this->newGroup(ids: [(string)$registerId, (string)$schemaId], caller: $caller);
			$groups[$key]['indexes'][] = $index;
		}

		return $groups;
	}//end groups()

	/**
	 * A group without rows yet.
	 *
	 * @param array{0: string, 1: string} $ids    The register and schema id the group is written to.
	 * @param BulkSlugCaller              $caller The register and schema the caller passed.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function newGroup(array $ids, BulkSlugCaller $caller): array {
		return [
			'registerId' => $ids[0],
			'schemaId' => $ids[1],
			'indexes' => [],
			'register' => $caller->registerFor(id: $ids[0]),
			'schema' => $caller->schemaFor(id: $ids[1]),
		];
	}//end newGroup()

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

		foreach ($this->groupDecisions(objects: $objects, group: $group, schema: $schema) as $index => $slug) {
			$this->writeDecision(objects: $objects, index: $index, slug: $slug);
		}
	}//end decideGroup()

	/**
	 * The slug decisions of one managed group.
	 *
	 * @param array                $objects The prepared rows.
	 * @param array<string, mixed> $group   The group, as groups() builds it.
	 * @param Schema               $schema  The group's schema (managed).
	 *
	 * @return array<int|string, string|null> Row index => slug.
	 */
	private function groupDecisions(array $objects, array $group, Schema $schema): array {
		$register = $this->groupRegister(group: $group);
		if ($register === null) {
			// Fail closed: without the table there is no uniqueness check, so no
			// client slug is written unchecked.
			return array_fill_keys($group['indexes'], null);
		}

		$rows = [];
		foreach ($group['indexes'] as $index) {
			$rows[$index] = $this->slugRow(object: $objects[$index]);
		}

		return $this->slugHandler->decideBatch(rows: $rows, register: $register, schema: $schema);
	}//end groupDecisions()

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
		[$self, $data] = $this->selfAndData(object: $object);
		$clientSlug = ($self['slug'] ?? $self[self::CLIENT_SLUG_KEY] ?? $data['slug'] ?? null);

		return [
			'uuid' => $this->nonEmptyString(value: ($self['uuid'] ?? $self['id'] ?? null), allowEmpty: true),
			'clientSlug' => $this->nonEmptyString(value: $clientSlug, allowEmpty: false),
			'data' => $this->dataArray(data: $data),
		];
	}//end slugRow()

	/**
	 * Business data as an array; anything else counts as none.
	 *
	 * @param mixed $data The business data as found.
	 *
	 * @return array The data, or an empty array.
	 */
	private function dataArray(mixed $data): array {
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end dataArray()

	/**
	 * A prepared row's metadata and business data, whichever shape it has.
	 *
	 * @param array $object The prepared row (flat selfData or `@self` shape).
	 *
	 * @return array{0: array, 1: mixed} The metadata and the business data as found.
	 */
	private function selfAndData(array $object): array {
		if ($this->hasSelf(row: $object) === false) {
			return [$object, ($object['object'] ?? [])];
		}

		$data = $object;
		unset($data['@self']);
		return [$object['@self'], $data];
	}//end selfAndData()

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
	private function nonEmptyString(mixed $value, bool $allowEmpty): string|null {
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
	private function writeDecision(array &$objects, int|string $index, string|null $slug): void {
		if ($this->hasSelf(row: $objects[$index]) === true) {
			$objects[$index]['@self']['slug'] = $slug;
			return;
		}

		$objects[$index]['slug'] = $slug;
	}//end writeDecision()
}//end class
