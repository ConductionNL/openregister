<?php

/**
 * OpenRegister OutdatedTranslations
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/i18n-source-of-truth/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\SourceLanguageLookup;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStatusService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Flips derived translations to outdated when a translatable source value changed.
 *
 * Conservative trigger: only a change of the source-language value counts; edits
 * to other languages do not.
 *
 * @spec openspec/specs/i18n-source-of-truth/spec.md
 */
final class OutdatedTranslations {

	/**
	 * @param TranslationHandler           $translations Names the translatable properties.
	 * @param TranslationProjectionService $projection   Resolves the source language.
	 * @param TranslationStatusService     $status       Marks the derived translations.
	 * @param LoggerInterface              $logger       Records a failure.
	 */
	public function __construct(
		private readonly TranslationHandler $translations,
		private readonly TranslationProjectionService $projection,
		private readonly TranslationStatusService $status,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compare an update's old and new values. A failure is logged, never thrown.
	 *
	 * @param ObjectUpdateRun $run     The update call.
	 * @param ObjectEntity    $updated The updated entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/i18n-source-of-truth/spec.md
	 */
	public function flag(ObjectUpdateRun $run, ObjectEntity $updated): void {
		try {
			$run->updated = $updated;
			$this->flagProperties(run: $run);
		} catch (Throwable $e) {
			$this->logger->warning('[SaveObject] failed to flag outdated translations: ' . $e->getMessage());
		}
	}//end flag()

	/**
	 * Check every translatable property of an updated object that has a uuid.
	 *
	 * @param ObjectUpdateRun $run The update call, with the updated entity.
	 *
	 * @return void
	 */
	private function flagProperties(ObjectUpdateRun $run): void {
		$uuid = $run->updated->getUuid();
		if ($uuid === null || $uuid === '') {
			return;
		}

		foreach ($this->translations->getTranslatableProperties($run->schema) as $property) {
			$this->flagProperty(run: $run, property: $property);
		}
	}//end flagProperties()

	/**
	 * Mark a property's derived translations when both source values exist and differ.
	 *
	 * @param ObjectUpdateRun $run      The update call, with the updated entity.
	 * @param mixed           $property The translatable property.
	 *
	 * @return void
	 */
	private function flagProperty(ObjectUpdateRun $run, mixed $property): void {
		$source = $this->projection->resolveSourceLanguage(
			lookup: new SourceLanguageLookup(
				schema: $run->schema,
				object: $run->updated,
				property: $property,
				registerDefault: $run->register->getDefaultLanguage()
			)
		);
		$old = self::languageValue(value: ((array)($run->oldObject->getObject() ?? []))[$property] ?? null, language: $source);
		$new = self::languageValue(value: ((array)($run->updated->getObject() ?? []))[$property] ?? null, language: $source);
		if ($old === null || $new === null || $old === $new) {
			return;
		}

		$this->status->markDerivedTranslationsOutdated(
			source: new TranslationSlot(objectUuid: (string)$run->updated->getUuid(), property: (string)$property, language: $source)
		);
	}//end flagProperty()

	/**
	 * The value for a language in a translatable property: language-keyed (`{nl: "x"}`)
	 * or a plain string, which is the source language.
	 *
	 * @param mixed  $value    The raw property value from the object body.
	 * @param string $language The language tag to extract for.
	 *
	 * @return string|null The value, or null when not present.
	 */
	private static function languageValue(mixed $value, string $language): ?string {
		if (is_string($value) === true) {
			return $value;
		}

		$entry = null;
		if (is_array($value) === true) {
			$entry = ($value[$language] ?? null);
		}

		if (is_scalar($entry) === true) {
			return (string)$entry;
		}

		return null;
	}//end languageValue()
}//end class
