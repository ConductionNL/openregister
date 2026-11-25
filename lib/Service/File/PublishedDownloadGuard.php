<?php

/**
 * OpenRegister PublishedDownloadGuard
 *
 * Checks a published file against its sealed digest before it is served (REQ-PFD-002).
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

use Exception;
use OCA\OpenRegister\Db\FileDigestMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The download side of a publication's seal.
 *
 * A file with an open digest record is the published file, so before it is
 * served its bytes are compared with the record: an unchanged etag and size
 * skip the recompute ({@see PublishedDigestService::verify()}). When they
 * match the caller gets the `Repr-Digest` value to send (RFC 9530). When they
 * do not, the mismatch is logged as an error for operations, written to the
 * audit trail as `file.digest-mismatch`, and the download is refused: a
 * changed file is never served as the published one.
 *
 * A file that is not published has no record and passes unchecked.
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class PublishedDownloadGuard {

	/**
	 * Constructor.
	 *
	 * @param FileDigestMapper       $digests The digest records.
	 * @param PublishedDigestService $service Compares a file with its record and writes the mismatch row.
	 * @param LoggerInterface        $logger  Where operations reads the alert.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function __construct(
		private readonly FileDigestMapper $digests,
		private readonly PublishedDigestService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `Repr-Digest` value of a file about to be downloaded, or null when it is not published.
	 *
	 * @param File              $file   The file the caller may read.
	 * @param ObjectEntity|null $object The object the file belongs to, for the audit row.
	 *
	 * @return string|null `sha-256=:<base64>:`, or null when the file has no open record.
	 *
	 * @throws PublishedFileChangedException When the bytes no longer match the recorded digest.
	 * @throws Exception When the file cannot be read to compare it.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public function reprDigestFor(File $file, ?ObjectEntity $object): ?string {
		$record = $this->digests->findOpenForFile(fileId: (int)$file->getId());
		if ($record === null) {
			return null;
		}

		$result = $this->service->verify(record: $record, file: $file);
		if ($result['verdict'] === PublishedDigestService::IDENTICAL) {
			return self::reprDigest(sha256: $result['recorded']);
		}

		$this->logger->error(
			message: '[PublishedDownloadGuard] A published file no longer matches its digest; the download was refused',
			context: [
				'fileId' => $record->getFileId(),
				'objectUuid' => $record->getObjectUuid(),
				'recorded' => $result['recorded'],
				'current' => $result['current'],
			]
		);

		if ($object !== null) {
			try {
				$this->service->recordMismatch(object: $object, record: $record, current: $result['current']);
			} catch (Throwable $e) {
				$this->logger->error(
					message: '[PublishedDownloadGuard] The mismatch could not be written to the audit trail: ' . $e->getMessage(),
					context: ['fileId' => $record->getFileId()]
				);
			}
		}

		throw new PublishedFileChangedException(fileId: (int)$record->getFileId());
	}//end reprDigestFor()

	/**
	 * The RFC 9530 `Repr-Digest` field value of a hex SHA-256.
	 *
	 * @param string $sha256 The lowercase hex digest.
	 *
	 * @return string `sha-256=:<base64>:`.
	 *
	 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
	 */
	public static function reprDigest(string $sha256): string {
		return 'sha-256=:' . base64_encode((string)hex2bin($sha256)) . ':';
	}//end reprDigest()
}//end class
