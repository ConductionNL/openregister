<?php

/**
 * RecordImportRequest: what a record import writes, and where.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The file, its format, the register and schema it goes into, and the confirmed mapping.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
final class RecordImportRequest {

	/**
	 * Constructor.
	 *
	 * @param string           $path     The record file.
	 * @param string           $format   One of the RecordFileReader format constants.
	 * @param Register         $register The register.
	 * @param Schema           $schema   The schema.
	 * @param ConfirmedMapping $mapping  The confirmed column mapping.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $format,
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly ConfirmedMapping $mapping,
	) {
	}//end __construct()
}//end class
