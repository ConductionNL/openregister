<?php

/**
 * OpenRegister translation filter
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
 * Which translation rows a search narrows to: language, workflow status, object and source language.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationFilter {
	/**
	 * Name the filters; null leaves a filter off.
	 *
	 * @param string|null $language       The target language.
	 * @param string|null $status         The workflow status.
	 * @param string|null $objectUuid     The object the rows belong to.
	 * @param string|null $sourceLanguage The canonical source language.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly ?string $language,
		public readonly ?string $status,
		public readonly ?string $objectUuid,
		public readonly ?string $sourceLanguage,
	) {
	}//end __construct()
}//end class
