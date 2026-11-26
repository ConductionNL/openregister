<?php

/**
 * OpenRegister RedactionOutputMode
 *
 * The formats an anonymised copy leaves the engine in, and how the verifier
 * reads each of them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The output modes and the reader the verifier uses for each.
 *
 * `READERS` is a separate statement from `ALL` on purpose: adding a mode to
 * `ALL` without a reader is a suite failure (OutputModeCoverageTest), not a
 * silent gap. A mode nobody gave a reader is a mode nobody verified.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */
final class RedactionOutputMode {

	/**
	 * A PDF, written by the byte-level pipeline (PDF/A included).
	 */
	public const PDF = 'pdf';

	/**
	 * A Word document.
	 */
	public const DOCX = 'docx';

	/**
	 * An OpenDocument text.
	 */
	public const ODT = 'odt';

	/**
	 * A spreadsheet.
	 */
	public const XLSX = 'xlsx';

	/**
	 * A presentation.
	 */
	public const PPTX = 'pptx';

	/**
	 * Plain text and every other format the string replacement writes.
	 */
	public const TEXT = 'text';

	/**
	 * Every mode a redacted copy can leave the engine in.
	 *
	 * @var list<string>
	 */
	public const ALL = [self::PDF, self::DOCX, self::ODT, self::XLSX, self::PPTX, self::TEXT];

	/**
	 * Reading the bytes as a PDF.
	 */
	public const READER_PDF = 'pdf';

	/**
	 * Reading the bytes as a ZIP package of parts (OOXML and ODF).
	 */
	public const READER_PACKAGE = 'package';

	/**
	 * Reading the bytes as text.
	 */
	public const READER_TEXT = 'text';

	/**
	 * The reader the verifier uses for each verified mode.
	 *
	 * @var array<string, string>
	 */
	public const READERS = [
		self::PDF => self::READER_PDF,
		self::DOCX => self::READER_PACKAGE,
		self::ODT => self::READER_PACKAGE,
		self::XLSX => self::READER_PACKAGE,
		self::PPTX => self::READER_PACKAGE,
		self::TEXT => self::READER_TEXT,
	];

	/**
	 * The file extensions written in a mode other than text.
	 *
	 * @var array<string, string>
	 */
	private const EXTENSIONS = [
		'pdf' => self::PDF,
		'docx' => self::DOCX,
		'doc' => self::DOCX,
		'odt' => self::ODT,
		'xlsx' => self::XLSX,
		'pptx' => self::PPTX,
	];

	/**
	 * The mode a written file is in, from its name.
	 *
	 * Mirrors DocumentProcessingHandler's dispatch: PDFs through the byte
	 * pipeline, Word and the sanitisable packages in their own format, and
	 * everything else through the string replacement.
	 *
	 * @param string $fileName The written file's name.
	 *
	 * @return string The output mode.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public static function forFileName(string $fileName): string {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		return (self::EXTENSIONS[$extension] ?? self::TEXT);
	}//end forFileName()

	/**
	 * The modes that write a copy and have no reader.
	 *
	 * @return list<string> The gaps, empty when every mode is covered.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.3
	 */
	public static function unverified(): array {
		return array_values(array_diff(self::ALL, array_keys(self::READERS)));
	}//end unverified()

	/**
	 * The reader of a mode, or null for a mode nobody declared.
	 *
	 * @param string $mode The output mode.
	 *
	 * @return string|null The reader.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.1
	 */
	public static function readerFor(string $mode): ?string {
		return (self::READERS[$mode] ?? null);
	}//end readerFor()
}//end class
