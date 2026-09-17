<?php

/**
 * OpenRegister PropertyVocabulary
 *
 * Publishes the property vocabulary: every type the layer accepts, the
 * constraint keys that type takes, the formats it supports, what happens to
 * populated objects when you convert to it, and a sentence a form can put
 * beside it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The published property vocabulary.
 *
 * One source of truth. The descriptor tables live on
 * {@see PropertyValidatorHandler}, directly beside the checks that read them,
 * so a type added to the validator is published in the same edit and cannot
 * be forgotten in a second file. `PropertyVocabularyTest` compares the
 * published list against the validator's own behaviour and fails when the two
 * drift apart, which is what makes "generated from the validator's own list" a
 * claim a build can refute.
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */
final class PropertyVocabulary {

	/**
	 * Every type, in a shape a property editor can render.
	 *
	 * @return array<int, array{type: string, category: string, constraints: array<int, string>,
	 *   formats: array<int, string>, conversion: string, conversionNote: string, description: string}> The type rows.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function types(): array {
		$rows = [];
		foreach (PropertyValidatorHandler::TYPES as $type => $descriptor) {
			$name = (string)$type;
			$rows[] = [
				'type' => $name,
				'category' => $descriptor['category'],
				'constraints' => $this->constraintsFor(type: $name),
				'formats' => $this->formatsFor(type: $name),
				'conversion' => $descriptor['conversion'],
				'conversionNote' => $descriptor['conversionNote'],
				'description' => $descriptor['description'],
			];
		}

		return $rows;
	}//end types()

	/**
	 * The type names alone.
	 *
	 * @return array<int, string> Type names in declaration order.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function typeNames(): array {
		return array_map('strval', array_keys(PropertyValidatorHandler::TYPES));
	}//end typeNames()

	/**
	 * Whether the vocabulary holds a type.
	 *
	 * @param string $type The type name to look up.
	 *
	 * @return bool True when the validator accepts this type.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function hasType(string $type): bool {
		return array_key_exists($type, PropertyValidatorHandler::TYPES);
	}//end hasType()

	/**
	 * The constraint keys a given type takes.
	 *
	 * @param string $type The type name.
	 *
	 * @return array<int, string> The constraint keys, in declaration order.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function constraintsFor(string $type): array {
		$keys = [];
		foreach (PropertyValidatorHandler::CONSTRAINTS as $key => $descriptor) {
			$appliesToEveryType = in_array('*', $descriptor['appliesTo'], true);
			if ($appliesToEveryType === true || in_array($type, $descriptor['appliesTo'], true) === true) {
				$keys[] = (string)$key;
			}
		}

		return $keys;
	}//end constraintsFor()

	/**
	 * The string formats a given type supports.
	 *
	 * Only `string` carries formats today: the save path checks `format`
	 * against the allowlist for strings and ignores it elsewhere. Publishing
	 * that as an empty list per type is the honest shape, because a form that
	 * offers a format picker on a boolean is offering something nothing reads.
	 *
	 * @param string $type The type name.
	 *
	 * @return array<int, string> The formats, empty for every type but string.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function formatsFor(string $type): array {
		if ($type !== 'string') {
			return [];
		}

		return array_values(array_filter(PropertyValidatorHandler::STRING_FORMATS, static fn (string $format): bool => $format !== ''));
	}//end formatsFor()

	/**
	 * Every constraint key, with the types it applies to.
	 *
	 * @return array<int, array{key: string, appliesTo: array<int, string>, value: string,
	 *   enforced: bool, breaking: bool, description: string}> The constraint rows.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function constraints(): array {
		$rows = [];
		foreach (PropertyValidatorHandler::CONSTRAINTS as $key => $descriptor) {
			$rows[] = [
				'key' => (string)$key,
				'appliesTo' => $descriptor['appliesTo'],
				'value' => $descriptor['value'],
				'enforced' => true,
				'breaking' => $descriptor['breaking'],
				'description' => $descriptor['description'],
			];
		}

		return $rows;
	}//end constraints()

	/**
	 * Every modifier key, valid on any type.
	 *
	 * @return array<int, array{key: string, value: string, enforced: bool, description: string}> The modifier rows.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function modifiers(): array {
		$rows = [];
		foreach (PropertyValidatorHandler::MODIFIERS as $key => $descriptor) {
			$rows[] = [
				'key' => (string)$key,
				'value' => $descriptor['value'],
				'enforced' => true,
				'description' => $descriptor['description'],
			];
		}

		return $rows;
	}//end modifiers()

	/**
	 * The keys the layer stores and hands on without checking them.
	 *
	 * @return array<int, array{key: string, enforced: bool, description: string}> The pass-through rows.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function passthrough(): array {
		$rows = [];
		foreach (PropertyValidatorHandler::PASSTHROUGH as $key => $description) {
			$rows[] = [
				'key' => (string)$key,
				'enforced' => false,
				'description' => $description,
			];
		}

		return $rows;
	}//end passthrough()

	/**
	 * Every key the vocabulary holds, in one flat list.
	 *
	 * This is the list an extending form declares against, and the list the
	 * save path refuses an unknown key with.
	 *
	 * @return array<int, string> The accepted property keys.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The validator owns the four tables and merges them
	 *                                       once. Re-merging them here would be the second list
	 *                                       this whole change exists to remove.
	 */
	public function keys(): array {
		return PropertyValidatorHandler::vocabularyKeys();
	}//end keys()

	/**
	 * Whether the vocabulary holds a key.
	 *
	 * @param string $key The property key to look up.
	 *
	 * @return bool True when a property may carry this key.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The validator owns the localisation rule because it is
	 *                                       the thing that enforces it. Spelling the suffix check
	 *                                       out again here would be the second list this class
	 *                                       exists to remove.
	 */
	public function hasKey(string $key): bool {
		if (in_array($key, $this->keys(), true) === true) {
			return true;
		}

		return PropertyValidatorHandler::isLocalisedKey(key: $key);
	}//end hasKey()

	/**
	 * The keys that take a language suffix, and the shape the suffix has.
	 *
	 * A generated editor needs both halves: which keys may be written per
	 * language, and what it may put after the colon. Publishing only the base
	 * keys would leave every app guessing the tag format, and guessing is how
	 * `title:english` gets written and then refused at import. It is reached
	 * through {@see self::all()} rather than on its own, because the rule is
	 * part of the published payload and not a second question.
	 *
	 * @return array{keys: array<int, string>, separator: string, languageTagPattern: string,
	 *   example: string, description: string} The localisation rule.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	private function localisation(): array {
		return [
			'keys' => PropertyValidatorHandler::LOCALISED_KEYS,
			'separator' => ':',
			'languageTagPattern' => PropertyValidatorHandler::LANGUAGE_TAG_PATTERN,
			'example' => 'title:nl',
			'description' => 'The prose keys may be written once per language, as `<key>:<language tag>`.',
		];
	}//end localisation()

	/**
	 * The categories the vocabulary groups its types under.
	 *
	 * @return array<int, string> Unique category names in declaration order.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function categories(): array {
		$categories = [];
		foreach (PropertyValidatorHandler::TYPES as $descriptor) {
			if (in_array($descriptor['category'], $categories, true) === false) {
				$categories[] = $descriptor['category'];
			}
		}

		return $categories;
	}//end categories()

	/**
	 * The whole vocabulary, in the shape the endpoint answers with.
	 *
	 * @return array<string, mixed> Types, constraints, modifiers, pass-through keys and the counts.
	 *
	 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
	 */
	public function all(): array {
		$types = $this->types();
		$constraints = $this->constraints();
		$modifiers = $this->modifiers();
		$passthrough = $this->passthrough();

		return [
			'types' => $types,
			'categories' => $this->categories(),
			'constraints' => $constraints,
			'modifiers' => $modifiers,
			'passthrough' => $passthrough,
			'keys' => $this->keys(),
			'localisation' => $this->localisation(),
			'vendorExtensionPrefix' => 'x-',
			'counts' => [
				'types' => count($types),
				'constraints' => count($constraints),
				'modifiers' => count($modifiers),
				'passthrough' => count($passthrough),
				'formats' => count($this->formatsFor(type: 'string')),
				'keys' => count($this->keys()),
			],
		];
	}//end all()
}//end class
