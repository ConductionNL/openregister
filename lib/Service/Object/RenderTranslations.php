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

use OCA\OpenRegister\Kernel\Contracts\Core\RequestLanguagesInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TranslationCompletenessInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TranslationRowStatusInterface;

/**
 * What a render resolves translatable properties and languages with.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderTranslations {
	/**
	 * Bundle the services.
	 *
	 * @param TranslationHandler               $translationHandler       Resolves translatable properties.
	 * @param TranslationCompletenessInterface $translationStatusService Per-object translation completeness.
	 * @param TranslationRowStatusInterface    $translationMapper        The status of a stored translation.
	 * @param RequestLanguagesInterface        $languageService          The request's negotiated languages.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly TranslationHandler $translationHandler,
		public readonly TranslationCompletenessInterface $translationStatusService,
		public readonly TranslationRowStatusInterface $translationMapper,
		public readonly RequestLanguagesInterface $languageService,
	) {
	}//end __construct()
}//end class
