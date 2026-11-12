<?php

/**
 * Where a translatable value goes on save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The register's default language, the request's target language and the property being saved.
 *
 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
 */
final class TranslationSaveTarget {
	/**
	 * Constructor.
	 *
	 * @param string      $defaultLanguage The register's default language.
	 * @param string|null $targetLanguage  The request's target language, if any.
	 * @param string      $property        The property being saved.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function __construct(
		public readonly string $defaultLanguage,
		public readonly ?string $targetLanguage,
		public readonly string $property = '',
	) {
	}//end __construct()

	/**
	 * The same languages, for one property.
	 *
	 * @param string $property The property.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function forProperty(string $property): self {
		return new self(defaultLanguage: $this->defaultLanguage, targetLanguage: $this->targetLanguage, property: $property);
	}//end forProperty()

	/**
	 * Whether the request names a target language.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function hasTarget(): bool {
		return $this->targetLanguage !== null && $this->targetLanguage !== '';
	}//end hasTarget()

	/**
	 * The language a simple value goes under: the target, else the default.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function wrappingLanguage(): string {
		if ($this->hasTarget() === true) {
			return (string)$this->targetLanguage;
		}

		return $this->defaultLanguage;
	}//end wrappingLanguage()
}//end class
