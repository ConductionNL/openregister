<?php

/**
 * OpenRegister TranslationOutcome
 *
 * A translation and the glossary terms it missed (design D-5).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

/**
 * What a translation returned, and which matched glossary terms it left out.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */
final class TranslationOutcome {

	/**
	 * Constructor.
	 *
	 * @param string       $text         The translation.
	 * @param list<string> $missingTerms The glossary terms whose required text is not in it.
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $missingTerms=[],
	) {
	}//end __construct()

	/**
	 * Whether the translation kept every matched glossary term.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function keptTheGlossary(): bool {
		return $this->missingTerms === [];
	}//end keptTheGlossary()

	/**
	 * The reason a slot is stored as a draft, or '' when it is not.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function reason(): string {
		if ($this->missingTerms === []) {
			return '';
		}

		return 'glossary-terms-missing: '.implode(', ', $this->missingTerms);
	}//end reason()
}//end class
