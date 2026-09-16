<?php

/**
 * Shared master data: one code list, several legal entities, no copies.
 *
 * WHY THIS EXISTS. A gemeenschappelijke regeling is several legal entities
 * sharing one back office. Each keeps its own records and its own
 * responsibility, and they share the code lists, the case types and the
 * parties. OpenRegister separated tenants well and shared nothing, so each
 * entity kept its own copy of the same code list, and a copy is wrong within a
 * year. Odoo's answer is cited in the corpus as `res_company.py, company_id on
 * every model`: records separated, master data shared.
 *
 * THE SHARE IS A DECLARED READ, NOT A COPY AND NOT A SECOND TENANCY (design
 * D-1). The holder is the `organisation` column the row already carries. The
 * consumers are the new `shared_with` list. Reading a shared row goes through
 * the SAME tenant-scoped query path as reading your own, widened by exactly the
 * holders you were declared a consumer of. So the organisation UUID is still
 * the only tenant key (ADR-002), and there is no second discriminator to drift.
 *
 * 🔴 THIS CLASS READS THE TWO TABLES DIRECTLY, AND THAT IS DELIBERATE. It is
 * the rule the tenant filter is widened BY, so it cannot itself be subject to
 * that filter: asking RegisterMapper::findAll() which registers are shared with
 * me would apply the org filter first and answer "none", every time, silently.
 * Going through the mappers would also close a dependency cycle
 * (RegisterMapper -> this -> RegisterMapper). Two columns, read raw.
 *
 * 🔑 THE WIDENING IS SCOPED TO THE DECLARED RESOURCE, NEVER TO THE HOLDER. A
 * consumer of one code list does not become able to read everything the holder
 * owns. Each magic table is one register+schema pair, so widening the org set
 * inside a query against that table widens exactly that pair and nothing else;
 * {@see holdersForResource()} is what decides whether it applies at all.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Throwable;

/**
 * Resolves, and enforces, shared master data declarations across organisations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class SharedMasterDataService {

	/**
	 * The registers table.
	 */
	public const REGISTERS = 'openregister_registers';

	/**
	 * The schemas table.
	 */
	public const SCHEMAS = 'openregister_schemas';

	/**
	 * Every declared share, per table, read once per request.
	 *
	 * Shape: table => list of ['id' => int, 'holder' => string, 'consumers' => string[], 'title' => ?string].
	 *
	 * @var array<string, array<int, array{id: int, holder: string, consumers: array<int, string>, title: ?string}>>|null
	 */
	private ?array $declarations = null;

	/**
	 * Organisation names, resolved lazily and only for a refusal message.
	 *
	 * @var array<string, string|null>
	 */
	private array $holderNames = [];

	/**
	 * Build the resolver.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Forget the cached declarations.
	 *
	 * Called after a declaration is written, so the same request cannot read a
	 * share it just removed.
	 *
	 * @return void
	 */
	public function clearCache(): void {
		$this->declarations = null;
		$this->holderNames = [];

	}//end clearCache()

	/**
	 * Read every shared master data declaration, once per request.
	 *
	 * A row with no `shared_with` value, an empty list, or a value that is not
	 * a JSON list of strings, declares nothing. Nothing is not an error: the
	 * overwhelming majority of rows are exactly that, and an instance that has
	 * never heard of this feature must cost nothing to read.
	 *
	 * @return array<string, array<int, array{id: int, holder: string, consumers: array<int, string>, title: ?string}>> Declarations per table.
	 */
	private function declarations(): array {
		if ($this->declarations !== null) {
			return $this->declarations;
		}

		$this->declarations = [
			self::REGISTERS => [],
			self::SCHEMAS => [],
		];

		foreach ([self::REGISTERS, self::SCHEMAS] as $table) {
			$this->declarations[$table] = $this->readDeclarations(table: $table);
		}

		return $this->declarations;

	}//end declarations()

	/**
	 * Read one table's declarations.
	 *
	 * Fails SOFT, and that is the correct direction here: this method only ever
	 * WIDENS what a caller may read, so an unreadable declaration table costs a
	 * consumer its shared rows and can never hand anybody a row they were not
	 * declared a consumer of. The refusal path ({@see assertWritable()}) is a
	 * different method for the same reason — it must fail closed, and it does,
	 * because an empty declaration set means "held by you", not "shared".
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 *
	 * @return array<int, array{id: int, holder: string, consumers: array<int, string>, title: ?string}> The declarations.
	 */
	private function readDeclarations(string $table): array {
		$rows = [];

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'title', 'organisation', 'shared_with')
				->from($table)
				->where($qb->expr()->isNotNull('shared_with'));

			$result = $qb->executeQuery();
			while (($row = $result->fetch()) !== false) {
				$declaration = $this->parseRow(row: $row);
				if ($declaration !== null) {
					$rows[] = $declaration;
				}
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			// An instance that has not run the migration yet has no column, and
			// that is the common case on the first request after an upgrade.
			// Declaring nothing is the right answer, not a 500.
			return [];
		}//end try

		return $rows;

	}//end readDeclarations()

	/**
	 * Turn one database row into a declaration, or into null when it declares nothing.
	 *
	 * @param array<string, mixed> $row The raw row.
	 *
	 * @return array{id: int, holder: string, consumers: array<int, string>, title: ?string}|null The declaration.
	 */
	private function parseRow(array $row): ?array {
		$holder = ($row['organisation'] ?? null);
		if (is_string($holder) === false || $holder === '') {
			// A row with no holder is not shared BY anyone, so it cannot be
			// shared WITH anyone either. Treating it as shared would make an
			// org-less legacy row readable across every tenant at once.
			return null;
		}

		$raw = ($row['shared_with'] ?? null);
		if (is_string($raw) === false || trim($raw) === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$consumers = [];
		foreach ($decoded as $consumer) {
			if (is_string($consumer) === true && $consumer !== '' && $consumer !== $holder) {
				$consumers[] = $consumer;
			}
		}

		if ($consumers === []) {
			return null;
		}

		$title = ($row['title'] ?? null);
		if (is_string($title) === false) {
			$title = null;
		}

		return [
			'id' => (int)($row['id'] ?? 0),
			'holder' => $holder,
			'consumers' => array_values(array_unique($consumers)),
			'title' => $title,
		];

	}//end parseRow()

	/**
	 * The ids in one table that the given organisations may read through a share.
	 *
	 * Only rows they do NOT already hold: a holder reads its own rows through
	 * the ordinary organisation filter, and adding them here would double a
	 * predicate for no gain.
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 * @param array<int, string> $consumerOrgUuids The reading organisation and its parents.
	 *
	 * @return array<int, int> The row ids.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function sharedIds(string $table, array $consumerOrgUuids): array {
		if ($consumerOrgUuids === []) {
			return [];
		}

		$ids = [];
		foreach (($this->declarations()[$table] ?? []) as $declaration) {
			if (in_array($declaration['holder'], $consumerOrgUuids, true) === true) {
				continue;
			}

			if (array_intersect($declaration['consumers'], $consumerOrgUuids) !== []) {
				$ids[] = $declaration['id'];
			}
		}

		return array_values(array_unique($ids));

	}//end sharedIds()

	/**
	 * The holder organisations whose OBJECT rows the reader may see in one register+schema pair.
	 *
	 * This is the object-data half. Each magic table holds exactly one
	 * register+schema pair, so a widening resolved here applies to that pair and
	 * to nothing else the holder owns — which is the whole isolation argument
	 * for doing it this way rather than adding holder UUIDs to the tenant set
	 * globally.
	 *
	 * A share declared on EITHER the register or the schema admits the pair.
	 * Declaring it on the register is how a whole code list is shared;
	 * declaring it on the schema is how one case type or party definition is
	 * shared across the registers that carry it.
	 *
	 * @param integer|null $registerId The register being read, when known.
	 * @param integer|null $schemaId The schema being read, when known.
	 * @param array<int, string> $consumerOrgUuids The reading organisation and its parents.
	 *
	 * @return array<int, string> The holder organisation UUIDs to widen by.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function holdersForResource(?int $registerId, ?int $schemaId, array $consumerOrgUuids): array {
		if ($consumerOrgUuids === [] || ($registerId === null && $schemaId === null)) {
			return [];
		}

		$holders = [];

		if ($registerId !== null) {
			$holders = array_merge(
				$holders,
				$this->holdersOf(table: self::REGISTERS, id: $registerId, consumerOrgUuids: $consumerOrgUuids)
			);
		}

		if ($schemaId !== null) {
			$holders = array_merge(
				$holders,
				$this->holdersOf(table: self::SCHEMAS, id: $schemaId, consumerOrgUuids: $consumerOrgUuids)
			);
		}

		return array_values(array_unique($holders));

	}//end holdersForResource()

	/**
	 * The holder of one row, when the given organisations consume it.
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 * @param integer $id The row id.
	 * @param array<int, string> $consumerOrgUuids The reading organisation and its parents.
	 *
	 * @return array<int, string> Zero or one holder UUID.
	 */
	private function holdersOf(string $table, int $id, array $consumerOrgUuids): array {
		foreach (($this->declarations()[$table] ?? []) as $declaration) {
			if ($declaration['id'] !== $id) {
				continue;
			}

			if (in_array($declaration['holder'], $consumerOrgUuids, true) === true) {
				// They hold it. The ordinary filter already lets them read it,
				// and it must keep letting them WRITE it.
				return [];
			}

			if (array_intersect($declaration['consumers'], $consumerOrgUuids) !== []) {
				return [$declaration['holder']];
			}

			return [];
		}//end foreach

		return [];

	}//end holdersOf()

	/**
	 * Whether a row is reached through a share rather than held.
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 * @param integer|null $id The row id.
	 * @param array<int, string> $activeOrgUuids The acting organisation and its parents.
	 *
	 * @return boolean True when the acting organisation only consumes this row.
	 */
	public function isConsumedShare(string $table, ?int $id, array $activeOrgUuids): bool {
		if ($id === null) {
			return false;
		}

		return $this->holdersOf(table: $table, id: $id, consumerOrgUuids: $activeOrgUuids) !== [];

	}//end isConsumedShare()

	/**
	 * Refuse a write to shared master data the acting organisation only consumes.
	 *
	 * Design D-2: read-only to the consumer is a property of the resolution, not
	 * of the user interface. This is the write path, so this is where the
	 * refusal belongs, and the message names the holder so the caller knows who
	 * to ask rather than only that they were told no.
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 * @param integer|null $id The row being written.
	 * @param array<int, string> $activeOrgUuids The acting organisation and its parents.
	 *
	 * @return void
	 *
	 * @throws SharedMasterDataWriteException When the acting organisation only consumes the row.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function assertWritable(string $table, ?int $id, array $activeOrgUuids): void {
		if ($id === null || $activeOrgUuids === []) {
			return;
		}

		foreach (($this->declarations()[$table] ?? []) as $declaration) {
			if ($declaration['id'] !== $id) {
				continue;
			}

			if (in_array($declaration['holder'], $activeOrgUuids, true) === true) {
				return;
			}

			if (array_intersect($declaration['consumers'], $activeOrgUuids) === []) {
				return;
			}

			$resourceType = 'register';
			if ($table === self::SCHEMAS) {
				$resourceType = 'schema';
			}

			throw new SharedMasterDataWriteException(
				holderUuid: $declaration['holder'],
				holderName: $this->holderName(uuid: $declaration['holder']),
				resourceType: $resourceType,
				resourceTitle: $declaration['title']
			);
		}//end foreach

	}//end assertWritable()

	/**
	 * The name of a holding organisation, for a refusal message only.
	 *
	 * Reads the organisations table directly and tolerates failure: a refusal
	 * whose message falls back to the UUID is still a refusal, and a lookup
	 * failure must never turn a clean 403 into a 500.
	 *
	 * @param string $uuid The organisation UUID.
	 *
	 * @return string|null The name, or null when it could not be read.
	 */
	public function holderName(string $uuid): ?string {
		if (array_key_exists($uuid, $this->holderNames) === true) {
			return $this->holderNames[$uuid];
		}

		$this->holderNames[$uuid] = null;

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('name')
				->from('openregister_organisations')
				->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$name = $result->fetchOne();
			$result->closeCursor();

			if (is_string($name) === true && $name !== '') {
				$this->holderNames[$uuid] = $name;
			}
		} catch (Throwable $e) {
			return null;
		}

		return $this->holderNames[$uuid];

	}//end holderName()
}//end class
