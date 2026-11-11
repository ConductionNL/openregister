<?php

/**
 * LanguageCoverageCheck: which candidate objects lack a language for some properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Translation;

/**
 * The language, the properties it must cover and the objects to check.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class LanguageCoverageCheck {

	/**
	 * Constructor.
	 *
	 * @param string   $language       BCP-47 language tag to test for completeness.
	 * @param string[] $properties     Property paths required for completeness.
	 * @param string[] $candidateUuids The object uuids to check.
	 */
	public function __construct(
		public readonly string $language,
		public readonly array $properties,
		public readonly array $candidateUuids,
	) {
	}//end __construct()
}//end class
