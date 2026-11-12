<?php

/**
 * The order of a file-source summary listing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

/**
 * The field and direction a file-source summary listing is sorted on.
 *
 * An unknown field sorts on the last extraction; anything but ASC (any case)
 * sorts descending.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class FileSourceOrder {

	/**
	 * File-source summary sort fields and the column each one orders by.
	 */
	private const COLUMNS = [
		'fileName' => 'fc.name',
		'fileSize' => 'fc.size',
		'extractedAt' => 'last_extracted',
		'chunkCount' => 'chunk_count',
	];

	/**
	 * Constructor.
	 *
	 * @param string $sort  Sort field (fileName, fileSize, extractedAt, chunkCount).
	 * @param string $order Sort direction (ASC or DESC).
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function __construct(
		public readonly string $sort = 'extractedAt',
		public readonly string $order = 'DESC',
	) {
	}//end __construct()

	/**
	 * The column the listing orders by.
	 *
	 * @return string The column, last_extracted for an unknown field.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function column(): string {
		return self::COLUMNS[$this->sort] ?? 'last_extracted';
	}//end column()

	/**
	 * The SQL sort direction: ASC when asked for (any case), DESC otherwise.
	 *
	 * @return string ASC or DESC.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function direction(): string {
		if (strtoupper($this->order) === 'ASC') {
			return 'ASC';
		}

		return 'DESC';
	}//end direction()
}//end class
