<?php

/**
 * OpenRegister CodedPropertyDeclaration
 *
 * One reader for `x-openregister-concepts`, so the validator, the option
 * builder, the branch filter and the schema editor all agree on what a
 * declaration says.
 *
 * The base keys (`scheme`, `store`, `allowDeprecated`) belong to
 * `property-code-list-from-concept-scheme` and are read here unchanged. This
 * change adds the four that turn a flat list into a usable one: a branch, a
 * leaf rule, a context binding and a rolled-up score (design.md D-3, D-4).
 *
 * A declaration is a value object rather than an array so a typo in a key
 * name fails at the reader rather than three layers down as a silently
 * missing narrowing, which is the exact failure mode this cluster exists to
 * remove.
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
 * A property's declared binding to a concept scheme.
 */
class CodedPropertyDeclaration {

	/**
	 * The schema-property annotation this class reads.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-concepts';

	/**
	 * Constructor.
	 *
	 * @param string $scheme The concept scheme's uri.
	 * @param string $store Whether values are stored as `uri` or as `notation`.
	 * @param boolean $allowDeprecated Whether a deprecated concept may still be written.
	 * @param string|null $branch The branch root's uri, when the property is bound to one.
	 * @param boolean $leafOnly Whether only leaf concepts may be written.
	 * @param integer|null $maxDepth How deep the branch walk goes.
	 * @param string|null $contextProperty The property whose value narrows the option set.
	 * @param string|null $contextKey The declared context key that narrows the option set.
	 * @param string|null $scoreProperty The property a rolled-up score is written to.
	 */
	public function __construct(
		public readonly string $scheme,
		public readonly string $store = 'uri',
		public readonly bool $allowDeprecated = false,
		public readonly ?string $branch = null,
		public readonly bool $leafOnly = false,
		public readonly ?int $maxDepth = null,
		public readonly ?string $contextProperty = null,
		public readonly ?string $contextKey = null,
		public readonly ?string $scoreProperty = null,
	) {

	}//end __construct()

	/**
	 * Read a declaration off a schema property, or null when it carries none.
	 *
	 * A declaration without a `scheme` is not a declaration: it is a typo, and
	 * returning null makes the property behave as an ordinary string rather
	 * than as a code list bound to nothing.
	 *
	 * @param mixed $property The schema property definition.
	 *
	 * @return self|null The declaration, or null.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public static function fromProperty(mixed $property): ?self {
		$raw = null;
		if (is_array($property) === true) {
			$raw = ($property[self::ANNOTATION] ?? null);
		} elseif (is_object($property) === true) {
			$raw = ($property->{self::ANNOTATION} ?? null);
		}

		if (is_object($raw) === true) {
			$raw = (array)$raw;
		}

		if (is_array($raw) === false) {
			return null;
		}

		$scheme = trim((string)($raw['scheme'] ?? ''));
		if ($scheme === '') {
			return null;
		}

		$store = trim((string)($raw['store'] ?? 'uri'));
		if ($store !== 'notation') {
			$store = 'uri';
		}

		$branch = trim((string)($raw['branch'] ?? ''));
		$contextProperty = trim((string)($raw['contextProperty'] ?? ''));
		$contextKey = trim((string)($raw['contextKey'] ?? ''));

		$score = ($raw['score'] ?? null);
		if (is_object($score) === true) {
			$score = (array)$score;
		}

		$scoreProperty = '';
		if (is_array($score) === true) {
			$scoreProperty = trim((string)($score['property'] ?? ''));
		} elseif (is_string($score) === true) {
			$scoreProperty = trim($score);
		}

		$maxDepth = null;
		if (isset($raw['maxDepth']) === true && is_numeric($raw['maxDepth']) === true) {
			$maxDepth = (int)$raw['maxDepth'];
		}

		// An absent part reads as an empty string above and as null on the
		// declaration, so the four optional parts are normalised once here
		// rather than each carrying its own check at the call.
		if ($branch === '') {
			$branch = null;
		}

		if ($contextProperty === '') {
			$contextProperty = null;
		}

		if ($contextKey === '') {
			$contextKey = null;
		}

		if ($scoreProperty === '') {
			$scoreProperty = null;
		}

		return new self(
			scheme: $scheme,
			store: $store,
			allowDeprecated: (($raw['allowDeprecated'] ?? false) === true),
			branch: $branch,
			leafOnly: (($raw['leafOnly'] ?? false) === true),
			maxDepth: $maxDepth,
			contextProperty: $contextProperty,
			contextKey: $contextKey,
			scoreProperty: $scoreProperty
		);
	}//end fromProperty()

	/**
	 * Every coded declaration on a schema's properties, keyed by property name.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<string,self> The declarations.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public static function fromProperties(array $properties): array {
		$declarations = [];
		foreach ($properties as $name => $property) {
			$declaration = self::fromProperty(property: $property);
			if ($declaration !== null) {
				$declarations[(string)$name] = $declaration;
			}
		}

		return $declarations;
	}//end fromProperties()

	/**
	 * Whether the option set is narrowed by something outside the scheme.
	 *
	 * @return boolean True when a context property or key is declared.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function isContextBound(): bool {
		return ($this->contextProperty !== null || $this->contextKey !== null);
	}//end isContextBound()

	/**
	 * Whether a concept belongs to this declaration's context subset.
	 *
	 * A concept declares the contexts it serves in its own `contexts` list. A
	 * concept that declares none serves every context, which is what keeps a
	 * scheme written before this change working under a context-bound property
	 * instead of emptying its picker.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param string|null $context The context value in play.
	 *
	 * @return boolean True when the concept is in the subset.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function matchesContext(array $concept, ?string $context): bool {
		if ($this->isContextBound() === false || $context === null || $context === '') {
			return true;
		}

		$declared = ($concept['contexts'] ?? null);
		if (is_string($declared) === true) {
			$declared = [$declared];
		}

		if (is_array($declared) === false || $declared === []) {
			return true;
		}

		foreach ($declared as $candidate) {
			if (is_string($candidate) === true && $candidate === $context) {
				return true;
			}
		}

		return false;
	}//end matchesContext()
}//end class
