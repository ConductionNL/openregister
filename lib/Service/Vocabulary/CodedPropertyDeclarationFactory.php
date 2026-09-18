<?php

/**
 * OpenRegister CodedPropertyDeclarationFactory
 *
 * Builds {@see CodedPropertyDeclaration} value objects from schema property
 * definitions. The parsing used to live as static named-constructors on the
 * value object itself; moving it to an injectable factory removes the static
 * coupling at every call site and keeps CodedPropertyDeclaration a plain,
 * side-effect-free value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * Reads coded-property declarations off schema properties.
 */
class CodedPropertyDeclarationFactory {

	/**
	 * Read a declaration off a schema property, or null when it carries none.
	 *
	 * A declaration without a `scheme` is not a declaration: it is a typo, and
	 * returning null makes the property behave as an ordinary string rather
	 * than as a code list bound to nothing.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return CodedPropertyDeclaration|null The declaration, or null.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function fromProperty(mixed $property): ?CodedPropertyDeclaration {
		$raw = $this->rawAnnotation(property: $property);
		if ($raw === null) {
			return null;
		}

		$scheme = trim((string)($raw['scheme'] ?? ''));
		if ($scheme === '') {
			return null;
		}

		$store = 'uri';
		if (trim((string)($raw['store'] ?? 'uri')) === 'notation') {
			$store = 'notation';
		}

		return new CodedPropertyDeclaration(
			scheme: $scheme,
			store: $store,
			allowDeprecated: (($raw['allowDeprecated'] ?? false) === true),
			branch: $this->nonEmpty(value: ($raw['branch'] ?? null)),
			leafOnly: (($raw['leafOnly'] ?? false) === true),
			maxDepth: $this->parseMaxDepth(raw: $raw),
			contextProperty: $this->nonEmpty(value: ($raw['contextProperty'] ?? null)),
			contextKey: $this->nonEmpty(value: ($raw['contextKey'] ?? null)),
			scoreProperty: $this->parseScoreProperty(raw: $raw)
		);
	}//end fromProperty()

	/**
	 * Every coded declaration on a schema's properties, keyed by property name.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<string,CodedPropertyDeclaration> The declarations.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function fromProperties(array $properties): array {
		$declarations = [];
		foreach ($properties as $name => $property) {
			$declaration = $this->fromProperty(property: $property);
			if ($declaration !== null) {
				$declarations[(string)$name] = $declaration;
			}
		}

		return $declarations;
	}//end fromProperties()

	/**
	 * The `x-openregister-concepts` annotation array off a property, or null.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return array<string,mixed>|null The annotation, or null when absent.
	 */
	private function rawAnnotation(mixed $property): ?array {
		$raw = $this->readKey(property: $property, key: CodedPropertyDeclaration::ANNOTATION);

		if (is_object($raw) === true) {
			$raw = (array)$raw;
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		// THE SIMPLE SPELLING, READ BY THE SAME READER ON PURPOSE.
		// `conceptScheme` is the published vocabulary modifier (openregister
		// #3883): a scheme slug and nothing else, which is what a case-type
		// editor writes and what dossiq forwards. The annotation above is the
		// same binding with the options a hierarchy needs.
		//
		// They are ONE declaration here rather than two readers, because two
		// readers of one capability is how the validator and the option builder
		// end up disagreeing about which field is coded. A property carrying
		// both is refused by {@see self::competingSpellings()} rather than
		// silently resolved, for the same reason.
		$simple = $this->nonEmpty(value: $this->readKey(
			property: $property,
			key: CodedPropertyDeclaration::SIMPLE_ANNOTATION
		));
		if ($simple !== null) {
			return ['scheme' => $simple];
		}

		return null;
	}//end rawAnnotation()

	/**
	 * One key off a property, whether it arrived as an array or an object.
	 *
	 * @param mixed  $property The schema property definition.
	 * @param string $key      The key to read.
	 *
	 * @return mixed The value, or null.
	 */
	private function readKey(mixed $property, string $key): mixed {
		if (is_array($property) === true) {
			return ($property[$key] ?? null);
		}

		if (is_object($property) === true) {
			return ($property->{$key} ?? null);
		}

		return null;
	}//end readKey()

	/**
	 * Whether a property declares its code list in both spellings at once.
	 *
	 * Two spellings on one property is an authoring mistake, and the dangerous
	 * version is the silent one: the validator reads the annotation, the editor
	 * reads the modifier, and nothing says which scheme a value is checked
	 * against. Reported, so it is fixed, rather than resolved by precedence.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return bool True when both are present.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function competingSpellings(mixed $property): bool {
		$annotation = $this->readKey(property: $property, key: CodedPropertyDeclaration::ANNOTATION);
		$simple = $this->readKey(property: $property, key: CodedPropertyDeclaration::SIMPLE_ANNOTATION);

		return (($annotation !== null && $annotation !== [])
			&& $this->nonEmpty(value: $simple) !== null);
	}//end competingSpellings()

	/**
	 * A trimmed non-empty string off a raw value, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed string, or null when empty.
	 */
	private function nonEmpty(mixed $value): ?string {
		$string = trim((string)($value ?? ''));
		if ($string === '') {
			return null;
		}

		return $string;
	}//end nonEmpty()

	/**
	 * The `maxDepth` as an int, or null when absent or non-numeric.
	 *
	 * @param array<string,mixed> $raw The annotation.
	 *
	 * @return integer|null The depth.
	 */
	private function parseMaxDepth(array $raw): ?int {
		if (isset($raw['maxDepth']) === true && is_numeric($raw['maxDepth']) === true) {
			return (int)$raw['maxDepth'];
		}

		return null;
	}//end parseMaxDepth()

	/**
	 * The score property name off the `score` block, or null.
	 *
	 * The block may be an object, an array or a bare string; each yields the
	 * property a rolled-up score is written to, or null when it names none.
	 *
	 * @param array<string,mixed> $raw The annotation.
	 *
	 * @return string|null The score property.
	 */
	private function parseScoreProperty(array $raw): ?string {
		$score = ($raw['score'] ?? null);
		if (is_object($score) === true) {
			$score = (array)$score;
		}

		if (is_array($score) === true) {
			return $this->nonEmpty(value: ($score['property'] ?? null));
		}

		if (is_string($score) === true) {
			return $this->nonEmpty(value: $score);
		}

		return null;
	}//end parseScoreProperty()
}//end class
