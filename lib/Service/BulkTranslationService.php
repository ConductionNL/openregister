<?php

/**
 * OpenRegister BulkTranslationService
 *
 * Fills missing translation slots on register objects via a configured
 * `TranslationProviderInterface`. The service writes:
 *   1. The resulting translation onto the object's JSONB property
 *      (preserves the source-of-truth contract; sidecar projection
 *      rebuilds from there on the next save).
 *   2. The translation slot in the sidecar with status
 *      `machine_translated` and `translator = "provider:{identifier}"`.
 *
 * The actual saveObject path is left to the caller — this service is
 * a pure translation step that returns the patch the caller applies.
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
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Service\Translation\LanguagePair;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use Psr\Log\LoggerInterface;

class BulkTranslationService implements BulkTranslationServiceInterface {

	/**
	 * Constructor.
	 *
	 * @param TranslationProviderInterface $provider     The translation provider.
	 * @param TranslationStores            $stores       The translation mapper and translation handler.
	 * @param SchemaMapperInterface        $schemaMapper The schema mapper.
	 * @param LoggerInterface              $logger       The logger.
	 */
	public function __construct(
		private readonly TranslationProviderInterface $provider,
		private readonly TranslationStores $stores,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate one object's translatable properties from one language to another.
	 *
	 * @param ObjectTranslationRequest $request    The object and the source and target language.
	 * @param array|null               $properties Optional whitelist of property names; null means all translatable ones.
	 *
	 * @return array{translated: array, skipped: array} What was translated and what was skipped, with why.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function translate(ObjectTranslationRequest $request, ?array $properties = null): array {
		if ($request->fromLong === $request->toLong) {
			return ['translated' => [], 'skipped' => ['_global' => 'fromLang === toLang']];
		}

		$translatableProps = $this->translatablePropertiesOf(object: $request->object);
		if (is_string($translatableProps) === true) {
			return ['translated' => [], 'skipped' => ['_global' => $translatableProps]];
		}

		return $this->translateProperties(
			request: $request,
			properties: $this->selectedProperties(translatable: $translatableProps, whitelist: $properties)
		);
	}//end translate()

	/**
	 * The translatable properties of the object's schema, or why there are none.
	 *
	 * @param ObjectEntity $object The object to translate.
	 *
	 * @return array<int|string,string>|string The properties; the skip reason when the
	 *                                         schema cannot be resolved or has none.
	 */
	private function translatablePropertiesOf(ObjectEntity $object): array|string {
		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return 'schema-not-resolvable';
		}

		$translatableProps = $this->stores->translationHandler->getTranslatableProperties($schema);
		if (count($translatableProps) === 0) {
			return 'no-translatable-properties';
		}

		return $translatableProps;
	}//end translatablePropertiesOf()

	/**
	 * The translatable properties, narrowed to the whitelist when one is given, in schema order.
	 *
	 * @param array<int|string,string> $translatable The schema's translatable properties.
	 * @param array<int,string>|null   $whitelist    Property names to limit to, or null for all.
	 *
	 * @return array<int|string,string>
	 */
	private function selectedProperties(array $translatable, ?array $whitelist): array {
		if ($whitelist === null) {
			return $translatable;
		}

		return array_filter(
			$translatable,
			static fn (mixed $property): bool => in_array($property, $whitelist, true) === true
		);
	}//end selectedProperties()

	/**
	 * Translate each property, collecting the translated texts and the skip reasons.
	 *
	 * @param ObjectTranslationRequest $request    The object and language pair.
	 * @param array<int|string,string> $properties The properties to translate.
	 *
	 * @return array{translated: array<string,string>, skipped: array<string,string>}
	 */
	private function translateProperties(ObjectTranslationRequest $request, array $properties): array {
		$result = ['translated' => [], 'skipped' => []];
		foreach ($properties as $property) {
			$value = $this->translateProperty(request: $request, property: $property);
			if ($value['translated'] === true) {
				$result['translated'][$property] = $value['text'];
				continue;
			}

			$result['skipped'][$property] = $value['text'];
		}

		return $result;
	}//end translateProperties()

	/**
	 * Translate one property into its target-language slot and mirror it into the sidecar.
	 *
	 * A missing source value, a filled target slot (whatever its status: promotion is the
	 * operator's job), a provider failure or an empty answer skips the property.
	 *
	 * @param ObjectTranslationRequest $request  The object and languages.
	 * @param string                   $property The property name.
	 *
	 * @return array{translated: bool, text: string} The translation, or the reason it was skipped.
	 */
	private function translateProperty(ObjectTranslationRequest $request, string $property): array {
		$existing = $request->data[$property] ?? null;
		$sourceValue = $this->sourceValue(existing: $existing, fromLong: $request->fromLong);
		if ($this->isText(value: $sourceValue) === false) {
			return ['translated' => false, 'text' => 'no-source-value'];
		}

		if ($this->targetSlotFilled(existing: $existing, toLong: $request->toLong) === true) {
			return ['translated' => false, 'text' => 'target-slot-already-filled'];
		}

		return $this->providerTranslation(translation: new PropertyTranslation(request: $request, property: $property), sourceValue: $sourceValue);
	}//end translateProperty()

	/**
	 * Ask the provider to translate a property's source value, and mirror a usable answer into the sidecar.
	 *
	 * @param PropertyTranslation $translation The object, the languages and the property.
	 * @param string              $sourceValue The text in the source language.
	 *
	 * @return array{translated: bool, text: string}
	 */
	private function providerTranslation(PropertyTranslation $translation, string $sourceValue): array {
		try {
			$translatedValue = $this->provider->translate($sourceValue, $translation->languages());
		} catch (\Throwable $e) {
			$this->logProviderFailure(translation: $translation, error: $e);
			return ['translated' => false, 'text' => 'provider-error: ' . $e->getMessage()];
		}

		if ($this->isText(value: $translatedValue) === false) {
			return ['translated' => false, 'text' => 'provider-returned-empty'];
		}

		$this->mirrorTranslation(translation: $translation, value: $translatedValue);
		return ['translated' => true, 'text' => $translatedValue];
	}//end providerTranslation()

	/**
	 * Log a provider that failed to translate a property.
	 *
	 * @param PropertyTranslation $translation The object, the languages and the property.
	 * @param \Throwable          $error       The provider's failure.
	 *
	 * @return void
	 */
	private function logProviderFailure(PropertyTranslation $translation, \Throwable $error): void {
		$this->logger->warning(
			sprintf(
				'[BulkTranslationService] provider %s failed for %s/%s -> %s: %s',
				$this->provider->getIdentifier(),
				$translation->request->object->getUuid(),
				$translation->property,
				$translation->request->toLong,
				$error->getMessage()
			)
		);
	}//end logProviderFailure()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isText(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isText()

	/**
	 * The source-language value of a property: its `fromLong` slot, or a plain string as legacy
	 * single-language Dutch.
	 *
	 * @param mixed  $existing The property's stored value.
	 * @param string $fromLong Source language code.
	 *
	 * @return mixed The source value, null when there is none.
	 */
	private function sourceValue(mixed $existing, string $fromLong): mixed {
		if (is_array($existing) === true && isset($existing[$fromLong]) === true) {
			return $existing[$fromLong];
		}

		if (is_string($existing) === true && $fromLong === 'nl') {
			return $existing;
		}

		return null;
	}//end sourceValue()

	/**
	 * Whether a property already holds a non-empty target-language value.
	 *
	 * @param mixed  $existing The property's stored value.
	 * @param string $toLong   Target language code.
	 *
	 * @return bool True when the target slot is filled.
	 */
	private function targetSlotFilled(mixed $existing, string $toLong): bool {
		return is_array($existing) === true
			&& isset($existing[$toLong]) === true
			&& is_string($existing[$toLong]) === true
			&& $existing[$toLong] !== '';
	}//end targetSlotFilled()

	/**
	 * Write a machine translation into the sidecar, so search and completeness queries see it
	 * before the caller persists the object. A failure is logged, not thrown.
	 *
	 * @param PropertyTranslation $translation The object, the languages and the property.
	 * @param string              $value       The translated value.
	 *
	 * @return void
	 */
	private function mirrorTranslation(PropertyTranslation $translation, string $value): void {
		try {
			$this->stores->translationMapper->upsert(
				slot: $translation->targetSlot(),
				edit: new TranslationEdit(
					value: $value,
					status: Translation::STATUS_MACHINE_TRANSLATED,
					translator: 'provider:' . $this->provider->getIdentifier()
				)
			);
		} catch (\Throwable $e) {
			$this->logMirrorFailure(translation: $translation, error: $e);
		}
	}//end mirrorTranslation()

	/**
	 * Log a sidecar write that failed; the translation itself still counts.
	 *
	 * @param PropertyTranslation $translation The object, the languages and the property.
	 * @param \Throwable          $error       The write's failure.
	 *
	 * @return void
	 */
	private function logMirrorFailure(PropertyTranslation $translation, \Throwable $error): void {
		$this->logger->warning(
			sprintf(
				'[BulkTranslationService] sidecar upsert failed for %s/%s/%s: %s',
				$translation->request->object->getUuid(),
				$translation->property,
				$translation->request->toLong,
				$error->getMessage()
			)
		);
	}//end logMirrorFailure()

	/**
	 * Resolve a schema entity from an object's schema reference.
	 *
	 * @param ObjectEntity $object The object whose schema reference should be resolved.
	 *
	 * @return Schema|null The resolved schema, or null when not resolvable.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$ref = $object->getSchema();
		if ($ref === null || $ref === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end loadSchema()
}//end class
