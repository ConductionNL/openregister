<?php

/**
 * OpenRegister ReferenceOptionsAsk
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


/**
 * Which record and which property a reference-options read is about.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
final class ReferenceOptionsAsk {
	/**
	 * Hold what was asked.
	 *
	 * @param string $id       The record.
	 * @param string $register The register in the URL.
	 * @param string $schema   The schema in the URL.
	 * @param string $property The property whose options are asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $property,
	) {
	}//end __construct()
}//end class
