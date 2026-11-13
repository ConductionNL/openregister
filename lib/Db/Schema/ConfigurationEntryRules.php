<?php

/**
 * ConfigurationEntryRules: which rule validates one schema configuration key.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;

/**
 * Routes one configuration key to the rule that validates or converts its value.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class ConfigurationEntryRules {
	// `objectSlugField` was missing here, so every save and import dropped it
	// and object slugs could not be switched on outside the database (#4037).
	private const CONFIG_STRING_FIELDS = ['objectNameField', 'objectDescriptionField', 'objectSummaryField', 'objectImageField', 'objectSlugField'];
	// `exportable` opts the schema into nextcloud-vue's native Export menu
	// on an index page (or#4103). Off the allowlist it was dropped without
	// a log line, so `allowExport: true` on every app page was a no-op.
	// `staging` and `additionalProperties` are read by FormDestinationValidator
	// (decision 179): a staging schema is no form destination, and a form
	// field into an undeclared property is a finding unless the schema opts
	// into extras. Off this list both were dropped on save, so neither could
	// ever be declared.
	// `draftsListed` (decision 216) is read by DraftListLens: false keeps the
	// caller's own drafts out of the schema's lists by default.
	// `autoShare` is the schema-level default FilePropertyHandler reads for
	// every file property. `objectSlugSuffix` (#4037) is read by
	// ObjectSlugHandler: true appends a UTC date-time suffix to a colliding slug.
	// `fireEventsOnBulkWrites` is read by BulkWriteEvents: whether a bulk write
	// runs the save events per row. `orphanCleanup` is read by SaveObject: a removed
	// reference soft-deletes the child the parent owns.
	private const CONFIG_BOOL_FIELDS = [
		'allowFiles',
		'autoShare',
		'defaultAutoShare',
		'exportable',
		'staging',
		'additionalProperties',
		'draftsVisible',
		'draftsListed',
		'fireEventsOnBulkWrites',
		'objectSlugSuffix',
		'orphanCleanup',
	];
	// `implements` + `x-schema-org` carry the cross-app semantic-type
	// markers (ADR-048); they must round-trip through the configuration
	// column so SemanticTypeResolver can discover the schema. Their IRI
	// shape is validated on read by JsonLdContextService::getImplementedTypes.
	// `handoffContract` is the ADR-051 provider-side binding block
	// (contract field → own property, per kind URI); its shape is
	// validated at save time by HandoffContractBindingValidator.
	// `importSource` is the schema-import provenance block (dialect,
	// reference, snapshot version, baseline) written by
	// SchemaImportService and read back by the reimport / update-from-
	// source endpoint; without it in the allowlist the provenance is
	// silently dropped on save and the entire update-from-source feature
	// is dead (the schema reports "not imported from a standard").
	// `uniqueConstraints` is the declared uniqueness block that
	// UniqueConstraintEvaluator and UniqueConstraintListener read (named
	// refuse/report constraints, beside the legacy `unique`). Off this
	// list every save dropped it in silence and no constraint ever fired.
	// `autoPublish` is the retired name of `autoShare`. It is kept unvalidated
	// and never acted on, so FilePropertyHandler can still log the
	// deprecation warning that names `autoShare`.
	private const CONFIG_PASS_THROUGH = [
		'autoPublish',
		'unique',
		'uniqueConstraints',
		'facetCacheTtl',
		'calendarProvider',
		'jsonld',
		'implements',
		'x-schema-org',
		'handoffContract',
		'importSource',
	];

	/**
	 * The schema's declared properties, which a write-only path must be rooted at.
	 *
	 * @var array|null
	 */
	private readonly ?array $properties;

	/**
	 * The value rules of the plain keys.
	 *
	 * @var ConfigurationValueRules
	 */
	private readonly ConfigurationValueRules $values;

	/**
	 * The linkRoles and partyKinds vocabularies.
	 *
	 * @var VocabularyEntries
	 */
	private readonly VocabularyEntries $vocabulary;

	/**
	 * Constructor.
	 *
	 * @param array|null $properties The schema's declared properties.
	 */
	public function __construct(?array $properties) {
		$this->properties = $properties;
		$this->values = new ConfigurationValueRules();
		$this->vocabulary = new VocabularyEntries();
	}//end __construct()

	/**
	 * Validate a single configuration entry.
	 *
	 * Each key is validated in isolation (#419): the caller wraps this in a
	 * try/catch, and an invalid value drops only the offending key instead of
	 * the whole configuration. Unknown keys are not stored, and unknown
	 * `x-openregister-*` keys are recorded by ConfigurationValidator.
	 *
	 * @param int|string $key   Configuration key.
	 * @param mixed      $value Raw value for the key.
	 *
	 * @throws \InvalidArgumentException If the value is invalid for the key.
	 *
	 * @return array<int, mixed> The value to store as a one-element list; empty when the key is not stored.
	 */
	public function validateConfigurationEntry(int|string $key, mixed $value): array {
		if (in_array($key, self::CONFIG_STRING_FIELDS, true) === true) {
			return [$this->values->validateStringConfigValue(key: $key, value: $value)];
		}

		if ($this->validatesRawConfigurationValue(key: (string)$key, value: $value) === true
			|| in_array($key, self::CONFIG_PASS_THROUGH, true) === true
		) {
			return [$value];
		}

		return $this->convertedConfigurationValue(key: (string)$key, value: $value);
	}//end validateConfigurationEntry()

	/**
	 * Validate a key whose value is stored as given.
	 *
	 * @param string $key   Configuration key.
	 * @param mixed  $value Raw value for the key.
	 *
	 * @throws \InvalidArgumentException If the value is invalid for the key.
	 *
	 * @return bool True when the key is one of these and its value passed.
	 */
	private function validatesRawConfigurationValue(string $key, mixed $value): bool {
		if (in_array($key, self::CONFIG_BOOL_FIELDS, true) === true) {
			$this->values->validateBoolConfigValue(key: $key, value: $value);
			return true;
		}

		// A `calendarProvider` that is not an array is passed through unvalidated.
		if ($key === 'calendarProvider' && is_array($value) === true) {
			$this->values->validateCalendarProviderConfig(config: $value);
			return true;
		}

		return $this->validatesShapedConfigurationValue(key: $key, value: $value);
	}//end validatesRawConfigurationValue()

	/**
	 * Validate the tag, linked-type and mail-template keys, stored as given.
	 *
	 * @param string $key   The configuration key.
	 * @param mixed  $value The value given for that key.
	 *
	 * @throws \InvalidArgumentException If the tags, linked types or mail template are invalid.
	 *
	 * @return bool Whether the key is one of these three (its value then passed).
	 */
	private function validatesShapedConfigurationValue(string $key, mixed $value): bool {
		if ($key === 'allowedTags') {
			$this->values->validateAllowedTagsValue(value: $value);
			return true;
		}

		if ($key === 'linkedTypes') {
			(new LinkedTypesRule())->validateLinkedTypesValue(value: $value);
			return true;
		}

		return $this->validatesMailTemplateConfigurationValue(key: $key, value: $value);
	}//end validatesShapedConfigurationValue()

	/**
	 * Check `mailObjectTemplate`, which is stored as given once it passes.
	 *
	 * @param string $key   The key being validated.
	 * @param mixed  $value What the configuration gives for it.
	 *
	 * @throws \InvalidArgumentException If the template is not a flat map of scalars.
	 *
	 * @return bool Whether the key was `mailObjectTemplate`.
	 */
	private function validatesMailTemplateConfigurationValue(string $key, mixed $value): bool {
		if ($key !== 'mailObjectTemplate') {
			return false;
		}

		$this->values->validateMailObjectTemplateValue(value: $value);
		return true;
	}//end validatesMailTemplateConfigurationValue()

	/**
	 * Validate a key whose value is normalised before it is stored.
	 *
	 * `draftLifetimeDays` (decisions 216 and 228) is read by DraftStatusPolicy
	 * and acted on by DraftExpiryJob. Absent or null means the default of 30
	 * days, so null is not stored.
	 *
	 * @param string $key   The configuration key.
	 * @param mixed  $value The value given for that key.
	 *
	 * @throws \InvalidArgumentException If the value cannot be normalised.
	 *
	 * @return array<int, mixed> The normalised value as a one-element list; empty when nothing is stored for the key.
	 */
	private function convertedConfigurationValue(string $key, mixed $value): array {
		if ($key === 'draftLifetimeDays') {
			return $this->draftLifetimeEntry(value: $value);
		}

		if ($key === 'linkRoles' || $key === 'partyKinds') {
			return [$this->validateVocabularyValue(key: $key, value: $value)];
		}

		if ($key === Schema::WRITEONLY_PATHS_ANNOTATION) {
			return [(new WriteOnlyPathsDeclaration())->validateWriteOnlyPathsValue(value: $value, properties: $this->properties)];
		}

		return $this->annotationConfigurationValue(key: $key, value: $value);
	}//end convertedConfigurationValue()

	/**
	 * The `draftLifetimeDays` value to store, as a one-element list; empty for null, which means the default.
	 *
	 * @param mixed $value Raw value for the key.
	 *
	 * @throws \InvalidArgumentException If the value is invalid.
	 *
	 * @return array<int, int|false>
	 */
	private function draftLifetimeEntry(mixed $value): array {
		if ($value === null) {
			return [];
		}

		return [$this->values->validateDraftLifetimeValue(value: $value)];
	}//end draftLifetimeEntry()

	/**
	 * Validate `linkRoles` or `partyKinds`.
	 *
	 * @param string $key   `linkRoles` or `partyKinds`.
	 * @param mixed  $value Raw value for the key.
	 *
	 * @throws \InvalidArgumentException If the value is not a list of valid entries.
	 *
	 * @return array<int, array<string, mixed>> The normalised entries.
	 */
	private function validateVocabularyValue(string $key, mixed $value): array {
		if ($key === 'linkRoles') {
			return $this->vocabulary->validateLinkRolesValue(value: $value);
		}

		return $this->vocabulary->validatePartyKindsValue(value: $value);
	}//end validateVocabularyValue()

	/**
	 * Keep a declared `x-openregister` or `x-openregister-*` annotation; record an unknown one.
	 *
	 * Declarative annotation extensions round-trip through the configuration
	 * column; their shape is validated by the dedicated validators (e.g.
	 * LifecycleAnnotationValidator) at schema-save time. An unknown
	 * `x-openregister-*` key (almost always a typo like `x-openregister-lifecycl`)
	 * is dropped; ConfigurationValidator records it, so SchemaMapper can log it after save.
	 *
	 * @param string $key   Configuration key.
	 * @param mixed  $value Raw value for the key.
	 *
	 * @return array<int, mixed> The value to store as a one-element list; empty when the key is not stored.
	 */
	private function annotationConfigurationValue(string $key, mixed $value): array {
		// The bare `x-openregister` block (expired-object-sweep's `expiry`) is kept like any declared annotation.
		if ($key !== 'x-openregister' && str_starts_with($key, 'x-openregister-') === false) {
			return [];
		}

		if (in_array($key, AnnotationVocabulary::KEYS, true) === true) {
			return [$value];
		}

		// Recorded as dropped by ConfigurationValidator.
		return [];
	}//end annotationConfigurationValue()
}//end class
