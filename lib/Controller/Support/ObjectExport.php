<?php

/**
 * What one object export is about, and how its file is delivered.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\DataDownloadResponse;

/**
 * The register, schema and filters of an export, and its file name without extension.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
final class ObjectExport {

	/**
	 * File extension and content type per export format.
	 */
	private const FILE_TYPES = [
		'csv'   => ['csv', 'text/csv'],
		'tsv'   => ['tsv', 'text/tab-separated-values; charset=utf-8'],
		'json'  => ['json', 'application/json'],
		'pdf'   => ['pdf', 'application/pdf'],
		'excel' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
	];

	/**
	 * Constructor.
	 *
	 * @param Register $register     The register being exported.
	 * @param Schema   $schema       The schema being exported.
	 * @param array    $filters      The request filters.
	 * @param string   $filenameBase The file name without extension.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $filters,
		public readonly string $filenameBase,
	) {
	}//end __construct()

	/**
	 * The download of an export file.
	 *
	 * @param string $format  The export format.
	 * @param string $content The file content.
	 *
	 * @return DataDownloadResponse
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function download(string $format, string $content): DataDownloadResponse {
		[$extension, $contentType] = self::FILE_TYPES[$format];

		return new DataDownloadResponse(
			data: $content,
			filename: "{$this->filenameBase}.{$extension}",
			contentType: $contentType
		);
	}//end download()
}//end class
