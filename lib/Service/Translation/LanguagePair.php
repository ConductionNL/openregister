<?php

/**
 * OpenRegister LanguagePair
 *
 * The source and target language of one machine translation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
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

namespace OCA\OpenRegister\Service\Translation;

/**
 * Both BCP 47 language codes (e.g. "nl", "en", "fr-CA") a
 * {@see TranslationProviderInterface::translate()} call translates between.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class LanguagePair {

	/**
	 * Constructor.
	 *
	 * @param string $fromLong BCP 47 source language code.
	 * @param string $toLong   BCP 47 target language code.
	 */
	public function __construct(
		public readonly string $fromLong,
		public readonly string $toLong,
	) {
	}//end __construct()
}//end class
