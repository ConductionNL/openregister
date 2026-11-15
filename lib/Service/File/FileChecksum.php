<?php

/**
 * The SHA-256 a file was stored with, and whether its content still matches it.
 *
 * Computed when a file is uploaded or its content replaced, and kept in the
 * file's Nextcloud metadata under the key a URL-fetched file already carries
 * (`openregister-sha256`), so one key means one thing whichever way the file
 * arrived. The fixity check reads the content again and compares.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\Files\File;
use OCP\FilesMetadata\IFilesMetadataManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Records a file's SHA-256 and checks its fixity against it.
 *
 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
 */
class FileChecksum {

	/**
	 * The metadata key of the stored SHA-256, lowercase hex.
	 */
	public const METADATA_KEY = UrlFileSource::KEY_SHA256;

	/**
	 * The content matches the stored checksum.
	 */
	public const UNCHANGED = 'unchanged';

	/**
	 * The content no longer matches the stored checksum.
	 */
	public const CHANGED = 'changed';

	/**
	 * No checksum was stored, so there is nothing to compare with.
	 */
	public const UNRECORDED = 'unrecorded';

	/**
	 * Bytes read per step while hashing.
	 */
	private const CHUNK_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param IFilesMetadataManager $metadataManager Where the checksum is kept.
	 * @param LoggerInterface       $logger          Logger.
	 */
	public function __construct(
		private readonly IFilesMetadataManager $metadataManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the file's SHA-256 and store it with its metadata.
	 *
	 * Best effort: the file is written already, and a missing checksum shows
	 * up as `unrecorded` in a later check rather than failing the upload.
	 *
	 * @param File $file The file just written.
	 *
	 * @return bool True when the checksum was stored.
	 *
	 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
	 */
	public function record(File $file): bool {
		try {
			$digest = $this->digestOf(file: $file);
			$metadata = $this->metadataManager->getMetadata($file->getId(), true);
			$metadata->setString(self::METADATA_KEY, $digest);
			$this->metadataManager->saveMetadata($metadata);
			return true;
		} catch (Throwable $error) {
			$this->logger->warning(
				message: '[FileChecksum] Could not store the checksum of a file',
				context: ['fileId' => $file->getId(), 'error' => $error->getMessage()]
			);
			return false;
		}
	}//end record()

	/**
	 * Check the file's content against the checksum stored with it.
	 *
	 * @param File $file The file.
	 *
	 * @return array{status: string, recorded: string, current: string} The verdict,
	 *         the stored checksum ('' when none) and the checksum of the content now.
	 *
	 * @throws RuntimeException When the file or its metadata cannot be read.
	 *
	 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
	 */
	public function check(File $file): array {
		$metadata = $this->metadataManager->getMetadata($file->getId(), true);
		$recorded = '';
		if ($metadata->hasKey(self::METADATA_KEY) === true) {
			$recorded = strtolower(trim($metadata->getString(self::METADATA_KEY)));
		}

		$current = $this->digestOf(file: $file);
		$status = self::CHANGED;
		if ($recorded === '') {
			$status = self::UNRECORDED;
		} else if (hash_equals($recorded, $current) === true) {
			$status = self::UNCHANGED;
		}

		return ['status' => $status, 'recorded' => $recorded, 'current' => $current];
	}//end check()

	/**
	 * The SHA-256 of a file's content, read as a stream in fixed chunks.
	 *
	 * @param File $file The file.
	 *
	 * @return string The lowercase hex digest.
	 *
	 * @throws RuntimeException When the file cannot be opened for reading.
	 *
	 * @spec openspec/changes/capability-rows-stated-requirements/specs/file-actions/spec.md
	 */
	public function digestOf(File $file): string {
		$handle = $file->fopen('r');
		if (is_resource($handle) === false) {
			throw new RuntimeException('The file cannot be opened for reading');
		}

		try {
			$context = hash_init('sha256');
			do {
				$read = hash_update_stream($context, $handle, self::CHUNK_BYTES);
			} while ($read > 0);

			return hash_final($context);
		} finally {
			fclose($handle);
		}
	}//end digestOf()
}//end class
