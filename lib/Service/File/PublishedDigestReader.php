<?php

/**
 * OpenRegister PublishedDigestReader
 *
 * Answers what a published file's digest is, and whether its bytes still match it (REQ-PFD-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\FileDigest;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\Files\File;
use Throwable;

/**
 * The read side of a publication's seal.
 *
 * It does not decide who may ask: the caller has already checked that the
 * person may read the file. It never writes either; a mismatch found here is
 * reported to the person asking, and recorded by the download path and the
 * nightly job, which own the alert.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class PublishedDigestReader {

	/**
	 * Constructor.
	 *
	 * @param FileDigestMapper          $digests     The digest records.
	 * @param PublishedDigestService    $service     Compares a file with its record.
	 * @param AuditTrailMapperInterface $auditTrails The audit row that sealed the publication.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function __construct(
		private readonly FileDigestMapper $digests,
		private readonly PublishedDigestService $service,
		private readonly AuditTrailMapperInterface $auditTrails,
	) {
	}//end __construct()

	/**
	 * Whether the file's bytes still match the digest recorded when it was published.
	 *
	 * @param File $file The file, which the caller may read.
	 *
	 * @return array{recorded: string, current: string, verdict: string, auditRow: array{uuid: string|null, hash: string|null, previousHash: string|null}}|null
	 *         The answer, or null when the file is not published.
	 *
	 * @throws Exception When the file cannot be read.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function verify(File $file): ?array {
		$record = $this->digests->findOpenForFile(fileId: (int)$file->getId());
		if ($record === null) {
			return null;
		}

		$result = $this->service->verify(record: $record, file: $file);

		return [
			'recorded' => $result['recorded'],
			'current' => $result['current'],
			'verdict' => $result['verdict'],
			'auditRow' => $this->sealOf(record: $record),
		];
	}//end verify()

	/**
	 * The `published` block of a file: its digest, when, and the row that sealed it.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return array{sha256: string|null, size: int|null, publishedAt: string|null, sealedBy: array{uuid: string|null, hash: string|null}}|null
	 *         The block, or null when the file is not published.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function publishedOf(int $fileId): ?array {
		try {
			$record = $this->digests->findOpenForFile(fileId: $fileId);
		} catch (Throwable $e) {
			// A listing never fails over the seal; the file is shown without it.
			return null;
		}

		if ($record === null) {
			return null;
		}

		$seal = $this->sealOf(record: $record);

		return [
			'sha256' => $record->getSha256(),
			'size' => $record->getSize(),
			'publishedAt' => $record->getPublishedAt()?->format(DateTimeInterface::ATOM),
			'sealedBy' => ['uuid' => $seal['uuid'], 'hash' => $seal['hash']],
		];
	}//end publishedOf()

	/**
	 * The uuid and the chain hashes of the audit row that sealed a publication.
	 *
	 * @param FileDigest $record The record.
	 *
	 * @return array{uuid: string|null, hash: string|null, previousHash: string|null} The seal; the hashes null when the row cannot be read.
	 */
	private function sealOf(FileDigest $record): array {
		$uuid = $record->getAuditTrailUuid();
		$seal = ['uuid' => $uuid, 'hash' => null, 'previousHash' => null];
		if ($uuid === null || $uuid === '') {
			return $seal;
		}

		try {
			$rows = $this->auditTrails->findTrail(query: new ListQuery(limit: 1, filters: ['uuid' => $uuid]));
		} catch (Throwable $e) {
			return $seal;
		}

		$row = ($rows[0] ?? null);
		if ($row instanceof AuditTrail === false) {
			return $seal;
		}

		return ['uuid' => $uuid, 'hash' => $row->getHash(), 'previousHash' => $row->getPreviousHash()];
	}//end sealOf()
}//end class
