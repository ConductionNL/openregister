<?php

/**
 * OpenRegister Property Translation
 *
 * One property of an object being machine-translated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\Translation\LanguagePair;

/**
 * The object translation and the property it is at, built by BulkTranslationService per property.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class PropertyTranslation {

	/**
	 * Constructor.
	 *
	 * @param ObjectTranslationRequest $request  The object and the languages.
	 * @param string                   $property The property being translated.
	 */
	public function __construct(
		public readonly ObjectTranslationRequest $request,
		public readonly string $property,
	) {
	}//end __construct()

	/**
	 * The language pair the provider is asked to translate between.
	 *
	 * @return LanguagePair
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function languages(): LanguagePair {
		return new LanguagePair(fromLong: $this->request->fromLong, toLong: $this->request->toLong);
	}//end languages()

	/**
	 * The sidecar slot the translation is written to.
	 *
	 * @return TranslationSlot
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function targetSlot(): TranslationSlot {
		return new TranslationSlot(objectUuid: (string)$this->request->object->getUuid(), property: $this->property, language: $this->request->toLong);
	}//end targetSlot()
}//end class
