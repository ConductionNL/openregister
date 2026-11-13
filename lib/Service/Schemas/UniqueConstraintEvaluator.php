<?php

/**
 * OpenRegister UniqueConstraintEvaluator
 *
 * A schema declares uniqueness over a NAMED combination of properties, and
 * says what a breach does: refuse the save, or let it through and record it
 * (design.md D-7).
 *
 * Both actions are real, and that is the whole reason the action is declared
 * rather than assumed. A gemeente wants one bezwaar per besluit per indiener
 * REFUSED, because a second one is a mistake. It wants two contacts with the
 * same e-mail REPORTED, because a second one is usually a duplicate and
 * occasionally a household, and blocking the intake over it costs more than
 * the duplicate does.
 *
 * The existing `configuration.unique` key keeps working exactly as it did: one
 * combination, refuse on breach, no name. It is read here as an unnamed
 * `refuse` constraint so there is one evaluator rather than two that drift.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Reads and evaluates a schema's uniqueness constraints.
 */
class UniqueConstraintEvaluator implements UniqueConstraintEvaluatorInterface {

	/**
	 * The schema configuration key holding the named constraints.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'uniqueConstraints';

	/**
	 * The pre-existing single-combination key, read as an unnamed refusal.
	 *
	 * @var string
	 */
	public const LEGACY_CONFIG_KEY = 'unique';

	/**
	 * Let the save through and record the breach.
	 *
	 * @var string
	 */
	public const ACTION_REPORT = 'report';

	/**
	 * What joins an identity's values into an object's `@self.key`.
	 *
	 * @var string
	 */
	public const KEY_SEPARATOR = ':';

	/**
	 * The constraints a schema configuration declares.
	 *
	 * Each is `{name, properties, action}`. A constraint naming no property,
	 * or an action that is neither refuse nor report, is dropped rather than
	 * guessed at: a malformed constraint that quietly became a `report` would
	 * be a refusal someone believes they configured and did not get.
	 *
	 * The pre-existing `unique` key is read only when `$includeLegacy` asks
	 * for it. The write path that already enforces it keeps doing so alone, so
	 * adding this evaluator does not give one mistake two different messages.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 * @param boolean $includeLegacy Whether to also read the pre-existing `unique` key.
	 *
	 * @return array<int,array{name:string,properties:array<int,string>,action:string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function constraints(?array $configuration, bool $includeLegacy = false): array {
		if ($configuration === null) {
			return [];
		}

		$legacy = [];
		if ($includeLegacy === true) {
			$legacy = $this->legacyConstraints(value: ($configuration[self::LEGACY_CONFIG_KEY] ?? null));
		}

		return array_merge($legacy, $this->declaredConstraints(declared: ($configuration[self::CONFIG_KEY] ?? null)));
	}//end constraints()

	/**
	 * The schema's identity: the declared `refuse` constraint that says `identity: true`.
	 *
	 * Only a well-formed `refuse` constraint can be the identity, because a
	 * `report` constraint lets duplicates through and a key that can match two
	 * records is not a key. When the declaration is refused at save (two
	 * identities, or identity on `report`) this reads the first valid one, so a
	 * stored schema never answers with an identity it does not enforce.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 *
	 * @return array{name:string,properties:array<int,string>,action:string}|null The identity, or null when none is declared.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function identity(?array $configuration): ?array {
		$declared = ($configuration[self::CONFIG_KEY] ?? null);
		if (is_array($declared) === false) {
			return null;
		}

		foreach ($declared as $key => $entry) {
			$constraint = $this->declaredConstraint(key: $key, entry: $entry);
			if ($constraint !== null
				&& $constraint['action'] === self::ACTION_REFUSE
				&& $this->declaresIdentity(entry: $entry) === true
			) {
				return $constraint;
			}
		}

		return null;
	}//end identity()

	/**
	 * Why a schema's identity declaration must be refused at save, if it must.
	 *
	 * An identity on a `report` constraint, and a second identity on one schema,
	 * are each refused with the name of the constraint that breaks the rule.
	 *
	 * @param array<string,mixed>|null $configuration The schema's configuration block.
	 *
	 * @return array<int,array{code:string,message:string}> The refusals, empty when the declaration is sound.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function identityRefusals(?array $configuration): array {
		$declared = ($configuration[self::CONFIG_KEY] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$refusals = [];
		$identity = null;
		foreach ($declared as $key => $entry) {
			$constraint = $this->declaredConstraint(key: $key, entry: $entry);
			if ($constraint === null || $this->declaresIdentity(entry: $entry) === false) {
				continue;
			}

			$refusal = $this->identityRefusal(constraint: $constraint, identity: $identity);
			if ($refusal !== null) {
				$refusals[] = $refusal;
				continue;
			}

			$identity = $constraint['name'];
		}

		return $refusals;
	}//end identityRefusals()

	/**
	 * The refusal of one constraint that declares itself the identity, or null when it may.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param string|null $identity The name of the identity accepted so far, if any.
	 *
	 * @return array{code:string,message:string}|null The refusal.
	 */
	private function identityRefusal(array $constraint, ?string $identity): ?array {
		if ($constraint['action'] !== self::ACTION_REFUSE) {
			return [
				'code' => 'identity-on-report',
				'message' => sprintf(
					'The constraint "%s" cannot be the identity: its action is "%s", and only a "refuse" constraint keeps a key unique.',
					$constraint['name'],
					$constraint['action']
				),
			];
		}

		if ($identity !== null) {
			return [
				'code' => 'identity-declared-twice',
				'message' => sprintf(
					'The constraint "%s" cannot be the identity: "%s" already is, and a schema has at most one.',
					$constraint['name'],
					$identity
				),
			];
		}

		return null;
	}//end identityRefusal()

	/**
	 * Whether a declared entry says `identity: true`.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return bool True only for a literal boolean true.
	 */
	private function declaresIdentity(mixed $entry): bool {
		return is_array($entry) === true && ($entry['identity'] ?? false) === true;
	}//end declaresIdentity()

	/**
	 * The key of an object under an identity: its values joined in declared order.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $identity The identity constraint.
	 * @param array<string,mixed> $object The object data.
	 *
	 * @return string|null The key, or null when one of the values is absent, empty or not scalar.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function keyOf(array $identity, array $object): ?string {
		$values = $this->filledValues(properties: $identity['properties'], object: $object);
		if ($values === null) {
			return null;
		}

		$parts = [];
		foreach ($values as $value) {
			if (is_scalar($value) === false) {
				return null;
			}

			$parts[] = (string)$value;
		}

		return implode(self::KEY_SEPARATOR, $parts);
	}//end keyOf()

	/**
	 * The declared constraints that are well formed, in declaration order.
	 *
	 * @param mixed $declared The value of the `uniqueConstraints` key.
	 *
	 * @return array<int,array{name:string,properties:array<int,string>,action:string}> The constraints.
	 */
	private function declaredConstraints(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$constraints = [];
		foreach ($declared as $key => $entry) {
			$constraint = $this->declaredConstraint(key: $key, entry: $entry);
			if ($constraint !== null) {
				$constraints[] = $constraint;
			}
		}

		return $constraints;
	}//end declaredConstraints()

	/**
	 * The pre-existing `unique` key read as an unnamed refusal: one constraint, or none.
	 *
	 * @param mixed $value The value of the legacy `unique` key.
	 *
	 * @return array<int,array{name:string,properties:array<int,string>,action:string}> The constraint, or nothing when none is declared.
	 */
	private function legacyConstraints(mixed $value): array {
		$properties = $this->propertyList(value: $value);
		if ($properties === []) {
			return [];
		}

		return [
			[
				'name' => implode('+', $properties),
				'properties' => $properties,
				'action' => self::ACTION_REFUSE,
			],
		];
	}//end legacyConstraints()

	/**
	 * One declared constraint entry, normalised, or null when it is malformed.
	 *
	 * A constraint naming no property, or an action that is neither refuse nor
	 * report, is dropped rather than guessed at.
	 *
	 * @param int|string $key   The declaration key, used as a name fallback.
	 * @param mixed      $entry The declared entry.
	 *
	 * @return array{name:string,properties:array<int,string>,action:string}|null The constraint, or null when malformed.
	 */
	private function declaredConstraint(int|string $key, mixed $entry): ?array {
		if ($this->isWellFormed(entry: $entry) === false) {
			return null;
		}

		return [
			'name' => $this->constraintName(entry: $entry, key: $key),
			'properties' => $this->propertyList(value: ($entry['properties'] ?? null)),
			'action' => $this->actionOf(entry: $entry),
		];
	}//end declaredConstraint()

	/**
	 * Whether a declared entry names at least one property and a known action.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return bool True when the entry can become a constraint.
	 */
	private function isWellFormed(mixed $entry): bool {
		return is_array($entry) === true
			&& $this->propertyList(value: ($entry['properties'] ?? null)) !== []
			&& in_array($this->actionOf(entry: $entry), [self::ACTION_REFUSE, self::ACTION_REPORT], true) === true;
	}//end isWellFormed()

	/**
	 * The action an entry declares, lower-cased and trimmed; refuse when it declares none.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 *
	 * @return string The action, which may still be an unknown one.
	 */
	private function actionOf(array $entry): string {
		return strtolower(trim((string)($entry['action'] ?? self::ACTION_REFUSE)));
	}//end actionOf()

	/**
	 * The name a declared constraint carries: its own, else its key, else the
	 * property combination.
	 *
	 * @param array<string,mixed> $entry The declared entry.
	 * @param int|string          $key   The declaration key.
	 *
	 * @return string The resolved name.
	 */
	private function constraintName(array $entry, int|string $key): string {
		$name = trim((string)($entry['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		if (is_string($key) === true) {
			return $key;
		}

		return implode('+', $this->propertyList(value: ($entry['properties'] ?? null)));
	}//end constraintName()

	/**
	 * The filters that find the objects breaching one constraint.
	 *
	 * A constraint over a property the object does not carry cannot be
	 * breached: the combination is not complete, so there is nothing to
	 * compare. Returning null says exactly that, rather than returning a
	 * filter on null which would match every object that also omits it.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param array<string,mixed> $object The submitted object data.
	 *
	 * @return array<string,mixed>|null The filters, or null when the constraint does not apply.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function filtersFor(array $constraint, array $object): ?array {
		if ($constraint['properties'] === []) {
			return null;
		}

		return $this->filledValues(properties: $constraint['properties'], object: $object);
	}//end filtersFor()

	/**
	 * The object's value for each property, or null as soon as one is absent, null or empty.
	 *
	 * @param array<int,string>   $properties The property names.
	 * @param array<string,mixed> $object     The submitted object data.
	 *
	 * @return array<string,mixed>|null The values by property, or null when the combination is incomplete.
	 */
	private function filledValues(array $properties, array $object): ?array {
		$filters = [];
		foreach ($properties as $property) {
			$value = ($object[$property] ?? null);
			if ($value === null || $value === '') {
				return null;
			}

			$filters[$property] = $value;
		}

		return $filters;
	}//end filledValues()

	/**
	 * The breach, in words, naming the combination and the conflicting object.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $constraint The constraint.
	 * @param UniqueConflict $conflict The submitted object data and who holds the combination already.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function describeBreach(array $constraint, UniqueConflict $conflict): string {
		$message = sprintf(
			'The combination %s (%s) is already in use.',
			implode(' and ', $constraint['properties']),
			$this->pairsOf(properties: $constraint['properties'], object: $conflict->object)
		);

		$conflictingId = $conflict->conflictingId;
		if ($conflictingId !== null && $conflictingId !== '') {
			$message .= ' It is held by object ' . $conflictingId . '.';
		}

		return $message;
	}//end describeBreach()

	/**
	 * The `property=value` pairs of a combination, comma separated.
	 *
	 * @param array<int,string>   $properties The property names.
	 * @param array<string,mixed> $object     The submitted object data.
	 *
	 * @return string The rendered pairs.
	 */
	private function pairsOf(array $properties, array $object): string {
		$pairs = [];
		foreach ($properties as $property) {
			$pairs[] = $property . '=' . $this->readable(value: ($object[$property] ?? null));
		}

		return implode(', ', $pairs);
	}//end pairsOf()

	/**
	 * Normalise a declared property list.
	 *
	 * @param mixed $value A property name, or a list of them.
	 *
	 * @return array<int,string> The property names.
	 */
	private function propertyList(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$named = array_filter($value, static fn (mixed $entry): bool => is_string($entry) === true && trim($entry) !== '');
		return array_values(array_map('trim', $named));
	}//end propertyList()

	/**
	 * A value rendered for a message.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The rendering.
	 */
	private function readable(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if ($value === null) {
			return 'null';
		}

		return (string)json_encode($value);
	}//end readable()
}//end class
