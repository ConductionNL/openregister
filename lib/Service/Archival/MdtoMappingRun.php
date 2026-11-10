<?php

/**
 * OpenRegister MdtoMappingRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One check of an MDTO mapping: what the catalogue knows and what the schema
 * declares. Built by ElementMappingValidator::validate() and never leaves it.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class MdtoMappingRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, bool>  $known      The MDTO elements the catalogue declares.
	 * @param array<string, mixed> $properties The schema's declared properties.
	 */
	public function __construct(
		public readonly array $known,
		public readonly array $properties,
	) {
	}//end __construct()
}//end class
