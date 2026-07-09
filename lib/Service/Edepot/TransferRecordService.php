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
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Persist + load durable e-Depot transfer + proof records via ObjectService.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 *   (Requirement: Durable transfer-list objects served over the API)
 */
class TransferRecordService
{

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
     * @param ObjectService   $objectService The RBAC/tenant-scoped, audited object store.
     * @param LoggerInterface $logger        Structured logging.
     */
    public function __construct(
        private readonly ObjectService $objectService,
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
    public function saveTransferList(array $transferList): array
    {
        $uuid = (string) ($transferList['uuid'] ?? '');

        $data = $transferList;
        // The list's own uuid IS the object uuid — keep them aligned so
        // subsequent status writes update the same durable object.
        $uuidArg = null;
        if ($uuid !== '') {
            $data['@self'] = ['uuid' => $uuid];
            $uuidArg       = $uuid;
        }

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::TRANSFER_SCHEMA_SLUG,
            uuid: $uuidArg
        );

        $rendered         = $saved->getObject();
        $rendered['uuid'] = (string) $saved->getUuid();

        return $rendered;

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
     */
    public function loadTransferList(string $uuid): ?array
    {
        try {
            $object = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::TRANSFER_SCHEMA_SLUG
            );
        } catch (\Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        $data         = $object->getObject();
        $data['uuid'] = (string) $object->getUuid();

        return $data;

    }//end loadTransferList()

    /**
     * List all durable transfer-list records (newest first), RBAC/tenant scoped.
     *
     * @return array<int, array<string, mixed>> The transfer-list records.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
     *   (Scenario: Index returns persisted transfer lists)
     */
    public function listTransferLists(): array
    {
        try {
            $rows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::REGISTER_SLUG,
                        'schema'   => self::TRANSFER_SCHEMA_SLUG,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[TransferRecordService] transfer-list enumeration failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $lists = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $lists[] = $row;
            }
        }

        return $lists;

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
    public function createProof(array $proof): array
    {
        $objectUuid   = (string) ($proof['objectUuid'] ?? '');
        $transferUuid = (string) ($proof['transferUuid'] ?? '');

        $existing = $this->findProof(objectUuid: $objectUuid, transferUuid: $transferUuid);
        if ($existing !== null) {
            return $existing;
        }

        if (isset($proof['confirmedAt']) === false) {
            $proof['confirmedAt'] = (new DateTime())->format('c');
        }

        $saved = $this->objectService->createObject(
            data: $proof + ['@self' => ['register' => self::REGISTER_SLUG, 'schema' => self::PROOF_SCHEMA_SLUG]]
        );

        $rendered         = $saved->getObject();
        $rendered['uuid'] = (string) $saved->getUuid();

        return $rendered;

    }//end createProof()

    /**
     * Find an existing proof for a (transfer, object) pair, or null.
     *
     * @param string $objectUuid   The transferred object's uuid.
     * @param string $transferUuid The transfer list's uuid.
     *
     * @return array<string, mixed>|null The proof data, or null when none exists.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
     *   (Scenario: Proof is write-once)
     */
    public function findProof(string $objectUuid, string $transferUuid): ?array
    {
        if ($objectUuid === '' || $transferUuid === '') {
            return null;
        }

        try {
            $rows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register'     => self::REGISTER_SLUG,
                        'schema'       => self::PROOF_SCHEMA_SLUG,
                        'objectUuid'   => $objectUuid,
                        'transferUuid' => $transferUuid,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) === true) {
                return $row;
            }
        }

        return null;

    }//end findProof()
}//end class
