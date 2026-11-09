<?php

/**
 * OpenRegister e-Depot Transfer Outcome Recorder
 *
 * Writes the outcome of a delivery onto each object's retention metadata and
 * creates the proof-of-transfer of each confirmed object.
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
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\RecordState;
use Psr\Log\LoggerInterface;

/**
 * Records one object's transfer outcome.
 *
 * An accepted object is marked transferred, audited and proven, in that
 * order; a refused object gets a transfer error and keeps its archival state.
 * Each write is best-effort: a failing write is logged and never unwinds a
 * confirmed transfer.
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */
final class TransferOutcomeRecorder {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface  $objectMapper          The object mapper.
	 * @param TransferRecordService $transferRecordService Durable transfer + proof persistence.
	 * @param TransferAuditLog      $auditLog              The transfer audit log.
	 * @param LoggerInterface       $logger                Logger.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly TransferRecordService $transferRecordService,
		private readonly TransferAuditLog $auditLog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record an object the e-Depot accepted: mark it transferred, audit it,
	 * and create its durable, immutable proof-of-transfer (OR-AD-3).
	 *
	 * @param array<string,mixed>  $item      The object with its files.
	 * @param array<string, mixed> $objResult The per-object transport result.
	 * @param TransferContext      $context   The delivery the object was confirmed in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Scenario: Proof created on confirmed transfer)
	 */
	public function recordAccepted(array $item, array $objResult, TransferContext $context): void {
		$object    = $item['object'];
		$reference = ($objResult['reference'] ?? '');
		$this->markTransferred(object: $object, reference: $reference, timestamp: $context->confirmedAt);
		$this->auditLog->objectTransferred(object: $object, transferUuid: $context->transferUuid, reference: $reference);
		$this->createProofRecord(item: $item, objResult: $objResult, context: $context);
	}//end recordAccepted()

	/**
	 * Record an object the e-Depot refused: append a transfer error.
	 *
	 * @param ObjectEntity $object    The object to update.
	 * @param string       $error     The error message.
	 * @param string       $timestamp The failure timestamp.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
	 */
	public function recordRefused(ObjectEntity $object, string $error, string $timestamp): void {
		$retention = ($object->getRetention() ?? []);
		if (isset($retention['transferErrors']) === false || is_array($retention['transferErrors']) === false) {
			$retention['transferErrors'] = [];
		}

		$retention['transferErrors'][] = ['error' => $error, 'timestamp' => $timestamp];
		$object->setRetention($retention);
		$this->save(object: $object, failure: 'Failed to update object transfer error');
	}//end recordRefused()

	/**
	 * Mark an object as successfully transferred.
	 *
	 * @param ObjectEntity $object    The object to update.
	 * @param string       $reference The e-Depot reference identifier.
	 * @param string       $timestamp The transfer timestamp.
	 *
	 * @return void
	 */
	private function markTransferred(ObjectEntity $object, string $reference, string $timestamp): void {
		$retention = ($object->getRetention() ?? []);
		$retention['archiefstatus']    = RecordState::TRANSFERRED;
		$retention['eDepotReferentie'] = $reference;
		$retention['transferDate']     = $timestamp;
		$object->setRetention($retention);
		$this->save(object: $object, failure: 'Failed to update object status to overgebracht');
	}//end markTransferred()

	/**
	 * Store an object; a failure is logged, never thrown.
	 *
	 * @param ObjectEntity $object  The object.
	 * @param string       $failure The message logged when the update fails.
	 *
	 * @return void
	 */
	private function save(ObjectEntity $object, string $failure): void {
		try {
			$this->objectMapper->update($object);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[EdepotTransferService] '.$failure,
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
		}
	}//end save()

	/**
	 * Create the proof-of-transfer of one confirmed object and link its UUID
	 * onto the object's retention metadata, next to the existing
	 * `eDepotReferentie` (additive). Write-once and best-effort: a
	 * proof-creation failure never unwinds the confirmed transfer.
	 *
	 * @param array<string,mixed>  $item      The object with its files.
	 * @param array<string, mixed> $objResult The per-object transport result.
	 * @param TransferContext      $context   The delivery the object was confirmed in.
	 *
	 * @return void
	 */
	private function createProofRecord(array $item, array $objResult, TransferContext $context): void {
		$object = $item['object'];
		try {
			$proof = $this->transferRecordService->createProof(
				proof: self::proofOf(object: $object, files: ($item['files'] ?? []), objResult: $objResult, context: $context)
			);
			$this->linkProof(object: $object, proofUuid: (string)($proof['uuid'] ?? ''));
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[EdepotTransferService] proof-of-transfer creation failed for '.((string)$object->getUuid()).': '.$e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end createProofRecord()

	/**
	 * The proof record of one confirmed object.
	 *
	 * @param ObjectEntity         $object    The confirmed object.
	 * @param array<int, mixed>    $files     The object's file metadata (name + checksum).
	 * @param array<string, mixed> $objResult The per-object transport result.
	 * @param TransferContext      $context   The delivery the object was confirmed in.
	 *
	 * @return array<string, mixed> The proof.
	 */
	private static function proofOf(ObjectEntity $object, array $files, array $objResult, TransferContext $context): array {
		$transferUuid = (string)($context->transferUuid ?? '');

		return array_merge(
			[
				'objectUuid' => (string)$object->getUuid(),
				'transferUuid' => $transferUuid,
				'eDepotReference' => (string)($objResult['reference'] ?? ''),
				'transportReceipt' => (string)($objResult['receipt'] ?? ($objResult['reference'] ?? '')),
			],
			self::packageOf(files: $files, context: $context, packageId: $transferUuid)
		);
	}//end proofOf()

	/**
	 * The package part of a proof: transport, package and file checksums.
	 *
	 * @param array<int, mixed> $files     The object's file metadata.
	 * @param TransferContext   $context   The delivery the object was confirmed in.
	 * @param string            $packageId The package id (the transfer list UUID).
	 *
	 * @return array<string, mixed> The package part.
	 */
	private static function packageOf(array $files, TransferContext $context, string $packageId): array {
		$fileChecksums = self::fileChecksums(files: $files);

		return [
			'transportName' => $context->transportName,
			'packageId' => $packageId,
			'packageFormat' => $context->packageFormat,
			'packageManifestSha256' => hash('sha256', (string)json_encode($fileChecksums)),
			'fileChecksums' => $fileChecksums,
			'confirmedAt' => $context->confirmedAt,
		];
	}//end packageOf()

	/**
	 * The name and SHA-256 of every named file.
	 *
	 * @param array<int, mixed> $files The object's file metadata.
	 *
	 * @return list<array{name: string, sha256: string}> The checksums.
	 */
	private static function fileChecksums(array $files): array {
		$fileChecksums = [];
		foreach ($files as $file) {
			if (is_array($file) === true && empty($file['name']) === false) {
				$fileChecksums[] = ['name' => (string)$file['name'], 'sha256' => (string)($file['checksum'] ?? '')];
			}
		}

		return $fileChecksums;
	}//end fileChecksums()

	/**
	 * Link a created proof onto the object's retention (additive — existing
	 * eDepotReferentie/transferDate readers keep working).
	 *
	 * @param ObjectEntity $object    The confirmed object.
	 * @param string       $proofUuid The proof's UUID, or '' when none came back.
	 *
	 * @return void
	 */
	private function linkProof(ObjectEntity $object, string $proofUuid): void {
		if ($proofUuid === '') {
			return;
		}

		$retention = ($object->getRetention() ?? []);
		$retention['transferProof'] = $proofUuid;
		$object->setRetention($retention);
		$this->objectMapper->update($object);
	}//end linkProof()
}//end class
