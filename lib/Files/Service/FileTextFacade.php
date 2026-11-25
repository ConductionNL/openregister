<?php

/**
 * FileTextFacade — a file's text, read on demand as a person, for other apps.
 *
 * PUBLIC API — stable cross-app contract, like VectorSearchFacade. Other apps
 * (hermiq chat attachments and document chat) call these two methods by this
 * class name. A change to a signature needs an OpenSpec change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Files\Service\TextExtraction\TextExtractionReaders;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a file's text in the person's own folder with the extraction readers,
 * and writes nothing: no chunk, index, entity or mail header row.
 *
 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
 */
class FileTextFacade {

	/**
	 * Mime types read directly as text besides `text/*`.
	 *
	 * @var list<string>
	 */
	private const PLAIN_TYPES = ['application/json', 'application/xml', 'application/x-yaml'];

	/**
	 * Word processing types the Word reader handles.
	 *
	 * @var list<string>
	 */
	private const WORD_TYPES = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
		'application/vnd.oasis.opendocument.text',
	];

	/**
	 * Spreadsheet types the spreadsheet reader handles.
	 *
	 * @var list<string>
	 */
	private const SPREADSHEET_TYPES = [
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
	];

	/**
	 * Wire the root folder, the readers and the logger.
	 *
	 * @param IRootFolder           $rootFolder Resolves the file in the person's folder.
	 * @param TextExtractionReaders $readers    The PDF, Word, spreadsheet and mail readers.
	 * @param LoggerInterface       $logger     Notes why a file gave no text.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly TextExtractionReaders $readers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the facade can answer; the readers ship with OpenRegister, so always.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
	 */
	public function isAvailable(): bool {
		return true;
	}//end isAvailable()

	/**
	 * The file's text, resolved in the person's own folder.
	 *
	 * @param int    $fileId The Nextcloud file id.
	 * @param string $userId The uid whose folder the file is read from.
	 *
	 * @return string|null The sanitised text; null when the person cannot open the
	 *                     file, it is no file, its type has no reader, or reading failed.
	 *
	 * @spec openspec/changes/file-text-facade/specs/text-extraction/spec.md
	 */
	public function extractText(int $fileId, string $userId): ?string {
		if ($fileId <= 0 || $userId === '') {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
			if ($node instanceof File === false || $node->isReadable() === false) {
				return null;
			}

			$text = $this->textOf(file: $node, mimeType: strtolower((string)$node->getMimeType()));
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[FileTextFacade] the file gave no text',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($text === null) {
			return null;
		}

		return $this->sanitise(text: $text);
	}//end extractText()

	/**
	 * The raw text of a file, read the way its mime type asks.
	 *
	 * @param File   $file     The file.
	 * @param string $mimeType Its mime type, lower case.
	 *
	 * @return string|null The text, or null when the type has no reader.
	 */
	private function textOf(File $file, string $mimeType): ?string {
		if (str_starts_with($mimeType, 'text/') === true || in_array($mimeType, self::PLAIN_TYPES, true) === true) {
			return $file->getContent();
		}

		return match (true) {
			$mimeType === 'application/pdf' => $this->readers->pdfExtractor->extract(file: $file),
			in_array($mimeType, self::WORD_TYPES, true) === true => $this->readers->wordExtractor->extract(file: $file),
			in_array($mimeType, self::SPREADSHEET_TYPES, true) === true => $this->readers->spreadsheetExtractor->extract(file: $file),
			$mimeType === 'message/rfc822' => $this->readers->emlParser->flatten(structure: $this->readers->emlParser->parse(file: $file)),
			default => null,
		};
	}//end textOf()

	/**
	 * Valid UTF-8 without NUL bytes, trimmed; null when nothing is left.
	 *
	 * @param string $text The raw text.
	 *
	 * @return string|null The text, or null when it is empty.
	 */
	private function sanitise(string $text): ?string {
		$clean = trim(mb_convert_encoding(str_replace("\0", '', $text), 'UTF-8', 'UTF-8'));
		if ($clean === '') {
			return null;
		}

		return $clean;
	}//end sanitise()
}//end class
