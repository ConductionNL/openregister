<?php

/**
 * OpenRegister LanguageGapScan
 *
 * Which objects to check for one language's missing translations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The language and the candidate objects {@see TranslationStatusService::findObjectsMissingLanguage()} scans.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class LanguageGapScan {

	/**
	 * Constructor.
	 *
	 * @param string   $language       The language code to check.
	 * @param string[] $candidateUuids The object UUIDs to consider.
	 */
	public function __construct(
		public readonly string $language,
		public readonly array $candidateUuids,
	) {
	}//end __construct()
}//end class
