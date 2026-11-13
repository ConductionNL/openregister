<?php

/**
 * OpenRegister ArchiveTextExtractor
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use OCA\OpenRegister\Service\File\ArchiveBounds;
use OCA\OpenRegister\Service\File\ArchiveListing;
use OCA\OpenRegister\Service\File\ArchiveMemberEnumerator;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Reads the text of each member of a zip archive in memory, with the existing
 * extractors, under the bounds the enumerator applies.
 *
 * Members are never written to Nextcloud Files: a member exists only as the
 * text returned here. A member path is only ever used as a name inside the
 * archive, never against the file system.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
 */
class ArchiveTextExtractor {

	/**
	 * The MIME types of a zip archive.
	 *
	 * @var array<int, string>
	 */
	private const ZIP_TYPES = ['application/zip', 'application/x-zip-compressed'];

	/**
	 * Archive types that are reported, not opened.
	 *
	 * @var array<int, string>
	 */
	private const UNSUPPORTED_TYPES = [
		'application/x-tar',
		'application/x-gtar',
		'application/x-7z-compressed',
		'application/vnd.rar',
		'application/x-rar',
		'application/x-rar-compressed',
	];

	/**
	 * Office reader per member MIME type.
	 *
	 * @var array<string, string>
	 */
	private const WORD_READERS = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word2007',
		'application/vnd.oasis.opendocument.text'                                 => 'ODText',
	];

	/**
	 * Spreadsheet member MIME types.
	 *
	 * @var array<int, string>
	 */
	private const SPREADSHEET_TYPES = [
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
		'application/vnd.oasis.opendocument.spreadsheet',
		'text/csv',
	];

	/**
	 * Constructor.
	 *
	 * @param ArchiveMemberEnumerator $enumerator  Lists the members under the bounds.
	 * @param PdfExtractor            $pdf         PDF text.
	 * @param SpreadsheetExtractor    $spreadsheet Spreadsheet text.
	 * @param EmlParser               $eml         E-mail text.
	 * @param LoggerInterface         $logger      Logger; never given member text.
	 */
	public function __construct(
		private readonly ArchiveMemberEnumerator $enumerator,
		private readonly PdfExtractor $pdf,
		private readonly SpreadsheetExtractor $spreadsheet,
		private readonly EmlParser $eml,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a file of this type is an archive this class opens.
	 *
	 * @param string $mimeType The MIME type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public static function isZip(string $mimeType): bool {
		return in_array($mimeType, self::ZIP_TYPES, true);
	}//end isZip()

	/**
	 * Whether a file of this type is an archive that is reported, not opened.
	 *
	 * @param string $mimeType The MIME type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md
	 */
	public static function isUnsupportedArchive(string $mimeType): bool {
		return in_array($mimeType, self::UNSUPPORTED_TYPES, true);
	}//end isUnsupportedArchive()

	/**
	 * The text of each member of an archive file.
	 *
	 * @param File $file The archive.
	 *
	 * @return ArchiveExtraction The member texts and what could not be read.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
	 */
	public function extractFile(File $file): ArchiveExtraction {
		$mimeType = (string) $file->getMimeType();
		if (self::isZip(mimeType: $mimeType) === false) {
			return new ArchiveExtraction(archiveType: $mimeType, supported: false);
		}

		$path = tempnam(sys_get_temp_dir(), 'or-archive-');
		if ($path === false) {
			return $this->unreadable(archiveType: $mimeType, name: (string) $file->getName());
		}

		try {
			$source = $file->fopen('r');
			$target = fopen($path, 'w');
			if ($source === false || $target === false) {
				return $this->unreadable(archiveType: $mimeType, name: (string) $file->getName());
			}

			stream_copy_to_stream($source, $target);
			fclose($source);
			fclose($target);

			return $this->extractPath(path: $path, archiveType: $mimeType);
		} finally {
			// NOSEMGREP: php.lang.security.unlink-use.unlink-use -- The path is the tempnam() file this method created.
			unlink($path);
		}
	}//end extractFile()

	/**
	 * The text of each member of a zip archive on disk.
	 *
	 * @param string             $path        The archive path.
	 * @param string             $archiveType The archive MIME type.
	 * @param ArchiveBounds|null $bounds      The bounds; the file settings when null.
	 *
	 * @return ArchiveExtraction The member texts and what could not be read.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-what-could-not-be-opened-is-reported-req-ams-003
	 */
	public function extractPath(string $path, string $archiveType='application/zip', ?ArchiveBounds $bounds=null): ArchiveExtraction {
		$extraction = new ArchiveExtraction(archiveType: $archiveType);
		$listing    = $this->enumerator->enumeratePath(path: $path, bounds: $bounds);
		$zip        = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			$extraction->addUnread(path: '', reason: 'unreadable');
			return $extraction;
		}

		$containers = new ArchiveContainers(outer: $zip);
		try {
			foreach ($listing->members() as $member) {
				$this->readMember(member: $member, containers: $containers, extraction: $extraction);
			}
		} finally {
			$containers->close();
		}

		if ($listing->stoppedAt() !== null) {
			$extraction->addUnread(path: $listing->stoppedMember(), reason: 'over-bound:'.$listing->stoppedAt());
		}

		return $extraction;
	}//end extractPath()

	/**
	 * Read one listed member into the extraction.
	 *
	 * @param array<string, mixed> $member     The listed member.
	 * @param ArchiveContainers    $containers The open archives.
	 * @param ArchiveExtraction    $extraction The extraction.
	 *
	 * @return void
	 */
	private function readMember(array $member, ArchiveContainers $containers, ArchiveExtraction $extraction): void {
		$path   = (string) $member['path'];
		$status = (string) $member['status'];
		if ($status !== ArchiveListing::LISTED) {
			$extraction->addUnread(path: $path, reason: $status);
			return;
		}

		$mimeType = (string) $member['mimeType'];
		if (self::isZip(mimeType: $mimeType) === true) {
			// A nested archive is a container: its own members are listed after it.
			$containers->openNested(path: $path);
			return;
		}

		$bytes = $containers->read(path: $path);
		if ($bytes === null) {
			$extraction->addUnread(path: $path, reason: 'unreadable');
			return;
		}

		try {
			$text = $this->memberText(bytes: $bytes, mimeType: $mimeType);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[ArchiveTextExtractor] An archive member could not be read',
				['mimeType' => $mimeType, 'exception' => get_class($failure)]
			);
			$extraction->addUnread(path: $path, reason: 'unreadable');
			return;
		}

		if ($text === null) {
			$extraction->addUnread(path: $path, reason: 'unsupported');
			return;
		}

		if (trim($text) !== '') {
			$extraction->addText(path: $path, text: $text);
		}
	}//end readMember()

	/**
	 * The text of a member's bytes, read the way its type asks.
	 *
	 * @param string $bytes    The member bytes.
	 * @param string $mimeType Its detected MIME type.
	 *
	 * @return string|null The text, or null for a type without an extractor.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
	 */
	public function memberText(string $bytes, string $mimeType): ?string {
		if (in_array($mimeType, self::SPREADSHEET_TYPES, true) === true) {
			return $this->spreadsheet->readBytes(content: $bytes);
		}

		if (str_starts_with($mimeType, 'text/') === true || in_array($mimeType, ['application/json', 'application/xml'], true) === true) {
			return $bytes;
		}

		if ($mimeType === 'application/pdf') {
			return $this->pdf->extractText(pdfBytes: $bytes);
		}

		if (isset(self::WORD_READERS[$mimeType]) === true) {
			return (new WordTextReader())->read(bytes: $bytes, format: self::WORD_READERS[$mimeType]);
		}

		if ($mimeType === 'message/rfc822') {
			return $this->eml->flatten(structure: $this->eml->parseBytes(bytes: $bytes));
		}

		return null;
	}//end memberText()

	/**
	 * An extraction that could not open the archive at all.
	 *
	 * @param string $archiveType The archive MIME type.
	 * @param string $name        The archive name.
	 *
	 * @return ArchiveExtraction
	 */
	private function unreadable(string $archiveType, string $name): ArchiveExtraction {
		$extraction = new ArchiveExtraction(archiveType: $archiveType);
		$extraction->addUnread(path: $name, reason: 'unreadable');
		return $extraction;
	}//end unreadable()
}//end class
