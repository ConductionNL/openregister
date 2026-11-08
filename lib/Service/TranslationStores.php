<?php

/**
 * The translation rows and the object translation handler of the translation services.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;

/**
 * The translation rows and the object translation handler of the translation services.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationStores {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TranslationMapperInterface $translationMapper  Translation row mapper.
	 * @param TranslationHandler         $translationHandler Reads and writes translated object values.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly TranslationMapperInterface $translationMapper,
		public readonly TranslationHandler $translationHandler,
	) {
	}//end __construct()
}//end class
