<?php

/**
 * OpenRegister Schema Property Validator
 *
 * This file contains the class for validating schema properties
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

namespace OCA\OpenRegister\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Search\PropertySearchProfile;

/**
 * Class PropertyValidatorHandler
 *
 * Service class for validating schema properties according to JSON Schema specification
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex JSON Schema property validation logic
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class PropertyValidatorHandler {

	/**
	 * The property types the layer accepts, and what an editor should say about each.
	 *
	 * This table is the vocabulary. `PropertyVocabulary` publishes it and
	 * `$validTypes` is derived from its keys, so the list a form is generated
	 * from and the list the save path checks against are one array. A type
	 * added here is accepted, published and documented in the same edit, and
	 * there is no second file to forget.
	 *
	 * `conversion` answers the question an administrator asks right after
	 * "what types are there": what happens to the objects that already exist.
	 * `supported` means every stored value survives the change. `conditional`
	 * means values that do not parse are refused, so the change needs a
	 * migration run. `unsupported` means the stored shape cannot be derived
	 * from the old one at all.
	 *
	 * @var array<string, array{category: string, conversion: string, conversionNote: string, description: string}>
	 */
	public const TYPES = [
		'string' => [
			'category' => 'text',
			'conversion' => 'supported',
			'conversionNote' => 'Every stored value can be read back as text, so populated objects survive the change.',
			'description' => 'Text. Add a format to say which kind of text it is.',
		],
		'number' => [
			'category' => 'numeric',
			'conversion' => 'conditional',
			'conversionNote' => 'A stored value that is not numeric is refused, so run a migration first.',
			'description' => 'A number with decimals.',
		],
		'integer' => [
			'category' => 'numeric',
			'conversion' => 'conditional',
			'conversionNote' => 'A stored value that is not a whole number is refused, so run a migration first.',
			'description' => 'A whole number.',
		],
		'boolean' => [
			'category' => 'numeric',
			'conversion' => 'conditional',
			'conversionNote' => 'Only true, false and the strings that spell them convert.',
			'description' => 'Yes or no.',
		],
		'array' => [
			'category' => 'composite',
			'conversion' => 'conditional',
			'conversionNote' => 'A single stored value becomes a list of one. The other direction loses everything after the first entry.',
			'description' => 'A list. Use items to say what one entry looks like.',
		],
		'object' => [
			'category' => 'composite',
			'conversion' => 'conditional',
			'conversionNote' => 'A stored value that is not an object is refused, so run a migration first.',
			'description' => 'A nested set of properties, stored inside this object.',
		],
		'null' => [
			'category' => 'composite',
			'conversion' => 'supported',
			'conversionNote' => 'Nothing is stored, so there is nothing to convert.',
			'description' => 'No value at all. Use it inside oneOf to allow an empty answer.',
		],
		'file' => [
			'category' => 'file',
			'conversion' => 'unsupported',
			'conversionNote' => 'A file has to be uploaded. No stored value converts into one.',
			'description' => 'An uploaded file, stored in the object folder.',
		],
		'geo' => [
			'category' => 'spatial',
			'conversion' => 'unsupported',
			'conversionNote' => 'Coordinates cannot be derived from another value. Import them instead.',
			'description' => 'A point or a shape on the map.',
		],
		'color' => [
			'category' => 'presentation',
			'conversion' => 'conditional',
			'conversionNote' => 'A stored value that is not a colour is refused, so run a migration first.',
			'description' => 'A colour, written as hex, rgb or hsl.',
		],
		'recurrence' => [
			'category' => 'temporal',
			'conversion' => 'unsupported',
			'conversionNote' => 'A repeat rule cannot be derived from a date or a sentence.',
			'description' => 'A repeat rule, such as every second Tuesday.',
		],
		'NcFile' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a file that has to exist first.',
			'description' => 'A link to a file already in Nextcloud.',
		],
		'NcMail' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a message that has to exist first.',
			'description' => 'A link to a message in Nextcloud Mail.',
		],
		'NcContact' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a contact that has to exist first.',
			'description' => 'A link to a contact in Nextcloud Contacts.',
		],
		'NcNote' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a note that has to exist first.',
			'description' => 'A link to a note in Nextcloud Notes.',
		],
		'NcTodo' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a task that has to exist first.',
			'description' => 'A link to a task in Nextcloud Tasks.',
		],
		'NcCalendarEvent' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at an event that has to exist first.',
			'description' => 'A link to an event in Nextcloud Calendar.',
		],
		'NcTalk' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a conversation that has to exist first.',
			'description' => 'A link to a conversation in Nextcloud Talk.',
		],
		'NcDeck' => [
			'category' => 'nextcloud',
			'conversion' => 'unsupported',
			'conversionNote' => 'The reference points at a card that has to exist first.',
			'description' => 'A link to a card in Nextcloud Deck.',
		],
	];

	/**
	 * Valid string formats for JSON Schema
	 *
	 * A format this allowlist accepts is one the value validator can actually
	 * enforce, so the two lists must agree. See `bsn` and `user` below for
	 * what it costs when they drift apart.
	 *
	 * @var array<string> List of valid string formats
	 */
	public const STRING_FORMATS = [
		'',
		// Text content formats.
		'text',
		'markdown',
		'html',
		// Standard JSON Schema formats.
		'date-time',
		'date',
		'time',
		'duration',
		'email',
		'idn-email',
		'hostname',
		'idn-hostname',
		'ipv4',
		'ipv6',
		'uri',
		'uri-reference',
		'iri',
		'iri-reference',
		'uuid',
		'uri-template',
		'json-pointer',
		'relative-json-pointer',
		'regex',
		'url',
		// Additional type.
		'color',
		// Additional type.
		'color-hex',
		// Additional type.
		'color-hex-alpha',
		// Additional type.
		'color-rgb',
		// Additional type.
		'color-rgba',
		// Additional type.
		'color-hsl',
		// Additional type.
		'color-hsla',
		// Semantic versioning format.
		'semver',
		// Dutch burgerservicenummer, checked with the 11-proef.
		//
		// BsnFormat has existed and been REGISTERED with the value validator all
		// along (ValidateObject::registerCustomFormat), so OpenRegister could
		// already checksum a BSN. It simply refused to accept a schema that
		// said so, because this allowlist never got the entry. procest declares
		// `format: bsn` on a burgerservicenummer, and that one missing word
		// failed its schema import, then schema creation, then its "Load default
		// ZGW API mapping configurations" repair step. A built and wired feature
		// was unreachable because two lists disagreed.
		'bsn',
		// Nextcloud user id, checked against the user backend. The referenced
		// user must exist. See UserFormat.
		'user',
	];

	/**
	 * The constraint keys a property may carry, and which types take them.
	 *
	 * `appliesTo` lists the types the key is meaningful on, or `*` for every
	 * type. A key outside this table, outside the modifiers and outside the
	 * pass-through set fails the save naming itself, so a typo cannot quietly
	 * become a property that constrains nothing.
	 *
	 * `breaking` marks the keys that change how stored objects validate.
	 * Adding a `format` to a property that already holds values is the one
	 * our own notes keep rediscovering.
	 *
	 * @var array<string, array{appliesTo: array<int, string>, value: string, breaking: bool, description: string}>
	 */
	public const CONSTRAINTS = [
		'format' => [
			'appliesTo' => ['string'],
			'value' => 'string',
			'breaking' => true,
			'description' => 'Which kind of text this is. Adding one to a populated property is breaking.',
		],
		'pattern' => [
			'appliesTo' => ['string'],
			'value' => 'string',
			'breaking' => true,
			'description' => 'A regular expression the value must match.',
		],
		'minLength' => [
			'appliesTo' => ['string'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The shortest text you accept.',
		],
		'maxLength' => [
			'appliesTo' => ['string'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The longest text you accept.',
		],
		'minimum' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'number',
			'breaking' => true,
			'description' => 'The lowest number you accept.',
		],
		'maximum' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'number',
			'breaking' => true,
			'description' => 'The highest number you accept.',
		],
		'exclusiveMinimum' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'number',
			'breaking' => true,
			'description' => 'The value must be above this number, not equal to it.',
		],
		'exclusiveMaximum' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'number',
			'breaking' => true,
			'description' => 'The value must be below this number, not equal to it.',
		],
		'exclusiveMin' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'boolean',
			'breaking' => true,
			'description' => 'Read minimum as "above" instead of "at least". The property form writes this one.',
		],
		'exclusiveMax' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'boolean',
			'breaking' => true,
			'description' => 'Read maximum as "below" instead of "at most". The property form writes this one.',
		],
		'multipleOf' => [
			'appliesTo' => ['number', 'integer'],
			'value' => 'number',
			'breaking' => true,
			'description' => 'The value must be a multiple of this number.',
		],
		'items' => [
			'appliesTo' => ['array'],
			'value' => 'object',
			'breaking' => true,
			'description' => 'What one entry in the list looks like.',
		],
		'minItems' => [
			'appliesTo' => ['array'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The fewest entries you accept.',
		],
		'maxItems' => [
			'appliesTo' => ['array'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The most entries you accept.',
		],
		'uniqueItems' => [
			'appliesTo' => ['array'],
			'value' => 'boolean',
			'breaking' => true,
			'description' => 'Refuse a list that repeats an entry.',
		],
		'properties' => [
			'appliesTo' => ['object'],
			'value' => 'object',
			'breaking' => true,
			'description' => 'The properties nested inside this one.',
		],
		'required' => [
			'appliesTo' => ['*'],
			'value' => 'boolean or array',
			'breaking' => true,
			'description' => 'On a property, whether an answer is required. On an object, which nested properties are.',
		],
		'additionalProperties' => [
			'appliesTo' => ['object'],
			'value' => 'boolean or object',
			'breaking' => true,
			'description' => 'Whether properties you did not declare may be stored.',
		],
		'minProperties' => [
			'appliesTo' => ['object'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The fewest nested properties you accept.',
		],
		'maxProperties' => [
			'appliesTo' => ['object'],
			'value' => 'integer',
			'breaking' => true,
			'description' => 'The most nested properties you accept.',
		],
		'enum' => [
			'appliesTo' => ['*'],
			'value' => 'array',
			'breaking' => true,
			'description' => 'The list of answers you accept. Removing an entry refuses objects that already hold it.',
		],
		'const' => [
			'appliesTo' => ['*'],
			'value' => 'any',
			'breaking' => true,
			'description' => 'The one value you accept.',
		],
		'default' => [
			'appliesTo' => ['*'],
			'value' => 'any',
			'breaking' => false,
			'description' => 'What a new object starts with.',
		],
		'oneOf' => [
			'appliesTo' => ['*'],
			'value' => 'array',
			'breaking' => true,
			'description' => 'A list of alternative shapes. Exactly one has to match.',
		],
		'$ref' => [
			'appliesTo' => ['*'],
			'value' => 'string',
			'breaking' => true,
			'description' => 'A reference to another schema. This is how one object points at another.',
		],
		'allowedTypes' => [
			'appliesTo' => ['file'],
			'value' => 'array',
			'breaking' => false,
			'description' => 'The MIME types you accept on upload.',
		],
		'allowedTags' => [
			'appliesTo' => ['file'],
			'value' => 'array',
			'breaking' => false,
			'description' => 'The tags a person may put on the upload.',
		],
		'autoTags' => [
			'appliesTo' => ['file'],
			'value' => 'array',
			'breaking' => false,
			'description' => 'The tags every upload gets automatically.',
		],
		'maxSize' => [
			'appliesTo' => ['file'],
			'value' => 'integer',
			'breaking' => false,
			'description' => 'The largest upload you accept, in bytes, up to 100 MB.',
		],
		'fileConfiguration' => [
			'appliesTo' => ['file', 'NcFile'],
			'value' => 'object',
			'breaking' => false,
			'description' => 'Where the upload lands and how many files fit.',
		],
		'allowedMimeTypes' => [
			'appliesTo' => ['file', 'NcFile'],
			'value' => 'array',
			'breaking' => false,
			'description' => 'The MIME types the upload field offers.',
		],
		'uploadMaxFiles' => [
			'appliesTo' => ['file', 'NcFile'],
			'value' => 'integer',
			'breaking' => false,
			'description' => 'How many files one answer may hold.',
		],
		'uploadMaxSizeMb' => [
			'appliesTo' => ['file', 'NcFile'],
			'value' => 'integer',
			'breaking' => false,
			'description' => 'The largest upload you accept, in megabytes.',
		],
	];

	/**
	 * The keys that change how a property behaves rather than what it accepts.
	 *
	 * These are OpenRegister's own, they are valid on every type, and they are
	 * published so a generated form offers them instead of a hand-written six.
	 *
	 * @var array<string, array{value: string, description: string}>
	 */
	public const MODIFIERS = [
		'title' => ['value' => 'string', 'description' => 'The label a person reads above the field.'],
		'description' => ['value' => 'string', 'description' => 'The sentence under the field.'],
		'example' => ['value' => 'any', 'description' => 'A sample answer, shown in the API documentation.'],
		'order' => ['value' => 'number', 'description' => 'Where the field sits in the form.'],
		'behavior' => ['value' => 'string', 'description' => 'How the field behaves in the form.'],
		'visible' => ['value' => 'boolean', 'description' => 'Set this to false and the property disappears from every read.'],
		'hideOnCollection' => ['value' => 'boolean', 'description' => 'Keep the field out of the list view.'],
		'hideOnForm' => ['value' => 'boolean', 'description' => 'Keep the field out of the form.'],
		'readOnly' => ['value' => 'boolean', 'description' => 'Show the value, refuse a write.'],
		'writeOnly' => ['value' => 'boolean', 'description' => 'Accept a write, never read it back.'],
		'immutable' => ['value' => 'boolean', 'description' => 'Accept the first answer, refuse every change after it.'],
		'deprecated' => ['value' => 'boolean', 'description' => 'Mark the field as on its way out.'],
		'facetable' => ['value' => 'boolean', 'description' => 'Offer the field as a filter in search.'],
		'facetConfig' => ['value' => 'object', 'description' => 'How the filter buckets its values.'],
		'matchType' => ['value' => 'string', 'description' => 'How search compares a term against this field: exact, prefix, range, fuzzy or fulltext.'],
		'inputControl' => ['value' => 'string', 'description' => 'The control a list surface should render to filter on this field.'],
		'aggregated' => ['value' => 'boolean', 'description' => 'Count the field in aggregations.'],
		'translatable' => ['value' => 'boolean', 'description' => 'Store one value per language.'],
		'sourceLanguage' => ['value' => 'string', 'description' => 'Which language the authored value is in. Needs translatable.'],
		'calculation' => ['value' => 'object', 'description' => 'Derive the value from other properties instead of asking for it.'],
		'computed' => ['value' => 'string', 'description' => 'A Twig expression that derives the value.'],
		'onDelete' => ['value' => 'string', 'description' => 'What happens to this object when the one it points at is deleted.'],
		'cascade' => ['value' => 'boolean', 'description' => 'Save the referenced object along with this one.'],
		'cascadeDelete' => ['value' => 'boolean', 'description' => 'Delete the referenced object along with this one.'],
		'inversedBy' => ['value' => 'string', 'description' => 'The property on the other side of the relation.'],
		'register' => ['value' => 'string', 'description' => 'The register the referenced object lives in.'],
		'schema' => ['value' => 'string', 'description' => 'The schema the referenced object follows.'],
		'writeBack' => ['value' => 'boolean', 'description' => 'Write changes back to the referenced object.'],
		'removeAfterWriteBack' => ['value' => 'boolean', 'description' => 'Drop the nested copy once it is written back.'],
		'objectConfiguration' => ['value' => 'object', 'description' => 'Whether a reference is stored nested, by id or by URL.'],
		'validateReference' => ['value' => 'boolean', 'description' => 'Check that the referenced object exists before saving.'],
		'validationStrictness' => ['value' => 'string', 'description' => 'How hard a failed check refuses the write.'],
		'referenceType' => ['value' => 'string', 'description' => 'What kind of thing the reference points at.'],
		'referenceSemanticType' => ['value' => 'string', 'description' => 'The semantic type the reference resolves to.'],
		'referenceSemanticApp' => ['value' => 'string', 'description' => 'The app that owns the semantic type.'],
		'iri' => ['value' => 'string', 'description' => 'The vocabulary term this property means.'],
		'domains' => ['value' => 'array', 'description' => 'The classes this property may be used on.'],
		'ranges' => ['value' => 'array', 'description' => 'The classes this property may point at.'],
	];

	/**
	 * Keys that are stored and handed on, but not enforced here.
	 *
	 * Standard JSON Schema keywords the layer keeps so an imported schema
	 * round-trips unchanged. They are published with the rest of the
	 * vocabulary, marked as not enforced, because a contract that hides which
	 * half it actually checks is the expensive kind of lie.
	 *
	 * Any key starting with `x-` is a vendor extension and passes through the
	 * same way, which is the JSON Schema convention and the reason an app can
	 * annotate a property without asking us first.
	 *
	 * @var array<string, string>
	 */
	public const PASSTHROUGH = [
		'$id' => 'The identifier of this sub-schema.',
		'$schema' => 'Which JSON Schema draft the author wrote against.',
		'$comment' => 'A note for whoever reads the schema next.',
		'comment' => 'A note for whoever reads the schema next.',
		'examples' => 'Sample answers, shown in the API documentation.',
		'nullable' => 'The OpenAPI 3.0 spelling of "an empty answer is allowed".',
		'allOf' => 'Every listed shape has to match. Stored, not enforced here.',
		'anyOf' => 'At least one listed shape has to match. Stored, not enforced here.',
		'not' => 'The listed shape must not match. Stored, not enforced here.',
		'if' => 'The condition of a conditional sub-schema.',
		'then' => 'The shape that applies when the condition matches.',
		'else' => 'The shape that applies when the condition does not match.',
		'contains' => 'At least one list entry has to match this shape.',
		'prefixItems' => 'The shape of the first entries of a list, in order.',
		'patternProperties' => 'Nested properties matched by name against a regular expression.',
		'propertyNames' => 'A shape every nested property name has to match.',
		'dependentRequired' => 'Which properties become required once this one is answered.',
		'contentMediaType' => 'The media type of the encoded content.',
		'contentEncoding' => 'How the content is encoded.',
	];

	/**
	 * Valid JSON Schema types
	 *
	 * Derived from {@see self::TYPES} so the published vocabulary and the
	 * list the save path checks against cannot drift apart.
	 *
	 * @var array<string> List of valid JSON Schema types
	 */
	private array $validTypes;

	/**
	 * Valid string formats for JSON Schema
	 *
	 * Derived from {@see self::STRING_FORMATS}.
	 *
	 * @var array<string> List of valid string formats
	 */
	private array $validStringFormats;

	/**
	 * Read the two allowlists off the published vocabulary.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->validTypes = array_map('strval', array_keys(self::TYPES));
		$this->validStringFormats = self::STRING_FORMATS;
	}//end __construct()

	/**
	 * Every key the vocabulary holds, in one flat list.
	 *
	 * A property key outside this list and not prefixed `x-` fails the save.
	 *
	 * @return array<int, string> The accepted property keys.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public static function vocabularyKeys(): array {
		return array_values(
			array_unique(
				array_merge(
					['type'],
					array_map('strval', array_keys(self::CONSTRAINTS)),
					array_map('strval', array_keys(self::MODIFIERS)),
					array_map('strval', array_keys(self::PASSTHROUGH))
				)
			)
		);
	}//end vocabularyKeys()

	/**
	 * Refuse a property key the vocabulary does not hold.
	 *
	 * A key starting with `x-` is a vendor extension and passes through: that
	 * is the JSON Schema convention, and it is what lets an app annotate a
	 * property without waiting on a release here. Everything else has to be a
	 * type, a constraint, a modifier or a pass-through keyword.
	 *
	 * @param array $property The property definition to check.
	 * @param string $path The current path in the schema (for error messages).
	 *
	 * @throws PropertyVocabularyException When a key is outside the vocabulary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	private function assertKeysAreInTheVocabulary(array $property, string $path): void {
		$known = self::vocabularyKeys();
		$errors = [];
		foreach (array_keys($property) as $key) {
			$key = (string)$key;
			if (str_starts_with($key, 'x-') === true || is_numeric($key) === true) {
				continue;
			}

			if (in_array($key, $known, true) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'property-vocabulary-unknown-key',
				'key' => $key,
				'path' => $path,
				'message' => "Unknown property key '{$key}' at '$path'. It is not in the property vocabulary.",
			];
		}

		if ($errors === []) {
			return;
		}

		$keys = implode(', ', array_map(static fn (array $error): string => $error['key'], $errors));
		throw new PropertyVocabularyException(
			"Unknown property key(s) '{$keys}' at '$path'. Read /api/schemas/property-vocabulary for the keys this layer accepts.",
			$errors
		);
	}//end assertKeysAreInTheVocabulary()

	/**
	 * Validate a property definition against JSON Schema rules
	 *
	 * @param array $property The property definition to validate
	 * @param string $path The current path in the schema (for error messages)
	 *
	 * @throws Exception If the property definition is invalid
	 *
	 * @return true True if the property is valid
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex JSON Schema property validation with multiple type checks
	 * @SuppressWarnings(PHPMD.StaticAccess)         `fromProperty()` is a named constructor on a
	 *                                              value object; a factory injected here would
	 *                                              answer one question and hold no state.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple validation paths for different property types
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validateProperty(array $property, string $path = ''): bool {
		// Every key on the property has to be one the vocabulary holds. A key
		// nobody defines is a typo, and a typo that passes is a constraint
		// that silently constrains nothing for as long as nobody counts.
		$this->assertKeysAreInTheVocabulary(property: $property, path: $path);

		// A generated identifier is checked where every other property key is.
		// The refusal extends PropertyVocabularyException, so every schema-save
		// path already answers it as a 422 naming the property, and no controller
		// had to learn about this annotation to do it.
		GeneratedIdentifierDeclaration::fromProperty(property: $property, path: $path);

		// If property has oneOf, treat the contents as separate properties and return the result of those checks.
		if (($property['oneOf'] ?? null) !== null) {
			return $this->validateProperties(properties: $property['oneOf'], path: $path . '/oneOf');
		}

		// Type is required at the TOP level, and optional below it.
		//
		// JSON Schema treats a schema with no `type` as "any type", and that is
		// a real thing authors need. procest's CMMN sentry declares
		// `ifPart: {field, operator, value}` where `value` is compared with
		// loose equality against bool/string/int, must be an ARRAY for the
		// in/notIn operators, and numeric for gt/lt. No single type is honest
		// there, so requiring one forced a lie — and refusing the omission
		// failed the whole schema import instead.
		//
		// It stays required at the top level because those properties become
		// COLUMNS: mapColumnTypeToSQL() takes a `string $type` and is handed
		// $column['type'] directly, so a typeless top-level property is a
		// TypeError during table creation rather than a permissive read. Nested
		// properties are stored inside a JSON column and derive nothing.
		//
		// Depth is the discriminator: validateProperties() builds '/name' for a
		// top-level property and appends for every level under it.
		$isTopLevel = (substr_count($path, '/') <= 1);
		if (isset($property['type']) === false) {
			if ($isTopLevel === true) {
				throw new Exception("Property at '$path' must have a 'type' field");
			}

			// Untyped nested schema: nothing further here is type-dependent.
			return true;
		}

		// Validate type. Union types arrive as arrays — render them as JSON in
		// the message instead of letting string interpolation emit a PHP
		// "Array to string conversion" warning.
		if (in_array($property['type'], $this->validTypes) === false) {
			$typeLabel = $property['type'];
			if (is_string($typeLabel) === false) {
				$typeLabel = (string)json_encode($typeLabel);
			}

			throw new PropertyVocabularyException(
				"Invalid type '{$typeLabel}' at '$path'. Must be one of: " . implode(', ', $this->validTypes),
				[
					[
						'code' => 'property-vocabulary-unknown-type',
						'key' => $typeLabel,
						'path' => $path,
						'message' => "Invalid type '{$typeLabel}' at '$path'. Must be one of: " . implode(', ', $this->validTypes),
					],
				]
			);
		}

		// Validate string format if present. An unrecognised format is still
		// rejected: a format this allowlist accepts is one ValidateObject can
		// actually enforce, so the two lists must agree. See `bsn` and `user`
		// in $validStringFormats for what that costs when they drift apart.
		if ($property['type'] === 'string' && (($property['format'] ?? null) !== null)) {
			if (in_array($property['format'], $this->validStringFormats) === false) {
				$formatLabel = $property['format'];
				if (is_string($formatLabel) === false) {
					$formatLabel = (string)json_encode($formatLabel);
				}

				$validFormats = implode(', ', $this->validStringFormats);
				$message = "Invalid string format '{$formatLabel}' at '$path'. Must be one of: $validFormats";
				throw new PropertyVocabularyException(
					$message,
					[
						[
							'code' => 'property-vocabulary-unknown-format',
							'key' => $formatLabel,
							'path' => $path,
							'message' => $message,
						],
					]
				);
			}
		}

		// Validate array items if type is array.
		$hasItems = ($property['items'] ?? null) !== null;
		if ($property['type'] === 'array' && $hasItems === true && isset($property['items']['$ref']) === false) {
			$this->validateProperty(property: $property['items'], path: $path . '/items');
		}

		// Validate nested properties if type is object.
		if ($property['type'] === 'object' && (($property['properties'] ?? null) !== null)) {
			$this->validateProperties(properties: $property['properties'], path: $path . '/properties');
		}

		// Validate minimum/maximum for numeric types.
		if (in_array($property['type'], ['number', 'integer'], true) === true) {
			if (($property['minimum'] ?? null) !== null && is_numeric($property['minimum']) === false) {
				throw new Exception("'minimum' at '$path' must be numeric");
			}

			if (($property['maximum'] ?? null) !== null && is_numeric($property['maximum']) === false) {
				throw new Exception("'maximum' at '$path' must be numeric");
			}

			if (($property['minimum'] ?? null) !== null
				&& ($property['maximum'] ?? null) !== null
				&& ($property['minimum'] > $property['maximum']) === true
			) {
				throw new Exception("'minimum' cannot be greater than 'maximum' at '$path'");
			}
		}

		// Validate file properties if type is file.
		if ($property['type'] === 'file') {
			$this->validateFileProperty(property: $property, path: $path);
		}

		// Validate enum values if present.
		if (($property['enum'] ?? null) !== null) {
			if (is_array($property['enum']) === false || empty($property['enum']) === true) {
				throw new Exception("'enum' at '$path' must be a non-empty array");
			}
		}

		// Validate visible property if present.
		if (($property['visible'] ?? null) !== null && is_bool($property['visible']) === false) {
			throw new Exception("'visible' at '$path' must be a boolean");
		}

		// Validate hideOnCollection property if present.
		if (($property['hideOnCollection'] ?? null) !== null && is_bool($property['hideOnCollection']) === false) {
			throw new Exception("'hideOnCollection' at '$path' must be a boolean");
		}

		// Validate hideOnForm property if present.
		if (($property['hideOnForm'] ?? null) !== null && is_bool($property['hideOnForm']) === false) {
			throw new Exception("'hideOnForm' at '$path' must be a boolean");
		}

		// Validate sourceLanguage modifier (i18n-source-of-truth).
		// Only allowed on translatable properties; rejects on non-translatable.
		if (array_key_exists('sourceLanguage', $property) === true) {
			if (($property['translatable'] ?? false) !== true) {
				$msg = "'sourceLanguage' at '$path' requires translatable: true";
				throw new Exception($msg);
			}

			$sourceLanguage = $property['sourceLanguage'];
			if (is_string($sourceLanguage) === false || $sourceLanguage === '') {
				throw new Exception("'sourceLanguage' at '$path' must be a non-empty string");
			}

			// Basic BCP-47 syntax check: 2-3 lowercase letters, optional
			// region/subtag suffix.
			if (preg_match('/^[a-z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $sourceLanguage) !== 1) {
				throw new Exception(
					"'sourceLanguage' at '$path' is not a valid BCP-47 language tag: '$sourceLanguage'"
				);
			}
		}

		// Validate onDelete property if present.
		if (($property['onDelete'] ?? null) !== null) {
			// OnDelete is only valid on relation properties (those with $ref).
			$hasRef = isset($property['$ref']) === true
				|| (isset($property['items']['$ref']) === true);
			if ($hasRef === false) {
				throw new Exception("'onDelete' at '$path' is only valid on relation properties with '\$ref'");
			}

			$validActions = ['CASCADE', 'RESTRICT', 'SET_NULL', 'SET_DEFAULT', 'NO_ACTION'];
			$upperValue = strtoupper((string)$property['onDelete']);
			if (in_array($upperValue, $validActions, true) === false) {
				$validList = implode(', ', $validActions);
				throw new Exception(
					"Invalid onDelete value '{$property['onDelete']}' at '$path'. Must be one of: {$validList}"
				);
			}
		}

		// Validate the declared search profile if present. An unknown match type
		// is refused here rather than ignored at query time: ignoring it leaves
		// the property matching the way it did before, which looks exactly like
		// the declaration working.
		$this->validateSearchProfile(property: $property, path: $path);

		return true;
	}//end validateProperty()

	/**
	 * Refuse an unknown match type or input control, naming the property.
	 *
	 * @param array  $property The property definition to check.
	 * @param string $path     The current path in the schema, for the message.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @throws Exception When a declared value is outside its vocabulary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	private function validateSearchProfile(array $property, string $path): void {
		$declarations = [
			'matchType' => PropertySearchProfile::MATCH_TYPES,
			'inputControl' => PropertySearchProfile::INPUT_CONTROLS,
		];

		foreach ($declarations as $key => $allowed) {
			$declared = ($property[$key] ?? null);
			if ($declared === null) {
				continue;
			}

			if (is_string($declared) === false
				|| in_array(strtolower(trim($declared)), $allowed, true) === false
			) {
				$rendered = json_encode($declared);
				$allowedList = implode(', ', $allowed);
				throw new Exception(
					"Invalid {$key} {$rendered} at '$path'. Must be one of: {$allowedList}"
				);
			}
		}
	}//end validateSearchProfile()

	/**
	 * Validate an entire properties object
	 *
	 * @param array $properties The properties object to validate
	 * @param string $path The current path in the schema
	 *
	 * @throws Exception If any property definition is invalid
	 *
	 * @return true True if all properties are valid
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validateProperties(array $properties, string $path = ''): bool {
		foreach ($properties as $propertyName => $property) {
			if (is_array($property) === false) {
				throw new Exception("Property '$propertyName' at '$path' must be an object");
			}

			$this->validateProperty(property: $property, path: $path . '/' . $propertyName);
		}

		return true;
	}//end validateProperties()

	/**
	 * Validate file-specific properties
	 *
	 * Validates file property configuration options including allowedTypes,
	 * maxSize, allowedTags, and autoTags
	 *
	 * @param array $property The file property definition to validate
	 * @param string $path The current path in the schema (for error messages)
	 *
	 * @throws Exception If the file property configuration is invalid
	 *
	 * @return true
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @psalm-suppress UnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple file property validations
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple validation paths for file properties
	 */
	private function validateFileProperty(array $property, string $path): bool {
		// Validate allowedTypes if present.
		if (($property['allowedTypes'] ?? null) !== null) {
			if (is_array($property['allowedTypes']) === false) {
				throw new Exception("'allowedTypes' at '$path' must be an array");
			}

			// Validate each MIME type.
			foreach ($property['allowedTypes'] as $index => $mimeType) {
				if (is_string($mimeType) === false) {
					throw new Exception("'allowedTypes[$index]' at '$path' must be a string");
				}

				// Basic MIME type validation (type/subtype).
				if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_.]*$/', $mimeType) === 0) {
					throw new Exception("'allowedTypes[$index]' at '$path' contains invalid MIME type format: '$mimeType'");
				}
			}
		}

		// Validate maxSize if present.
		if (($property['maxSize'] ?? null) !== null) {
			if (is_int($property['maxSize']) === false && is_numeric($property['maxSize']) === false) {
				throw new Exception("'maxSize' at '$path' must be a numeric value");
			}

			$maxSize = (int)$property['maxSize'];
			if ($maxSize < 0) {
				throw new Exception("'maxSize' at '$path' must be a positive number");
			}

			// Reasonable upper limit (100MB).
			if ($maxSize > 104857600) {
				throw new Exception("'maxSize' at '$path' exceeds maximum allowed size (100MB)");
			}
		}

		// Validate allowedTags if present.
		if (($property['allowedTags'] ?? null) !== null) {
			if (is_array($property['allowedTags']) === false) {
				throw new Exception("'allowedTags' at '$path' must be an array");
			}

			foreach ($property['allowedTags'] as $index => $tag) {
				if (is_string($tag) === false) {
					throw new Exception("'allowedTags[$index]' at '$path' must be a string");
				}

				// Basic tag validation (no empty strings, reasonable length).
				if (trim($tag) === '') {
					throw new Exception("'allowedTags[$index]' at '$path' cannot be empty");
				}

				if (strlen($tag) > 50) {
					throw new Exception("'allowedTags[$index]' at '$path' exceeds maximum length (50 characters)");
				}
			}
		}

		// Validate autoTags if present.
		if (($property['autoTags'] ?? null) !== null) {
			if (is_array($property['autoTags']) === false) {
				throw new Exception("'autoTags' at '$path' must be an array");
			}

			foreach ($property['autoTags'] as $index => $tag) {
				if (is_string($tag) === false) {
					throw new Exception("'autoTags[$index]' at '$path' must be a string");
				}

				// Basic tag validation (no empty strings, reasonable length).
				if (trim($tag) === '') {
					throw new Exception("'autoTags[$index]' at '$path' cannot be empty");
				}

				if (strlen($tag) > 50) {
					throw new Exception("'autoTags[$index]' at '$path' exceeds maximum length (50 characters)");
				}
			}
		}

		return true;
	}//end validateFileProperty()
}//end class
