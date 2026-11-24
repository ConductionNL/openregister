<?php

/**
 * PdfDocumentSanitizer
 *
 * The per-file entry point for sanitising a PDF, beside
 * {@see OfficeDocumentSanitizer} for office documents. One pass removes the
 * /Info identity fields and XMP identity namespaces (the scope of
 * {@see Pdf\PdfMetadataSanitizer}), annotations, embedded files, JavaScript,
 * /OpenAction and /AA, and writes the result as one full save of the objects
 * still referenced, so earlier revisions and orphaned objects are gone. Page
 * content is not touched. In a PDF/A-3 document an attachment declared
 * /AFRelationship /Source or /Data is kept with its /AF association and
 * listed in the report. An encrypted PDF is refused, never half-processed.
 *
 * The source file is never changed: the result is a temporary file the caller
 * stores where it wants.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Files\Service\File
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfPayloadStripper;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfReachableSerialiser;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sanitises one PDF into a temporary file.
 */
class PdfDocumentSanitizer {

	public const MIME = 'application/pdf';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder            $rootFolder  Resolves the file.
	 * @param ITempManager           $tempManager Allocates the result file.
	 * @param PdfMetadataSanitizer   $metadata    Strips /Info and XMP identity fields.
	 * @param LoggerInterface        $logger      The log.
	 * @param PdfPayloadStripper     $payload     Strips annotations, embedded files, JavaScript and actions.
	 * @param PdfReachableSerialiser $serialiser  Writes the full save.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ITempManager $tempManager,
		private readonly PdfMetadataSanitizer $metadata,
		private readonly LoggerInterface $logger,
		private readonly PdfPayloadStripper $payload = new PdfPayloadStripper(),
		private readonly PdfReachableSerialiser $serialiser = new PdfReachableSerialiser(),
	) {
	}//end __construct()

	/**
	 * Whether this sanitiser handles a MIME type.
	 *
	 * @param string $mimeType The MIME type.
	 *
	 * @return bool True for a PDF.
	 */
	public function isSanitizable(string $mimeType): bool {
		return $mimeType === self::MIME;

	}//end isSanitizable()

	/**
	 * Sanitise a stored PDF into a temporary file.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return PdfSanitizationResult The temporary file and the report.
	 *
	 * @throws SanitizationException When the file is not a PDF, is encrypted, or cannot be sanitised.
	 */
	public function sanitize(int $fileId): PdfSanitizationResult {
		$file = ($this->rootFolder->getById($fileId)[0] ?? null);
		if (($file instanceof File) === false || $file->getMimeType() !== self::MIME) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_UNSUPPORTED_MIME,
				message: 'Sanitisation target is not a PDF file'
			);
		}

		try {
			$bytes = (string) $file->getContent();
		} catch (Throwable $e) {
			throw new SanitizationException(reason: SanitizationException::REASON_INTERNAL, message: 'Could not read the PDF', previous: $e);
		}

		[$clean, $report] = $this->sanitizeBytes(bytes: $bytes);

		$tempPath = $this->tempManager->getTemporaryFile('.pdf');
		if ($tempPath === false || file_put_contents($tempPath, $clean) === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_INTERNAL,
				message: 'Could not write the sanitised PDF to a temporary file'
			);
		}

		$this->logger->info(
			message: '[PdfDocumentSanitizer] PDF sanitised',
			context: ['fileId' => $fileId, 'counts' => $report->jsonSerialize()]
		);

		return new PdfSanitizationResult(path: $tempPath, report: $report);

	}//end sanitize()

	/**
	 * Sanitise PDF bytes, for a caller that holds the bytes rather than a file
	 * (a final artifact before it is stored).
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return array{0: string, 1: PdfSanitizationReport} The sanitised bytes and the report.
	 *
	 * @throws SanitizationException When the PDF is encrypted or cannot be sanitised.
	 */
	public function sanitizeBytes(string $bytes): array {
		if (str_starts_with(ltrim($bytes), '%PDF') === false) {
			throw new SanitizationException(reason: SanitizationException::REASON_UNSUPPORTED_MIME, message: 'The bytes are not a PDF');
		}

		if (preg_match('#/Encrypt\s*(\d+\s+\d+\s+R|<<)#', $bytes) === 1) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_ENCRYPTED,
				message: 'The PDF is encrypted; decrypt it before sanitising'
			);
		}

		try {
			$doc = PDFDoc::from_string(buffer: $bytes);
		} catch (Throwable $e) {
			$doc = false;
		}

		if ($doc === false) {
			throw new SanitizationException(reason: SanitizationException::REASON_INTERNAL, message: 'The PDF could not be parsed');
		}

		try {
			$metadata = $this->metadata->sanitize(doc: $doc);
			$stripped = $this->payload->strip(doc: $doc, isPdfA3: PdfPayloadStripper::declaresPdfA3(bytes: $bytes));
			$clean = $this->serialiser->serialise(doc: $doc);
		} catch (Throwable $e) {
			throw new SanitizationException(reason: SanitizationException::REASON_INTERNAL, message: 'PDF sanitisation failed', previous: $e);
		}

		return [
			$clean,
			new PdfSanitizationReport(
				metadataFieldsScrubbed: (int) $metadata['info_fields_stripped'],
				xmpScrubbed: (bool) $metadata['xmp_stripped'],
				annotationsRemoved: $stripped['annotationsRemoved'],
				embeddedFilesRemoved: $stripped['embeddedFilesRemoved'],
				javascriptRemoved: $stripped['javascriptRemoved'],
				openActionsRemoved: $stripped['openActionsRemoved'],
				additionalActionsRemoved: $stripped['additionalActionsRemoved'],
				preservedAttachments: $stripped['preservedAttachments'],
			),
		];

	}//end sanitizeBytes()
}//end class
