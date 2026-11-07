<?php

/**
 * OpenRegister TranslationProjectionService
 *
 * Keeps the `openregister_translations` sidecar in sync with the
 * authoritative JSONB property data on each object. Called by
 * `TranslationProjectionListener` on object create / update / delete.
 *
 * The JSONB on the object remains the source of truth; the sidecar
 * is a derived projection optimised for per-language search,
 * completeness queries, and workflow status tracking.
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\Object\PropertySourceLanguage;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TranslationProjectionService implements TranslationProjectionServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param TranslationMapperInterface $translationMapper The translation mapper.
	 * @param TranslationHandler $translationHandler The translation handler.
	 * @param SchemaMapperInterface $schemaMapper The schema mapper.
	 * @param RegisterMapperInterface $registerMapper The register mapper.
	 * @param IUserSession $userSession The user session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly TranslationMapperInterface $translationMapper,
		private readonly TranslationHandler $translationHandler,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project the given object's translatable property data into the sidecar.
	 *
	 * Behaviour:
	 *  - For each translatable property declared on the schema, walk
	 *    the object's data shape `{lang: value, ...}` (or fall back to
	 *    a single-language string treated as the register's default).
	 *  - Upsert one row per (uuid, property, language) that has a
	 *    non-empty value.
	 *  - Delete any pre-existing row whose (property, language) no
	 *    longer has a value on the object.
	 *
	 * Status defaults to `draft` on first projection; subsequent saves
	 * preserve whatever status the slot already had (the projection
	 * doesn't second-guess workflow state — that's `TranslationStatusService`'s job).
	 *
	 * @param ObjectEntity $object The object to project.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function project(ObjectEntity $object): void {
		$uuid = $object->getUuid();
		if ($uuid === null || $uuid === '') {
			return;
		}

		try {
			$this->projectObject(object: $object, uuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[TranslationProjection] failed for object %s: %s', $uuid, $e->getMessage())
			);
		}
	}//end project()

	/**
	 * Upsert the object's desired slots, then delete every other row of the object.
	 *
	 * @param ObjectEntity $object The object to project.
	 * @param string $uuid The object's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function projectObject(ObjectEntity $object, string $uuid): void {
		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return;
		}

		// With no translatable properties nothing is desired, so every
		// row left over from when a property was translatable is removed.
		$properties = $this->translationHandler->getTranslatableProperties($schema);
		$keptKeys = [];
		if (count($properties) > 0) {
			$sources = $this->sourceLanguages(object: $object, target: ['schema' => $schema, 'properties' => $properties]);
			$slots = $this->desiredSlots(data: (array)($object->getObject() ?? []), sources: $sources);
			$keptKeys = $this->upsertSlots(uuid: $uuid, plan: ['slots' => $slots, 'sources' => $sources]);
		}

		$this->deleteRowsNotIn(uuid: $uuid, keptKeys: $keptKeys);
	}//end projectObject()

	/**
	 * The resolved source language of each translatable property (i18n-source-of-truth).
	 *
	 * @param ObjectEntity                                $object The object.
	 * @param array{schema: Schema, properties: string[]} $target The schema and its translatable properties.
	 *
	 * @return array<string, string> Source language by property.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function sourceLanguages(ObjectEntity $object, array $target): array {
		$defaultLanguage = ($this->loadRegister(object: $object)?->getDefaultLanguage() ?? 'nl');

		$sources = [];
		foreach ($target['properties'] as $property) {
			$sources[$property] = $this->resolveSourceLanguage(
				schema: $target['schema'],
				object: $object,
				property: $property,
				registerDefault: $defaultLanguage
			);
		}

		return $sources;
	}//end sourceLanguages()

	/**
	 * The non-empty (property, language, value) slots the object's data asks for.
	 *
	 * A language-keyed value `{nl: "...", en: "..."}` gives one slot per
	 * language; a plain string is credited to the property's source language.
	 *
	 * @param array                 $data    The object's data.
	 * @param array<string, string> $sources Source language by translatable property.
	 *
	 * @return array<string, array<string, string>> Value by language, by property.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function desiredSlots(array $data, array $sources): array {
		$desired = [];
		foreach ($sources as $property => $sourceLanguage) {
			$slots = $this->propertySlots(value: ($data[$property] ?? null), sourceLanguage: $sourceLanguage);
			if ($slots !== []) {
				$desired[$property] = $slots;
			}
		}

		return $desired;
	}//end desiredSlots()

	/**
	 * The slots of one property value: a plain string is credited to the
	 * source language, a language-keyed value gives one slot per language.
	 *
	 * @param mixed  $value          The property's stored value.
	 * @param string $sourceLanguage The property's source language.
	 *
	 * @return array<string, string> Value by language.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function propertySlots(mixed $value, string $sourceLanguage): array {
		if (is_string($value) === true && $value !== '') {
			return [$sourceLanguage => $value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return $this->languageSlots(value: $value);
	}//end propertySlots()

	/**
	 * The non-empty values of a language-keyed value, as strings, by language.
	 *
	 * @param array $value The language-keyed value.
	 *
	 * @return array<string, string> Value by language.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function languageSlots(array $value): array {
		$slots = [];
		foreach ($value as $language => $languageValue) {
			$stringValue = $this->valueToString(value: $languageValue);
			if ($this->isSlot(language: $language, value: $stringValue) === true) {
				$slots[$language] = $stringValue;
			}
		}

		return $slots;
	}//end languageSlots()

	/**
	 * Whether a (language, value) pair is a slot: a non-empty string language and a non-empty value.
	 *
	 * @param int|string  $language The language key.
	 * @param string|null $value    The coerced value.
	 *
	 * @return bool
	 */
	private function isSlot(int|string $language, ?string $value): bool {
		return is_string($language) === true && $language !== '' && (string)$value !== '';
	}//end isSlot()

	/**
	 * Upsert every desired slot, keeping an existing slot's status and defaulting a new one to draft.
	 *
	 * @param string                                                                   $uuid The object's uuid.
	 * @param array{slots: array<string, array<string, string>>, sources: array<string, string>} $plan The slots and source languages.
	 *
	 * @return string[] The `property|language` keys written.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function upsertSlots(string $uuid, array $plan): array {
		$context = ['uuid' => $uuid, 'translator' => $this->userSession->getUser()?->getUID()];
		$keys = [];
		foreach ($plan['slots'] as $property => $byLanguage) {
			$slot = array_merge($context, ['property' => $property, 'sourceLanguage' => $plan['sources'][$property]]);
			$this->upsertProperty(slot: $slot, byLanguage: $byLanguage);
			foreach (array_keys($byLanguage) as $language) {
				$keys[] = $property . '|' . $language;
			}
		}

		return $keys;
	}//end upsertSlots()

	/**
	 * Upsert one property's slots; status null keeps an existing slot's status and drafts a new one.
	 *
	 * @param array{uuid: string, translator: ?string, property: string, sourceLanguage: string} $slot       The row identity.
	 * @param array<string, string>                                                           $byLanguage Value by language.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function upsertProperty(array $slot, array $byLanguage): void {
		foreach ($byLanguage as $language => $stringValue) {
			$this->translationMapper->upsert(
				objectUuid: $slot['uuid'],
				property: $slot['property'],
				language: $language,
				value: $stringValue,
				status: null,
				translator: $slot['translator'],
				sourceLanguage: $slot['sourceLanguage']
			);
		}
	}//end upsertProperty()

	/**
	 * Delete, best effort, the object's rows whose `property|language` was not just written.
	 *
	 * @param string   $uuid     The object's uuid.
	 * @param string[] $keptKeys The keys to keep.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	private function deleteRowsNotIn(string $uuid, array $keptKeys): void {
		foreach ($this->translationMapper->findByObject($uuid) as $row) {
			if (in_array($row->getProperty() . '|' . $row->getLanguage(), $keptKeys, true) === true) {
				continue;
			}

			try {
				$this->translationMapper->delete($row);
			} catch (\Throwable $e) {
				// Best effort.
			}
		}
	}//end deleteRowsNotIn()

	/**
	 * Drop every translation row for the given object.
	 *
	 * @param ObjectEntity $object The object to purge translations for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function purge(ObjectEntity $object): void {
		$uuid = $object->getUuid();
		if ($uuid === null || $uuid === '') {
			return;
		}

		try {
			$this->translationMapper->deleteByObject($uuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[TranslationProjection] purge failed for object %s: %s', $uuid, $e->getMessage())
			);
		}
	}//end purge()

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

	/**
	 * Resolve a register entity from an object's register reference.
	 *
	 * @param ObjectEntity $object The object whose register reference should be resolved.
	 *
	 * @return Register|null The resolved register, or null when not resolvable.
	 */
	private function loadRegister(ObjectEntity $object): ?Register {
		$ref = $object->getRegister();
		if ($ref === null || $ref === '') {
			return null;
		}

		try {
			return $this->registerMapper->find((string)$ref, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end loadRegister()

	/**
	 * Resolve the source language for a single (object, property) tuple.
	 *
	 * Lookup chain (highest precedence first):
	 *   1. object body `_translationMeta.<property>.sourceLanguage`
	 *   2. schema `properties.<property>.sourceLanguage`
	 *   3. supplied register default
	 *   4. hardcoded `'nl'` fallback
	 *
	 * @param Schema $schema The schema declaring the property.
	 * @param ObjectEntity $object The object body containing optional override.
	 * @param string $property The translatable property name.
	 * @param string $registerDefault Register-level default language.
	 *
	 * @return string The resolved BCP-47 source language for the property.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
	 */
	public function resolveSourceLanguage(
		Schema $schema,
		ObjectEntity $object,
		string $property,
		string $registerDefault,
	): string {
		$rule = new PropertySourceLanguage(schema: $schema, registerDefault: $registerDefault);
		return $rule->forProperty(body: (array)($object->getObject() ?? []), property: $property);
	}//end resolveSourceLanguage()

	/**
	 * Coerce a translatable value to a string for storage.
	 *
	 * Most properties are strings; we accept scalar / null / arrays-of-strings and return
	 * either a string or null.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return string|null The coerced string, or null when not coercible.
	 */
	private function valueToString(mixed $value): ?string {
		if (is_array($value) === true) {
			// Translatable-array property (rare); flatten to JSON for searchability.
			return json_encode($value, JSON_UNESCAPED_SLASHES);
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return null;
	}//end valueToString()
}//end class
