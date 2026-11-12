<?php

/**
 * OpenRegister not-supplied rules
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * What a schema says about not-supplied entries: the properties it declares and the reasons it administers.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class NotSuppliedRules {
	/**
	 * Hold the schema's side of the check.
	 *
	 * @param array                 $properties The schema's declared properties.
	 * @param array<string, string> $reasons    The reasons this schema administers.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly array $properties,
		public readonly array $reasons,
	) {
	}//end __construct()
}//end class
