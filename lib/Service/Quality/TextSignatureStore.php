<?php

/**
 * OpenRegister TextSignatureStore
 *
 * Keeps one text signature per extracted file: computed and stored when the
 * file's text is indexed, removed when its chunks are replaced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use DateTime;
use OCA\OpenRegister\Db\TextSignature as TextSignatureRow;
use OCA\OpenRegister\Db\TextSignatureMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores and forgets the text signature of a file.
 *
 * A signature that cannot be written is logged and skipped: the file's text
 * is indexed all the same and the file is reported as not extracted in any
 * grouping until its next extraction.
 */
final class TextSignatureStore {

	/**
	 * Constructor.
	 *
	 * @param TextSignatureMapper $mapper The signature rows.
	 * @param LoggerInterface     $logger Where a failed write is reported.
	 * @param TextSignature       $signer Computes the signature.
	 */
	public function __construct(
		private readonly TextSignatureMapper $mapper,
		private readonly LoggerInterface $logger,
		private readonly TextSignature $signer=new TextSignature(),
	) {
	}//end __construct()

	/**
	 * Compute the signature of a file's text and store it in place of any earlier one.
	 *
	 * A text too short to sign still gets a row, without a signature and with its
	 * shingle count, so a grouping can say why the file was not compared.
	 *
	 * @param int    $fileId The Nextcloud file id.
	 * @param string $text   The file's extracted text.
	 *
	 * @return bool Whether the row was stored.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function store(int $fileId, string $text): bool {
		try {
			$record = $this->signer->sign(text: $text);
			$this->mapper->replaceForFile(row: TextSignatureRow::fromRecord(fileId: $fileId, record: $record, created: new DateTime()));
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[TextSignatureStore] The text signature of a file could not be stored',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $failure->getMessage()]
			);
			return false;
		}

		return true;
	}//end store()

	/**
	 * Remove the signature of a file whose chunks are replaced or removed.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return bool Whether the delete ran.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function forget(int $fileId): bool {
		try {
			$this->mapper->deleteByFileId(fileId: $fileId);
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[TextSignatureStore] The text signature of a file could not be removed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId, 'error' => $failure->getMessage()]
			);
			return false;
		}

		return true;
	}//end forget()
}//end class
