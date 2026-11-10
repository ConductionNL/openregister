<?php

/**
 * SchemaDiffRun: the state of one SchemaDiffService::diff() call.
 *
 * Holds the normalised properties and required lists of both definitions,
 * the declared renames, and the change list with its classification flags
 * as the diff steps add to it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

/**
 * Per-call diff state: both definitions, declared renames and the changes found.
 */
final class SchemaDiffRun {

	/**
	 * Properties map of the old definition.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $oldProps;

	/**
	 * Properties map of the new definition.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $newProps;

	/**
	 * Required property names of the old definition.
	 *
	 * @var array<int, string>
	 */
	public readonly array $oldReq;

	/**
	 * Required property names of the new definition.
	 *
	 * @var array<int, string>
	 */
	public readonly array $newReq;

	/**
	 * Old names of every declared rename, matched or not.
	 *
	 * @var array<int, string>
	 */
	private array $renamedFrom = [];

	/**
	 * New names of every declared rename, matched or not.
	 *
	 * @var array<int, string>
	 */
	private array $renamedTo = [];

	/**
	 * Changes found so far, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $changes = [];

	/**
	 * Whether a breaking change was found.
	 *
	 * @var boolean
	 */
	private bool $hasBreaking = false;

	/**
	 * Whether an optional property was added.
	 *
	 * @var boolean
	 */
	private bool $hasAddedProp = false;

	/**
	 * Normalise both definitions.
	 *
	 * @param array<string, mixed> $old Previous definition.
	 * @param array<string, mixed> $new New definition.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function __construct(array $old, array $new) {
		$this->oldProps = self::properties(definition: $old);
		$this->newProps = self::properties(definition: $new);
		$this->oldReq   = self::requiredSet(definition: $old);
		$this->newReq   = self::requiredSet(definition: $new);
	}//end __construct()

	/**
	 * Register the declared renames and record each one that matches both definitions.
	 *
	 * Every declared old and new name is excluded from the removed and added
	 * steps, also when the rename does not match the definitions.
	 *
	 * @param array<string, string> $renames Declared renames (old name => new name).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function declareRenames(array $renames): void {
		$this->renamedFrom = array_keys($renames);
		$this->renamedTo   = array_values($renames);

		foreach ($renames as $from => $to) {
			if (array_key_exists($from, $this->oldProps) === false || array_key_exists($to, $this->newProps) === false) {
				continue;
			}

			$this->breaking(change: ['property' => $from, 'kind' => 'renamed', 'old' => $from, 'new' => $to]);
		}
	}//end declareRenames()

	/**
	 * Whether a name is the old side of a declared rename.
	 *
	 * @param int|string $name Property name (a numeric name is an int array key).
	 *
	 * @return bool True when declared as renamed away.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function isRenamedFrom(int|string $name): bool {
		return in_array($name, $this->renamedFrom, true);
	}//end isRenamedFrom()

	/**
	 * Whether a name is the new side of a declared rename.
	 *
	 * @param int|string $name Property name (a numeric name is an int array key).
	 *
	 * @return bool True when declared as a rename target.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function isRenamedTo(int|string $name): bool {
		return in_array($name, $this->renamedTo, true);
	}//end isRenamedTo()

	/**
	 * Whether the new definition requires a property without giving it a default.
	 *
	 * @param int|string $name Property name (a numeric name is an int array key).
	 *
	 * @return bool True when required in the new definition and without a default.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function requiredWithoutDefault(int|string $name): bool {
		if (in_array($name, $this->newReq, true) === false) {
			return false;
		}

		return array_key_exists('default', (array)($this->newProps[$name] ?? [])) === false;
	}//end requiredWithoutDefault()

	/**
	 * Record a breaking change.
	 *
	 * @param array<string, mixed> $change The change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function breaking(array $change): void {
		$this->changes[]   = $change;
		$this->hasBreaking = true;
	}//end breaking()

	/**
	 * Record an added optional property.
	 *
	 * @param array<string, mixed> $change The change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function addition(array $change): void {
		$this->changes[]    = $change;
		$this->hasAddedProp = true;
	}//end addition()

	/**
	 * Record a change that does not affect the classification by itself.
	 *
	 * @param array<string, mixed> $change The change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function compatible(array $change): void {
		$this->changes[] = $change;
	}//end compatible()

	/**
	 * Classify the recorded changes.
	 *
	 * Breaking → major, added optional property → minor, other compatible
	 * change → patch, no change → none.
	 *
	 * @return SchemaChangeSet The classified change set.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function changeSet(): SchemaChangeSet {
		if (count($this->changes) === 0) {
			return new SchemaChangeSet([], SchemaChangeSet::CLASS_NONE, 'none');
		}

		if ($this->hasBreaking === true) {
			return new SchemaChangeSet($this->changes, SchemaChangeSet::CLASS_BREAKING, 'major');
		}

		if ($this->hasAddedProp === true) {
			return new SchemaChangeSet($this->changes, SchemaChangeSet::CLASS_COMPATIBLE, 'minor');
		}

		return new SchemaChangeSet($this->changes, SchemaChangeSet::CLASS_COMPATIBLE, 'patch');
	}//end changeSet()

	/**
	 * Extract the properties map from a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed> The properties map.
	 */
	private static function properties(array $definition): array {
		$props = ($definition['properties'] ?? []);
		if (is_array($props) === false) {
			return [];
		}

		return $props;
	}//end properties()

	/**
	 * Extract the required-property name set from a definition.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<int, string> The required property names.
	 */
	private static function requiredSet(array $definition): array {
		$required = ($definition['required'] ?? []);
		if (is_array($required) === false) {
			return [];
		}

		return array_values(array_filter($required, 'is_string'));
	}//end requiredSet()
}//end class
