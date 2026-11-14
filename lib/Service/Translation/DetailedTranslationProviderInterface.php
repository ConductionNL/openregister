<?php

/**
 * OpenRegister DetailedTranslationProviderInterface
 *
 * A translation provider that also reports the glossary terms a translation
 * left out, so the caller can store such a slot as a draft (design D-5).
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
 * A provider whose answer carries the glossary terms it missed.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */
interface DetailedTranslationProviderInterface extends TranslationProviderInterface {
	/**
	 * Translate a text and report the matched glossary terms the translation left out.
	 *
	 * @param string       $text     The source text.
	 * @param LanguagePair $pair     The language pair.
	 * @param string|null  $register The register the text belongs to, if any.
	 *
	 * @return TranslationOutcome|null Null when the provider gives no text.
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function translateDetailed(string $text, LanguagePair $pair, ?string $register=null): ?TranslationOutcome;
}//end interface
