<?php

/**
 * OpenRegister Translation Handler
 *
 * Handler class responsible for resolving translatable properties in objects.
 * Supports both reading (selecting the correct language variant) and writing
 * (normalizing input to language-keyed objects) for translatable properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\TranslationTargetConflictException;
use OCA\OpenRegister\Service\LanguageService;
use Psr\Log\LoggerInterface;

/**
 * Handler for translatable property resolution in objects.
 *
 * This handler reads the schema's property definitions to determine which
 * properties are translatable, then either resolves them to a single language
 * value (for rendering) or normalizes them to language-keyed objects (for saving).
 *
 * @package OCA\OpenRegister\Service\Object
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class TranslationHandler implements TranslationHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param LanguageService $languageService The request-scoped language service
	 * @param LoggerInterface $logger Logger interface
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LanguageService $languageService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the list of translatable property names from a schema.
	 *
	 * Inspects the schema's properties and returns the names of those
	 * that have `translatable: true` in their definition.
	 *
	 * @param Schema $schema The schema to inspect
	 *
	 * @return string[] Array of translatable property names
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getTranslatableProperties(Schema $schema): array {
		$properties = $schema->getProperties() ?? [];
		$translatable = [];

		foreach ($properties as $propertyName => $propertyDef) {
			if (is_array($propertyDef) === false) {
				continue;
			}

			if (($propertyDef['translatable'] ?? false) === true) {
				$translatable[] = $propertyName;
			}
		}

		return $translatable;
	}//end getTranslatableProperties()

	/**
	 * Resolve translatable properties in object data for rendering.
	 *
	 * For each translatable property:
	 * - If _translations=all is requested, returns the full language object
	 * - Otherwise, resolves to the single value for the best matching language
	 * - Falls back to the register's default language if requested language is missing
	 *
	 * @param array            $objectData The object data array
	 * @param TranslationScope $scope      The schema for property definitions and the register for language configuration
	 *
	 * @return array The object data with resolved translations
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolveTranslationsForRender(array $objectData, TranslationScope $scope): array {
		$translatableProps = $this->propertiesToResolve(schema: $scope->schema);
		if (empty($translatableProps) === true) {
			return $objectData;
		}

		$chain = $this->languageChain(register: $scope->register);
		foreach ($translatableProps as $propName) {
			$value = ($objectData[$propName] ?? null);
			if ($this->isLanguageKeyedValue(value: $value) === true) {
				$objectData[$propName] = $this->translatedValue(value: $value, chain: $chain);
			}
		}

		return $objectData;
	}//end resolveTranslationsForRender()

	/**
	 * The translatable properties a render resolves: none when all translations are returned.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array The property names.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function propertiesToResolve(Schema $schema): array {
		if ($this->languageService->shouldReturnAllTranslations() === true) {
			return [];
		}

		return $this->getTranslatableProperties(schema: $schema);
	}//end propertiesToResolve()

	/**
	 * The languages a property is looked up in, in order: the user's resolved
	 * language, then the register's languages as declared, then the register's
	 * default ('nl' without a register). Each property resolves independently.
	 *
	 * @param Register|null $register The register for language configuration
	 *
	 * @return list<string> The language chain, resolved language first
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function languageChain(?Register $register): array {
		$registerLanguages = ($register?->getLanguages() ?? []);
		$defaultLanguage = ($register?->getDefaultLanguage() ?? 'nl');

		$chain = [$this->languageService->resolveLanguageForRegister($registerLanguages)];
		foreach ([...$registerLanguages, $defaultLanguage] as $language) {
			if (in_array($language, $chain, true) === false) {
				$chain[] = $language;
			}
		}

		return $chain;
	}//end languageChain()

	/**
	 * The value of a language-keyed property in the first chain language it
	 * has, else its first variant (legacy data in a language outside the
	 * chain). Any pick other than the resolved language marks the fallback as
	 * used, for the Content-Language header.
	 *
	 * @param array        $value The language-keyed value
	 * @param list<string> $chain The language chain, resolved language first
	 *
	 * @return mixed The picked value, or the language-keyed value when it has no usable variant
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function translatedValue(array $value, array $chain): mixed {
		foreach ($chain as $candidate) {
			if (isset($value[$candidate]) === true) {
				if ($candidate !== $chain[0]) {
					$this->languageService->setFallbackUsed(true);
				}

				return $value[$candidate];
			}
		}

		$firstValue = reset($value);
		if ($firstValue === false) {
			return $value;
		}

		$this->languageService->setFallbackUsed(true);
		return $firstValue;
	}//end translatedValue()

	/**
	 * Normalize translatable properties in object data for saving.
	 *
	 * Resolution per property:
	 *  - Already a language-keyed object (e.g. `{nl: ..., en: ...}`):
	 *    keep as-is. When `X-Translation-Target-Language` is also set on
	 *    the request, throw `TranslationTargetConflictException` to
	 *    surface conflicting intent to the consumer.
	 *  - Scalar value AND `X-Translation-Target-Language: <bcp47>` on the
	 *    request: wrap under the target language.
	 *  - Scalar value AND no target header: wrap under the register
	 *    default (legacy behaviour preserved).
	 *
	 * @param array            $objectData The incoming object data.
	 * @param TranslationScope $scope      The schema for property definitions and the register for language configuration.
	 *
	 * @return array The normalized object data with translations wrapped correctly.
	 *
	 * @throws TranslationTargetConflictException When a full language-keyed body is
	 *                                            paired with `X-Translation-Target-Language`.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	public function normalizeTranslationsForSave(array $objectData, TranslationScope $scope): array {
		$translatableProps = $this->getTranslatableProperties(schema: $scope->schema);
		if (empty($translatableProps) === true) {
			return $objectData;
		}

		$target = new TranslationSaveTarget(
			defaultLanguage: ($scope->register?->getDefaultLanguage() ?? 'nl'),
			targetLanguage: $this->languageService->getTargetLanguage()
		);
		foreach ($translatableProps as $propName) {
			if (isset($objectData[$propName]) === true) {
				$objectData[$propName] = $this->valueForSave(value: $objectData[$propName], target: $target->forProperty(property: (string)$propName));
			}
		}

		return $objectData;
	}//end normalizeTranslationsForSave()

	/**
	 * A language-keyed value after its check, or a simple value wrapped under its language.
	 *
	 * @param mixed                 $value  The non-null value.
	 * @param TranslationSaveTarget $target The languages and the property.
	 *
	 * @return mixed The value to save.
	 *
	 * @throws TranslationTargetConflictException When a language-keyed value meets a target language.
	 *
	 * @spec openspec/changes/i18n-api-language-negotiation/tasks.md#phase-2
	 */
	private function valueForSave(mixed $value, TranslationSaveTarget $target): mixed {
		if ($this->isLanguageKeyedValue(value: $value) === false) {
			return [$target->wrappingLanguage() => $value];
		}

		if ($target->hasTarget() === true) {
			throw new TranslationTargetConflictException(property: $target->property, targetLanguage: (string)$target->targetLanguage);
		}

		if (isset($value[$target->defaultLanguage]) === false) {
			$this->logger->warning(
				message: '[TranslationHandler] Translatable property missing default language value',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $target->property, 'defaultLanguage' => $target->defaultLanguage]
			);
		}

		return $value;
	}//end valueForSave()

	/**
	 * Whether a value is an array keyed by language.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isLanguageKeyedValue(mixed $value): bool {
		return is_array($value) === true && $this->isLanguageKeyedObject(value: $value) === true;
	}//end isLanguageKeyedValue()

	/**
	 * Check if an array is a language-keyed object.
	 *
	 * A language-keyed object has string keys that look like BCP 47 language codes
	 * (2-3 letter codes, optionally with region suffixes like "en-US").
	 *
	 * @param array $value The array to check
	 *
	 * @return bool True if this looks like a language-keyed object
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isLanguageKeyedObject(array $value): bool {
		return (new LanguageKeyedMap(value: $value))->isLanguageKeyed();
	}//end isLanguageKeyedObject()
}//end class
