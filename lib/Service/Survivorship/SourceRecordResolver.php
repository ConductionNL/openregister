<?php

/**
 * OpenRegister SourceRecordResolver
 *
 * Resolves a master object's competing source records for the survivorship /
 * merge engine, in one of two modes declared by the schema's
 * `x-openregister-survivorship` / `x-openregister-merge` annotation:
 *
 *   - embedded  (default) — sources live on the master payload's
 *                `sourceLinkField` as embedded records and/or uuid references.
 *   - reverseFk           — sources are separate objects that reference the
 *                master; resolved by querying `sourceLink.sourceSchema` for
 *                objects whose `sourceLink.referenceField` equals the master's
 *                uuid.
 *
 * The resolved array is fed verbatim to `SurvivorshipResolver`, which reads
 * each entry's `values`/`mappedAttributes`. Never fatal: an unresolvable
 * reference or a malformed `sourceLink` block degrades to embedded mode (or an
 * empty set) with a logged warning rather than throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mode-aware source-record resolver shared by the survivorship recompute
 * listener and the merge service.
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
 */
class SourceRecordResolver implements SourceRecordResolverInterface {

	/**
	 * The warning for a reverse-FK source link that cannot be resolved.
	 *
	 * @var string
	 */
	private const INCOMPLETE_SOURCE_LINK = 'x-openregister source resolution: reverseFk sourceLink is missing sourceSchema or referenceField; '
		.'falling back to embedded mode.';

	/**
	 * Cross-request cache of source-schema reference (slug/uuid) => numeric
	 * schema id. Populated only from successful resolutions (a mid-save slug
	 * lookup that throws never poisons it). Lets the reverse-FK query pass a
	 * numeric schema id — which `ObjectService::setSchema()` resolves via its
	 * cached numeric path — instead of a slug, whose DB lookup throws when it
	 * runs inside a save transaction (the recompute-on-save listener).
	 *
	 * @var array<string, string>
	 */
	private static array $schemaIdCache = [];

	/**
	 * Wire the object read path used to resolve uuid references (embedded
	 * mode) and to query source objects (reverse-FK mode).
	 *
	 * @param ObjectOperationsInterface $objectService Object read path (RBAC + tenant scoped).
	 * @param SchemaMapperInterface $schemaMapper Schema lookup, to resolve the source schema slug to a numeric id.
	 * @param LoggerInterface $logger PSR logger for non-fatal warnings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the competing source records for a master, honouring the
	 * annotation's `sourceLink.mode`.
	 *
	 * @param array<string, mixed> $masterData Master object payload.
	 * @param string $masterUuid Master object uuid (needed for reverse-FK).
	 * @param array<string, mixed> $config `x-openregister-survivorship` (or `-merge`) config.
	 * @param string $masterRegister Master's register ref, used as the reverse-FK
	 *                               source register when the annotation omits one
	 *                               (the magic-table query needs a register + schema).
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function resolveSources(array $masterData, string $masterUuid, array $config, string $masterRegister = ''): array {
		$reverseFk = $this->reverseFkConfig(config: $config);
		if ($reverseFk === null) {
			return $this->embeddedSources(data: $masterData, config: $config);
		}

		$reverseFk = $this->withSourceRegister(reverseFk: $reverseFk, masterRegister: $masterRegister);
		return $this->resolveReverseFk(query: $this->reverseFkQuery(reverseFk: $reverseFk, masterUuid: $masterUuid));
	}//end resolveSources()

	/**
	 * Embedded mode (default): the records the master payload's sourceLinkField names; none when the config names no field.
	 *
	 * @param array<string, mixed> $data   Master object payload.
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 */
	private function embeddedSources(array $data, array $config): array {
		$sourceLinkField = (string)($config['sourceLinkField'] ?? '');
		if ($sourceLinkField === '') {
			return [];
		}

		return $this->resolveEmbedded(data: $data, sourceLinkField: $sourceLinkField);
	}//end embeddedSources()

	/**
	 * The reverse-FK config with the master's register as the source register when the annotation omits one
	 * (the magic-table query needs a register + schema).
	 *
	 * @param array{sourceSchema: string, referenceField: string, sourceRegister: string} $reverseFk      The reverse-FK config.
	 * @param string                                                                       $masterRegister Master's register ref.
	 *
	 * @return array{sourceSchema: string, referenceField: string, sourceRegister: string}
	 */
	private function withSourceRegister(array $reverseFk, string $masterRegister): array {
		if ($reverseFk['sourceRegister'] === '') {
			$reverseFk['sourceRegister'] = $masterRegister;
		}

		return $reverseFk;
	}//end withSourceRegister()

	/**
	 * The reverse-FK query for one master.
	 *
	 * @param array{sourceSchema: string, referenceField: string, sourceRegister: string} $reverseFk  The reverse-FK config.
	 * @param string                                                                       $masterUuid Master object uuid.
	 *
	 * @return ReverseFkQuery The query.
	 */
	private function reverseFkQuery(array $reverseFk, string $masterUuid): ReverseFkQuery {
		return new ReverseFkQuery(
			masterUuid: $masterUuid,
			sourceSchema: $reverseFk['sourceSchema'],
			referenceField: $reverseFk['referenceField'],
			sourceRegister: $reverseFk['sourceRegister']
		);
	}//end reverseFkQuery()

	/**
	 * Whether the config selects reverse-FK resolution.
	 *
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return bool True when a well-formed reverse-FK `sourceLink` is present.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function isReverseFk(array $config): bool {
		return $this->reverseFkConfig(config: $config) !== null;
	}//end isReverseFk()

	/**
	 * Return the validated reverse-FK descriptor (`sourceSchema`,
	 * `referenceField`, `sourceRegister`) for a config, or null when the
	 * config is not reverse-FK. Used by callers that need to query or mutate
	 * the source objects themselves (e.g. merge relink).
	 *
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return array{sourceSchema: string, referenceField: string, sourceRegister: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function reverseFkDescriptor(array $config): ?array {
		return $this->reverseFkConfig(config: $config);
	}//end reverseFkDescriptor()

	/**
	 * Resolve a source-schema reference to the value the reverse-FK `findAll`
	 * filter should carry: the numeric schema id when resolvable (the
	 * save-transaction-safe path), else the original reference unchanged.
	 * Used by callers that build the query themselves (e.g. merge relink).
	 *
	 * @param string $ref Source-schema reference (slug/uuid/id).
	 *
	 * @return string Numeric id when resolvable, otherwise `$ref`.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	public function schemaQueryFilter(string $ref): string {
		$id = $this->resolveSchemaId(ref: $ref);
		if ($id !== '') {
			return $id;
		}

		return $ref;
	}//end schemaQueryFilter()

	/**
	 * Resolve a schema reference (slug/uuid/id) to its numeric id, memoising
	 * successful resolutions across requests. A lookup that throws (e.g. a slug
	 * resolved inside a save transaction) returns '' and is NOT cached, so a
	 * later clean-context call can still populate it.
	 *
	 * @param string $ref Schema reference.
	 *
	 * @return string Numeric schema id, or '' when unresolved.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function resolveSchemaId(string $ref): string {
		if ($ref === '') {
			return '';
		}

		if (is_numeric($ref) === true) {
			return $ref;
		}

		if (array_key_exists($ref, self::$schemaIdCache) === true) {
			return self::$schemaIdCache[$ref];
		}

		return $this->lookupSchemaId(ref: $ref);
	}//end resolveSchemaId()

	/**
	 * Look a non-numeric schema reference up, caching the id only on success.
	 *
	 * @param string $ref Schema reference (slug/uuid).
	 *
	 * @return string Numeric schema id, or '' when the lookup throws.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function lookupSchemaId(string $ref): string {
		try {
			$schema = $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
			$id     = (string)$schema->getId();
		} catch (Throwable) {
			return '';
		}

		if ($id !== '') {
			self::$schemaIdCache[$ref] = $id;
		}

		return $id;
	}//end lookupSchemaId()

	/**
	 * Parse and validate the reverse-FK `sourceLink` block. A block with mode
	 * `reverseFk` but missing `sourceSchema`/`referenceField` degrades to
	 * embedded mode (returns null) with a logged warning.
	 *
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return array{sourceSchema: string, referenceField: string, sourceRegister: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function reverseFkConfig(array $config): ?array {
		$sourceLink = $this->reverseFkSourceLink(config: $config);
		if ($sourceLink === null) {
			return null;
		}

		$fkConfig = self::fkConfigOf(sourceLink: $sourceLink);
		if ($fkConfig['sourceSchema'] === '' || $fkConfig['referenceField'] === '') {
			$this->logger->warning(message: self::INCOMPLETE_SOURCE_LINK);
			return null;
		}

		return $fkConfig + ['sourceRegister' => (string)($sourceLink['sourceRegister'] ?? '')];
	}//end reverseFkConfig()

	/**
	 * The two required reverse-FK fields of a `sourceLink` block, as text.
	 *
	 * @param array<string, mixed> $sourceLink The block.
	 *
	 * @return array{sourceSchema: string, referenceField: string}
	 */
	private static function fkConfigOf(array $sourceLink): array {
		return [
			'sourceSchema' => (string)($sourceLink['sourceSchema'] ?? ''),
			'referenceField' => (string)($sourceLink['referenceField'] ?? ''),
		];
	}//end fkConfigOf()

	/**
	 * The config's `sourceLink` block when it selects reverse-FK mode.
	 *
	 * @param array<string, mixed> $config Survivorship/merge config.
	 *
	 * @return array<string, mixed>|null The block, or null when absent, not an array or another mode.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function reverseFkSourceLink(array $config): ?array {
		$sourceLink = ($config['sourceLink'] ?? null);
		if (is_array($sourceLink) === false) {
			return null;
		}

		if ((string)($sourceLink['mode'] ?? 'embedded') !== 'reverseFk') {
			return null;
		}

		return $sourceLink;
	}//end reverseFkSourceLink()

	/**
	 * Query the source schema for objects whose `referenceField` equals the
	 * master uuid. RBAC + multitenancy scoped. A DB-level property filter is
	 * requested, and the result is re-filtered in PHP so correctness does not
	 * depend on the magic-table filter honouring an arbitrary property.
	 *
	 * @param ReverseFkQuery $query The master and where its sources are looked up.
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function resolveReverseFk(ReverseFkQuery $query): array {
		if ($query->masterUuid === '') {
			return [];
		}

		$objects = $this->findReverseFkObjects(query: $query, filters: $this->reverseFkFilters(query: $query));
		if ($objects === null) {
			return [];
		}

		return $this->pointingAtMaster(objects: $objects, query: $query);
	}//end resolveReverseFk()

	/**
	 * The `findAll` filters of a reverse-FK lookup.
	 *
	 * @param ReverseFkQuery $query The master and where its sources are looked up.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function reverseFkFilters(ReverseFkQuery $query): array {
		// Prefer a numeric schema id (setSchema's cached numeric path works
		// inside a save transaction; a slug lookup throws there).
		$schemaFilter = $this->resolveSchemaId(ref: $query->sourceSchema);
		if ($schemaFilter === '') {
			$schemaFilter = $query->sourceSchema;
		}

		$filters = [
			'schema' => $schemaFilter,
			$query->referenceField => $query->masterUuid,
		];

		if ($query->sourceRegister !== '') {
			$filters['register'] = $query->sourceRegister;
		}

		return $filters;
	}//end reverseFkFilters()

	/**
	 * Run the RBAC + tenant scoped reverse-FK query.
	 *
	 * @param ReverseFkQuery        $query   The master the sources point at.
	 * @param array<string, string> $filters The filters to query with.
	 *
	 * @return array<int, mixed>|null The objects found, or null when the query failed.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function findReverseFkObjects(ReverseFkQuery $query, array $filters): ?array {
		try {
			return $this->objectService->findAll(
				['filters' => $filters],
				_rbac: true,
				_multitenancy: true
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('Reverse-FK source resolution failed for master "%s": %s', $query->masterUuid, $e->getMessage())
			);
			return null;
		}
	}//end findReverseFkObjects()

	/**
	 * Keep the payloads of the objects that actually point at the master.
	 *
	 * @param array<int, mixed> $objects The objects the query returned.
	 * @param ReverseFkQuery    $query   The master and the field that points at it.
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function pointingAtMaster(array $objects, ReverseFkQuery $query): array {
		$records = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = ($object->getObject() ?? []);
			// Re-filter in PHP: only sources actually pointing at this master.
			if ((string)($data[$query->referenceField] ?? '') !== $query->masterUuid) {
				continue;
			}

			$records[] = $data;
		}

		return $records;
	}//end pointingAtMaster()

	/**
	 * Embedded resolution: the field holds an array of already-embedded source
	 * records and/or uuid strings referencing objects elsewhere in the
	 * register (resolved via ObjectService::find, RBAC + tenant scoped).
	 * Unresolvable entries are skipped.
	 *
	 * @param array<string, mixed> $data Master object payload.
	 * @param string $sourceLinkField Field holding the linked source records.
	 *
	 * @return array<int, array<string, mixed>> Resolved source-record payloads.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function resolveEmbedded(array $data, string $sourceLinkField): array {
		$raw = ($data[$sourceLinkField] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$records = [];
		foreach ($raw as $entry) {
			$record = $this->embeddedRecord(entry: $entry);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end resolveEmbedded()

	/**
	 * One embedded entry as a source record: an inline record as-is, a uuid
	 * string resolved, anything else skipped.
	 *
	 * @param mixed $entry One entry of the source link field (untrusted).
	 *
	 * @return array<string, mixed>|null The record, or null when skipped or unresolvable.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 */
	private function embeddedRecord(mixed $entry): ?array {
		if (is_array($entry) === true) {
			return $entry;
		}

		if (is_string($entry) === true && $entry !== '') {
			return $this->resolveReference(uuid: $entry);
		}

		return null;
	}//end embeddedRecord()

	/**
	 * Resolve a single uuid reference to its payload, RBAC + tenant scoped.
	 *
	 * @param string $uuid Referenced object's uuid/id.
	 *
	 * @return array<string, mixed>|null Resolved payload, or null on lookup failure.
	 *
	 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#2.1
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function resolveReference(string $uuid): ?array {
		try {
			$entity = WriteCause::asLookup(fn () => $this->objectService->find(id: $uuid, _rbac: true, _multitenancy: true));
		} catch (Throwable) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return ($entity->getObject() ?? []);
	}//end resolveReference()
}//end class
