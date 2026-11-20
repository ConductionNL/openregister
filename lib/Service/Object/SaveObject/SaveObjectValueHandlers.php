<?php

/**
 * The handlers that transform stored values: translations and field encryption.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Kernel\Contracts\Core\DerivedTranslationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SourceLanguagesInterface;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;

/**
 * The handlers that transform stored values: translations and field encryption.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectValueHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TranslationHandler           $translationHandler           Handler for translation operations.
	 * @param SourceLanguagesInterface     $translationProjectionService Resolves a translatable property's source language.
	 * @param DerivedTranslationsInterface $translationStatusService     Marks the translations derived from a changed source value.
	 * @param ?FieldEncryptionHandler      $fieldEncryptionHandler       Field-level encryption handler.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly TranslationHandler $translationHandler,
		public readonly SourceLanguagesInterface $translationProjectionService,
		public readonly DerivedTranslationsInterface $translationStatusService,
		public readonly ?FieldEncryptionHandler $fieldEncryptionHandler = null,
	) {
	}//end __construct()
}//end class
