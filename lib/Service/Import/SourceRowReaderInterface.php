<?php

/**
 * SourceRowReaderInterface: what callers outside the component use of SourceRowReader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;

/**
 * Read, hash and stage an uploaded import source.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface SourceRowReaderInterface {

	/**
	 * Comma-separated values.
	 *
	 * @var string
	 */
	public const FORMAT_CSV = 'csv';

	/**
	 * A spreadsheet.
	 *
	 * @var string
	 */
	public const FORMAT_EXCEL = 'excel';

	/**
	 * A JSON list, or a `{"results": [...]}` envelope.
	 *
	 * @var string
	 */
	public const FORMAT_JSON = 'json';

	/**
	 * Every format a preview reads.
	 *
	 * @var array<int, string>
	 */
	public const FORMATS = [
		self::FORMAT_CSV,
		self::FORMAT_EXCEL,
		self::FORMAT_JSON,
	];

	/**
	 * The sha256 of a file, which is the identity a commit is checked against.
	 *
	 * @param string $filePath Path to the file on disk.
	 *
	 * @return string The hash.
	 *
	 * @throws InvalidArgumentException When the file cannot be hashed.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function hash(string $filePath): string;

	/**
	 * Copy an uploaded file somewhere it survives the request.
	 *
	 * PHP removes an upload's temp file when the request ends, so a preview
	 * that runs on cron rather than in the request has nothing left to read.
	 * The copy is host-local, which is the same assumption the rest of the
	 * import path makes about an upload, and it is removed as soon as the
	 * preview has decided every row.
	 *
	 * @param string $filePath The uploaded file's temp path.
	 * @param string $token A token making the staged name unique, usually the preview uuid.
	 *
	 * @return string The staged path.
	 *
	 * @throws InvalidArgumentException When the copy cannot be made.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function stage(string $filePath, string $token): string;
}//end interface
