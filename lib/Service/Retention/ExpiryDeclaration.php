<?php

/**
 * ExpiryDeclaration: one reading of a schema's `x-openregister.expiry` block.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Retention;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;

/**
 * What expiry means for a schema's objects: delete them, or anonymise them
 * keeping a named set of properties, optionally decided per object by a
 * property's value.
 *
 * ```json
 * "x-openregister": { "expiry": { "action": "anonymise", "keep": ["reference"], "actionField": "retentionMethod" } }
 * ```
 *
 * A schema without the block has not opted in; `expires` then keeps meaning
 * what it meant before and nothing here touches it.
 */
final class ExpiryDeclaration {

	/**
	 * The schema configuration key that holds the block.
	 */
	public const ANNOTATION = 'x-openregister';

	/**
	 * The key of the expiry block inside the annotation.
	 */
	public const KEY = 'expiry';

	public const ACTION_DELETE = 'delete';

	public const ACTION_ANONYMISE = 'anonymise';

	/**
	 * The actions an expiry block may name.
	 */
	public const ACTIONS = [self::ACTION_DELETE, self::ACTION_ANONYMISE];

	/**
	 * Keys that identify an object rather than answer anything; an anonymisation never empties them.
	 */
	private const IDENTITY_KEYS = ['id', '@self'];

	/**
	 * Build a declaration from a validated block.
	 *
	 * @param string             $action      The default action.
	 * @param array<int, string> $keep        The properties an anonymisation keeps.
	 * @param string|null        $actionField The property whose value overrides the action per object.
	 */
	private function __construct(
		public readonly string $action,
		public readonly array $keep,
		public readonly ?string $actionField,
	) {
	}//end __construct()

	/**
	 * The raw expiry block a schema configuration carries, or null when the schema did not opt in.
	 *
	 * @param array|null $configuration The schema configuration.
	 *
	 * @return mixed The block as written, or null when absent.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public static function rawBlock(?array $configuration): mixed {
		$annotation = ($configuration[self::ANNOTATION] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		return ($annotation[self::KEY] ?? null);
	}//end rawBlock()

	/**
	 * What is wrong with an expiry block, as messages; an empty list when it is valid or absent.
	 *
	 * @param mixed                $block      The block as written (null when absent).
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string> The faults.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public static function faults(mixed $block, array $properties): array {
		if ($block === null) {
			return [];
		}

		if (is_array($block) === false) {
			return ['expiry must be an object with an action.'];
		}

		return array_merge(
			self::actionFaults(action: ($block['action'] ?? null)),
			self::keepFaults(keep: ($block['keep'] ?? []), properties: $properties),
			self::actionFieldFaults(field: ($block['actionField'] ?? null), properties: $properties)
		);
	}//end faults()

	/**
	 * The declaration of a schema, or null when it did not opt in or its block is invalid.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return self|null The declaration.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public static function of(Schema $schema): ?self {
		$configuration = $schema->getConfiguration();
		if (is_array($configuration) === false) {
			return null;
		}

		$block = self::rawBlock(configuration: $configuration);
		if ($block === null || self::faults(block: $block, properties: ($schema->getProperties() ?? [])) !== []) {
			return null;
		}

		$field = ($block['actionField'] ?? null);
		if (is_string($field) === false || $field === '') {
			$field = null;
		}

		return new self(
			action: (string)$block['action'],
			keep: array_values(array_map('strval', ($block['keep'] ?? []))),
			actionField: $field
		);
	}//end of()

	/**
	 * The action for one object: its action field's value when that is a known action, else the default.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return string delete or anonymise.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function actionFor(array $data): string {
		if ($this->actionField === null) {
			return $this->action;
		}

		$value = ($data[$this->actionField] ?? null);
		if (is_string($value) === true && in_array($value, self::ACTIONS, true) === true) {
			return $value;
		}

		return $this->action;
	}//end actionFor()

	/**
	 * The data an anonymisation leaves: kept properties and the object's id as they are, every other property emptied.
	 *
	 * A nested object or list becomes empty, anything else null, so the shape
	 * of the object stays readable while its answers are gone.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return array<string, mixed> The anonymised data.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function anonymised(array $data): array {
		$out = [];
		foreach ($data as $key => $value) {
			if (in_array((string)$key, $this->keep, true) === true || in_array((string)$key, self::IDENTITY_KEYS, true) === true) {
				$out[$key] = $value;
				continue;
			}

			$out[$key] = self::emptied(value: $value);
		}

		return $out;
	}//end anonymised()

	/**
	 * Store `@self.expires` on an object of an opted-in schema; leave other schemas' objects as they are.
	 *
	 * An empty value clears the expiry. A value that is not an ISO 8601 date is refused,
	 * not dropped, so a caller is never told its expiry was saved when it was not.
	 *
	 * @param Schema               $schema   The object's schema.
	 * @param ObjectEntity         $object   The object being saved.
	 * @param array<string, mixed> $selfData The caller's `@self` block.
	 *
	 * @return void
	 *
	 * @throws ValidationException When the expiry is not a date.
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public static function stampRequestedExpiry(Schema $schema, ObjectEntity $object, array $selfData): void {
		if (array_key_exists('expires', $selfData) === false || self::of(schema: $schema) === null) {
			return;
		}

		$requested = $selfData['expires'];
		if ($requested === null || $requested === '') {
			$object->setExpires(null);
			return;
		}

		$parsed = false;
		if (is_string($requested) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($requested)) === 1) {
			$parsed = date_create(trim($requested));
		}

		if ($parsed === false) {
			throw new ValidationException(message: '@self.expires must be a date and time, for example 2026-12-31T00:00:00+00:00.');
		}

		$object->setExpires(DateTime::createFromInterface($parsed));
	}//end stampRequestedExpiry()

	/**
	 * Faults of the action.
	 *
	 * @param mixed $action The action as written.
	 *
	 * @return array<int, string>
	 */
	private static function actionFaults(mixed $action): array {
		if (is_string($action) === true && in_array($action, self::ACTIONS, true) === true) {
			return [];
		}

		return [
			sprintf(
				'expiry.action "%s" is not allowed; use one of: %s.',
				self::describe(value: $action),
				implode(', ', self::ACTIONS)
			),
		];
	}//end actionFaults()

	/**
	 * Faults of the kept-properties list.
	 *
	 * @param mixed                $keep       The list as written.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 */
	private static function keepFaults(mixed $keep, array $properties): array {
		if (is_array($keep) === false || array_is_list($keep) === false) {
			return ['expiry.keep must be a list of property names.'];
		}

		$faults = [];
		foreach ($keep as $name) {
			if (is_string($name) === false || array_key_exists($name, $properties) === false) {
				$faults[] = sprintf('expiry.keep names "%s", which is not a property of this schema.', self::describe(value: $name));
			}
		}

		return $faults;
	}//end keepFaults()

	/**
	 * Faults of the per-object action field.
	 *
	 * @param mixed                $field      The field as written (null when absent).
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string>
	 */
	private static function actionFieldFaults(mixed $field, array $properties): array {
		if ($field === null) {
			return [];
		}

		if (is_string($field) === false || array_key_exists($field, $properties) === false) {
			return [sprintf('expiry.actionField names "%s", which is not a property of this schema.', self::describe(value: $field))];
		}

		return [];
	}//end actionFieldFaults()

	/**
	 * A written value as text for a message: itself when scalar, else its type.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function describe(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end describe()

	/**
	 * The empty value of the same kind.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array|null An empty array for an object or list, else null.
	 */
	private static function emptied(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return [];
	}//end emptied()
}//end class
