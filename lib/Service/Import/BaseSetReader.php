<?php

/**
 * BaseSetReader: read a base export of a named product through that product's reader.
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
 * Picks the reader for a product format and reads the export with it.
 *
 * An unknown format is refused naming the formats that can be read, rather
 * than guessed from the file.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
class BaseSetReader {

	/**
	 * The readers by format.
	 *
	 * @var array<string, BaseSetReaderInterface>
	 */
	private readonly array $readers;

	/**
	 * Constructor.
	 *
	 * @param BaserowBaseReader $baserow The Baserow reader.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(BaserowBaseReader $baserow) {
		$this->readers = self::byFormat(readers: [$baserow]);
	}//end __construct()

	/**
	 * The formats that can be read.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function formats(): array {
		return array_keys($this->readers);
	}//end formats()

	/**
	 * Read an export of the given product format.
	 *
	 * @param string $format   The product format (e.g. baserow).
	 * @param string $filePath Path to the export on disk.
	 *
	 * @return BaseSet The base.
	 *
	 * @throws InvalidArgumentException When the format is unknown or the file is not an export of it.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function read(string $format, string $filePath): BaseSet {
		$reader = ($this->readers[strtolower(trim($format))] ?? null);
		if ($reader === null) {
			throw new InvalidArgumentException(
				'A base in format "'.$format.'" cannot be read; readable formats: '.implode(', ', $this->formats()).'.'
			);
		}

		return $reader->read(filePath: $filePath);
	}//end read()

	/**
	 * Key readers by the format each reads.
	 *
	 * @param array<int, BaseSetReaderInterface> $readers The readers.
	 *
	 * @return array<string, BaseSetReaderInterface>
	 */
	private static function byFormat(array $readers): array {
		$byFormat = [];
		foreach ($readers as $reader) {
			$byFormat[$reader->format()] = $reader;
		}

		return $byFormat;
	}//end byFormat()
}//end class
