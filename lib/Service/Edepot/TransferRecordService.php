<?php

/**
 * OpenRegister e-Depot Transfer Record Service
 *
 * Durable persistence for e-Depot transfer records (archival-transfer-hardening,
 * OR-AD-3): the `edepotTransfer` transfer-list object and the immutable
 * per-object `edepotTransferProof` proof-of-transfer records, stored in the
 * `edepot-transfers` system register through `ObjectService` (audited write
 * path, RBAC/tenant scoped). This makes the former placeholder comments in
 * `TransferController` true — transfer lists round-trip as real objects and
 * their status transitions ride OR's normal write path.
 *
 * The service is a thin persistence adapter: `TransferListService` keeps its
 * array-based status machine; this service saves/loads those arrays as
 * objects and assembles proof records. Proof records are write-once — a
 * second create for the same (transfer, object) pair is refused (belt to the
 * schema-level `immutable` flag).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;

/**
 * Persist + load durable e-Depot transfer + proof records via ObjectService.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 *   (Requirement: Durable transfer-list objects served over the API)
 */
class TransferRecordService implements TransferRecordServiceInterface, TransferRecordStoreInterface {

	/**
	 * Register slug the durable transfer records live under.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'edepot-transfers';

	/**
	 * Schema slug for the durable transfer list.
	 *
	 * @var string
	 */
	public const TRANSFER_SCHEMA_SLUG = 'edepotTransfer';

	/**
	 * Schema slug for the immutable proof-of-transfer record.
	 *
	 * @var string
	 */
	public const PROOF_SCHEMA_SLUG = 'edepotTransferProof';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService The RBAC/tenant-scoped, audited object store.
	 * @param LoggerInterface $logger Structured logging.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Persist (create or update) a transfer-list record.
	 *
	 * Keyed by the transfer list's own `uuid`, so status transitions on the
	 * same list update the one durable object. Returns the persisted data.
	 *
	 * @param array<string, mixed> $transferList The transfer-list array (from TransferListService).
	 *
	 * @return array<string, mixed> The persisted transfer-list data (with its object uuid).
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	public function saveTransferList(array $transferList): array {
		$uuid = (string)($transferList['uuid'] ?? '');

		// The list's own uuid IS the object uuid — keep them aligned so
		// subsequent status writes update the same durable object.
		$saved = $this->objectService->saveObject(
			object: self::alignedWith(transferList: $transferList, uuid: $uuid),
			register: self::REGISTER_SLUG,
			schema: self::TRANSFER_SCHEMA_SLUG,
			uuid: self::uuidOrNull(uuid: $uuid)
		);

		return self::rendered(object: $saved);
	}//end saveTransferList()

	/**
	 * Load a transfer-list record by uuid, or null when absent.
	 *
	 * @param string $uuid The transfer-list uuid.
	 *
	 * @return array<string, mixed>|null The transfer-list data, or null.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Show returns a persisted transfer list)
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function loadTransferList(string $uuid): ?array {
		$object = $this->lookup(uuid: $uuid);
		if ($object === null) {
			return null;
		}

		return self::rendered(object: $object);
	}//end loadTransferList()

	/**
	 * List all durable transfer-list records (newest first), RBAC/tenant scoped.
	 *
	 * @return array<int, array<string, mixed>> The transfer-list records.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Index returns persisted transfer lists)
	 */
	public function listTransferLists(): array {
		try {
			$rows = $this->rows(filters: ['register' => self::REGISTER_SLUG, 'schema' => self::TRANSFER_SCHEMA_SLUG]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TransferRecordService] transfer-list enumeration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) === true));
	}//end listTransferLists()

	/**
	 * Create an immutable proof-of-transfer record for one confirmed object.
	 *
	 * Write-once: if a proof already exists for this (transfer, object) pair
	 * it is returned unchanged rather than duplicated (idempotent re-run
	 * safety; belt to the schema `immutable` flag).
	 *
	 * @param array<string, mixed> $proof The proof field set (objectUuid, transferUuid, eDepotReference, …).
	 *
	 * @return array<string, mixed> The persisted (or pre-existing) proof data.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Proof created on confirmed transfer)
	 */
	public function createProof(array $proof): array {
		$existing = $this->findProof(
			objectUuid: (string)($proof['objectUuid'] ?? ''),
			transferUuid: (string)($proof['transferUuid'] ?? '')
		);
		if ($existing !== null) {
			return $existing;
		}

		$saved = $this->objectService->createObject(
			data: self::dated(proof: $proof) + ['@self' => ['register' => self::REGISTER_SLUG, 'schema' => self::PROOF_SCHEMA_SLUG]]
		);

		return self::rendered(object: $saved);
	}//end createProof()

	/**
	 * Find an existing proof for a (transfer, object) pair, or null.
	 *
	 * @param string $objectUuid The transferred object's uuid.
	 * @param string $transferUuid The transfer list's uuid.
	 *
	 * @return array<string, mixed>|null The proof data, or null when none exists.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Proof is write-once)
	 */
	public function findProof(string $objectUuid, string $transferUuid): ?array {
		if ($objectUuid === '' || $transferUuid === '') {
			return null;
		}

		$filters = ['register' => self::REGISTER_SLUG, 'schema' => self::PROOF_SCHEMA_SLUG, 'objectUuid' => $objectUuid, 'transferUuid' => $transferUuid];
		try {
			return self::firstArray(rows: $this->rows(filters: $filters));
		} catch (\Throwable $e) {
			return null;
		}
	}//end findProof()
	/**
	 * The list data to save, with `@self.uuid` set when the list has a uuid.
	 *
	 * @param array<string, mixed> $transferList The transfer list.
	 * @param string               $uuid         The list's uuid, or ''.
	 *
	 * @return array<string, mixed> The data.
	 */
	private static function alignedWith(array $transferList, string $uuid): array {
		if ($uuid !== '') {
			$transferList['@self'] = ['uuid' => $uuid];
		}

		return $transferList;
	}//end alignedWith()

	/**
	 * A proof with its confirmation moment, now when it carries none.
	 *
	 * @param array<string, mixed> $proof The proof.
	 *
	 * @return array<string, mixed> The dated proof.
	 */
	private static function dated(array $proof): array {
		if (isset($proof['confirmedAt']) === false) {
			$proof['confirmedAt'] = (new DateTime())->format('c');
		}

		return $proof;
	}//end dated()

	/**
	 * A uuid, or null when empty.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return string|null The uuid.
	 */
	private static function uuidOrNull(string $uuid): ?string {
		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end uuidOrNull()

	/**
	 * A stored object's data with its uuid.
	 *
	 * @param ObjectEntity $object The stored object.
	 *
	 * @return array<string, mixed> The data.
	 */
	private static function rendered(ObjectEntity $object): array {
		$rendered         = $object->getObject();
		$rendered['uuid'] = (string)$object->getUuid();

		return $rendered;
	}//end rendered()

	/**
	 * Look a transfer list object up; a failed lookup is null.
	 *
	 * @param string $uuid The transfer list uuid.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function lookup(string $uuid): ?ObjectEntity {
		try {
			return WriteCause::asLookup(fn () => $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::TRANSFER_SCHEMA_SLUG)
			));
		} catch (\Throwable $e) {
			return null;
		}
	}//end lookup()

	/**
	 * The rows matching filters.
	 *
	 * @param array<string, string> $filters The filters.
	 *
	 * @return array<mixed> The rows.
	 *
	 * @throws \Throwable When the store cannot be read.
	 */
	private function rows(array $filters): array {
		return $this->objectService->findAll(config: ['filters' => $filters]);
	}//end rows()

	/**
	 * The first array row, or null.
	 *
	 * @param array<mixed> $rows The rows.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private static function firstArray(array $rows): ?array {
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end firstArray()
}//end class
