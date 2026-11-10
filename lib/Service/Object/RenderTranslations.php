<?php

/**
 * OpenRegister render translations
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

use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\TranslationStatusService;

/**
 * What a render resolves translatable properties and languages with.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderTranslations {
	/**
	 * Bundle the services.
	 *
	 * @param TranslationHandler              $translationHandler       Resolves translatable properties.
	 * @param TranslationStatusService        $translationStatusService Per-object translation status.
	 * @param TranslationMapperInterface      $translationMapper        The translation store.
	 * @param LanguageService                 $languageService          Source-language resolution.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly TranslationHandler $translationHandler,
		public readonly TranslationStatusService $translationStatusService,
		public readonly TranslationMapperInterface $translationMapper,
		public readonly LanguageService $languageService,
	) {
	}//end __construct()
}//end class
