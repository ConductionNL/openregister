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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use Psr\Log\LoggerInterface;

class BulkTranslationService {
	/**
	 * Constructor.
	 *
	 * @param TranslationProviderInterface $provider The translation provider.
	 * @param TranslationMapperInterface $translationMapper The translation mapper.
	 * @param TranslationHandler $translationHandler The translation handler.
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly TranslationProviderInterface $provider,
		private readonly TranslationMapperInterface $translationMapper,
		private readonly TranslationHandler $translationHandler,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate an object's translatable properties from one language to another.
	 *
	 * Fills only slots that are currently empty in the target language.
	 *
	 * Returns a per-property patch:
	 *   `[propertyName => translatedValue]`
	 * The caller merges this into the object's `{lang: value}` JSONB
	 * map for `$toLong` and persists via the standard save path —
	 * which triggers the projection listener to populate the sidecar.
	 *
	 * Note: this method also writes directly to the sidecar so the
	 * translation is queryable immediately, even before the caller
	 * persists the object. Callers who don't want that immediate
	 * write should invoke the provider directly.
	 *
	 * @param ObjectEntity $object The object to translate.
	 * @param string $fromLong Source language code.
	 * @param string $toLong Target language code.
	 * @param string[]|null $properties Optional whitelist of property
	 *                                  names to translate; null = all.
	 *
	 * @return array{translated: array<string, string>, skipped: array<string, string>}
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function translateObject(
		ObjectEntity $object,
		string $fromLong,
		string $toLong,
		?array $properties = null,
	): array {
		if ($fromLong === $toLong) {
			return ['translated' => [], 'skipped' => ['_global' => 'fromLang === toLang']];
		}

		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return ['translated' => [], 'skipped' => ['_global' => 'schema-not-resolvable']];
		}

		$translatableProps = $this->translationHandler->getTranslatableProperties($schema);
		if (count($translatableProps) === 0) {
			return ['translated' => [], 'skipped' => ['_global' => 'no-translatable-properties']];
		}

		$request = new ObjectTranslationRequest(object: $object, fromLong: $fromLong, toLong: $toLong);
		$result = ['translated' => [], 'skipped' => []];
		foreach ($translatableProps as $property) {
			if (is_array($properties) === true && in_array($property, $properties, true) === false) {
				continue;
			}

			$value = $this->translateProperty(request: $request, property: $property);
			if ($value['translated'] === true) {
				$result['translated'][$property] = $value['text'];
				continue;
			}

			$result['skipped'][$property] = $value['text'];
		}

		return $result;
	}//end translateObject()

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
		if (is_string($sourceValue) === false || $sourceValue === '') {
			return ['translated' => false, 'text' => 'no-source-value'];
		}

		if ($this->targetSlotFilled(existing: $existing, toLong: $request->toLong) === true) {
			return ['translated' => false, 'text' => 'target-slot-already-filled'];
		}

		try {
			$translatedValue = $this->provider->translate($sourceValue, $request->fromLong, $request->toLong);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[BulkTranslationService] provider %s failed for %s/%s -> %s: %s',
					$this->provider->getIdentifier(),
					$request->object->getUuid(),
					$property,
					$request->toLong,
					$e->getMessage()
				)
			);
			return ['translated' => false, 'text' => 'provider-error: ' . $e->getMessage()];
		}

		if (is_string($translatedValue) === false || $translatedValue === '') {
			return ['translated' => false, 'text' => 'provider-returned-empty'];
		}

		$this->mirrorTranslation(request: $request, translation: ['property' => $property, 'value' => $translatedValue]);
		return ['translated' => true, 'text' => $translatedValue];
	}//end translateProperty()

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
	 * @param ObjectTranslationRequest $request     The object and languages.
	 * @param array                    $translation The property and its translated value.
	 *
	 * @return void
	 */
	private function mirrorTranslation(ObjectTranslationRequest $request, array $translation): void {
		try {
			$this->translationMapper->upsert(
				objectUuid: (string)$request->object->getUuid(),
				property: $translation['property'],
				language: $request->toLong,
				value: $translation['value'],
				status: Translation::STATUS_MACHINE_TRANSLATED,
				translator: 'provider:' . $this->provider->getIdentifier()
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[BulkTranslationService] sidecar upsert failed for %s/%s/%s: %s',
					$request->object->getUuid(),
					$translation['property'],
					$request->toLong,
					$e->getMessage()
				)
			);
		}
	}//end mirrorTranslation()

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
