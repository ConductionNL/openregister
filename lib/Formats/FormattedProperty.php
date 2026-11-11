<?php

/**
 * OpenRegister formatted property
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
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

namespace OCA\OpenRegister\Formats;

/**
 * A schema property as its declaration names it: the property name and its `format` annotation.
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
final class FormattedProperty {
	/**
	 * Constructor.
	 *
	 * @param string      $name   The property name (for the error message).
	 * @param string|null $format The declared `format` annotation, null when there is none.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $format = null,
	) {
	}//end __construct()
}//end class
