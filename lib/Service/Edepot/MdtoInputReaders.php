<?php

/**
 * OpenRegister MDTO input readers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Service\Archival\MdtoMappingResolver;

/**
 * The readers that tell the MDTO preconditions what an object can supply.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
 */
final class MdtoInputReaders {
	/**
	 * Bundle the readers.
	 *
	 * @param MdtoSourceReader     $sourceReader     Resolver for the declared archival values.
	 * @param MdtoBestandGenerator $bestandGenerator Owner of the per-file input rules.
	 * @param MdtoMappingResolver  $mappingResolver  Reads the schema's administered element mapping.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-mdto-generator-must-report-the-limits-of-its-own-validation
	 */
	public function __construct(
		public readonly MdtoSourceReader $sourceReader,
		public readonly MdtoBestandGenerator $bestandGenerator,
		public readonly MdtoMappingResolver $mappingResolver,
	) {
	}//end __construct()
}//end class
