<?php

/**
 * OpenRegister e-Depot Transfer Object Gatherer
 *
 * Which objects of a transfer list are still outstanding, and the objects
 * with their file metadata that one attempt packages.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\RecordState;

/**
 * Reads the objects a transfer attempt sends.
 *
 * An object whose retention already says it was transferred is never resent
 * (partial-success awareness). Both vocabularies count: stored data is not
 * migrated, so a record transferred before GAP A4 still holds `overgebracht`.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */
final class TransferObjectGatherer {

	/**
	 * Constructor.
	 *
	 * @param TransferStores       $stores               The objects and the log.
	 * @param PackagedFileChecksum $packagedFileChecksum Computes, dates and fixity-checks each file's checksum.
	 */
	public function __construct(
		private readonly TransferStores $stores,
		private readonly PackagedFileChecksum $packagedFileChecksum,
	) {
	}//end __construct()

	/**
	 * The object references not yet confirmed transferred.
	 *
	 * An unloadable object stays in the outstanding set so its failure is
	 * recorded rather than silently dropped.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 *
	 * @return array<int, array<string,mixed>> The outstanding object references.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
	 *   (Scenario: Retry excludes already-confirmed objects)
	 */
	public function outstanding(array $transferList): array {
		$refs = ($transferList['objectReferences'] ?? []);
		if (is_array($refs) === false) {
			return [];
		}

		$outstanding = [];
		foreach (self::validReferences(refs: $refs) as $ref) {
			if ($this->alreadyTransferred(ref: $ref) === false) {
				$outstanding[] = $ref;
			}
		}

		return $outstanding;
	}//end outstanding()

	/**
	 * Terminal status when no objects are outstanding: `completed` when every
	 * declared object is already confirmed, else `failed`.
	 *
	 * @param array<string,mixed> $transferList The transfer list data.
	 *
	 * @return string The terminal status.
	 */
	public function terminalStatus(array $transferList): string {
		$refs = ($transferList['objectReferences'] ?? []);
		if (is_array($refs) === false || $refs === []) {
			return TransferListService::STATUS_FAILED;
		}

		foreach (self::validReferences(refs: $refs) as $ref) {
			if ($this->confirmed(ref: $ref) === false) {
				return TransferListService::STATUS_FAILED;
			}
		}

		return TransferListService::STATUS_COMPLETED;
	}//end terminalStatus()

	/**
	 * The objects and their file metadata for SIP building. An object that
	 * cannot be loaded, or whose file fails its fixity check, is left out and
	 * logged by uuid.
	 *
	 * @param array<int, array<string,mixed>> $objectRefs Object references.
	 *
	 * @return array<int, array{object: ObjectEntity, files: array<int, array<string,mixed>>}> Objects with file metadata.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
	 */
	public function gather(array $objectRefs): array {
		$result = [];
		foreach ($objectRefs as $ref) {
			try {
				$object   = $this->stores->objects->find($ref['uuid']);
				$result[] = ['object' => $object, 'files' => $this->filesOf(object: $object)];
			} catch (\Exception $e) {
				$this->stores->logger->warning(
					message: '[EdepotTransferService] Could not load object for transfer',
					context: ['uuid' => $ref['uuid'], 'error' => $e->getMessage()]
				);
			}
		}

		return $result;
	}//end gather()

	/**
	 * File metadata for an object, each checksum computed and dated at
	 * packaging by {@see PackagedFileChecksum}.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, array<string, mixed>> File metadata (name, size, format, checksum, checksumDate, path, isRendition).
	 *
	 * @throws \RuntimeException When a file cannot be read or its stored SHA-256 no longer matches.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
	 */
	public function filesOf(ObjectEntity $object): array {
		$files = [];
		foreach (self::fileReferences(object: $object) as $fileRef) {
			$entry = $this->fileEntry(fileRef: $fileRef);
			if ($entry !== null) {
				$files[] = $entry;
			}
		}

		return $files;
	}//end filesOf()

	/**
	 * The well-formed references of a transfer list.
	 *
	 * @param array<mixed> $refs The declared references.
	 *
	 * @return list<array<string, mixed>> The references that carry a uuid.
	 */
	private static function validReferences(array $refs): array {
		return array_values(
			array_filter($refs, static fn (mixed $ref): bool => is_array($ref) === true && empty($ref['uuid']) === false)
		);
	}//end validReferences()

	/**
	 * Whether an object was ingested on a prior attempt; an unloadable one was not.
	 *
	 * @param array<string, mixed> $ref The object reference.
	 *
	 * @return bool True when it must not be resent.
	 */
	private function alreadyTransferred(array $ref): bool {
		try {
			return $this->isTransferred(uuid: $ref['uuid']);
		} catch (\Throwable $e) {
			$this->stores->logger->debug(
				message: '[EdepotTransferService] outstanding-check could not load object '.((string)$ref['uuid']),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}
	}//end alreadyTransferred()

	/**
	 * Whether an object is confirmed transferred; an unloadable one is not.
	 *
	 * @param array<string, mixed> $ref The object reference.
	 *
	 * @return bool True when confirmed.
	 */
	private function confirmed(array $ref): bool {
		try {
			return $this->isTransferred(uuid: $ref['uuid']);
		} catch (\Throwable $e) {
			return false;
		}
	}//end confirmed()

	/**
	 * Whether the stored object's retention says it was transferred.
	 *
	 * @param mixed $uuid The object uuid.
	 *
	 * @return bool True when transferred.
	 *
	 * @throws \Throwable When the object cannot be loaded.
	 */
	private function isTransferred(mixed $uuid): bool {
		$retention = ($this->stores->objects->find($uuid)->getRetention() ?? []);

		return in_array(($retention['archiefstatus'] ?? ''), RecordState::TRANSFERRED_ALIASES, true) === true;
	}//end isTransferred()

	/**
	 * The file references an object carries.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<mixed> The references, or [] when malformed.
	 */
	private static function fileReferences(ObjectEntity $object): array {
		$objectData = ($object->getObject() ?? []);
		$fileRefs   = ($objectData['_files'] ?? $objectData['bijlagen'] ?? []);
		if (is_array($fileRefs) === false) {
			return [];
		}

		return $fileRefs;
	}//end fileReferences()

	/**
	 * The metadata of one referenced file, or null when it is not on disk.
	 *
	 * @param mixed $fileRef The file reference.
	 *
	 * @return array<string, mixed>|null The metadata.
	 */
	private function fileEntry(mixed $fileRef): ?array {
		if (is_array($fileRef) === false) {
			return null;
		}

		$path = ($fileRef['path'] ?? '');
		if (empty($path) === true || file_exists($path) === false) {
			return null;
		}

		return $this->describe(fileRef: $fileRef, path: $path);
	}//end fileEntry()

	/**
	 * Describe a file that exists, with a freshly computed checksum.
	 *
	 * @param array<string, mixed> $fileRef The file reference.
	 * @param string               $path    The file's path.
	 *
	 * @return array<string, mixed> The metadata.
	 */
	private function describe(array $fileRef, string $path): array {
		$checksum = $this->packagedFileChecksum->compute(path: $path, stored: ($fileRef['checksum'] ?? null));

		return [
			'name' => ($fileRef['name'] ?? basename($path)),
			'size' => (int)($fileRef['size'] ?? filesize($path)),
			'format' => ($fileRef['mimeType'] ?? ($fileRef['format'] ?? 'application/octet-stream')),
			'checksum' => $checksum['checksum'],
			'checksumDate' => $checksum['checksumDate'],
			'path' => $path,
			'isRendition' => (bool)($fileRef['isRendition'] ?? false),
		];
	}//end describe()
}//end class
