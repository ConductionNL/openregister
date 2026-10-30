<?php

/**
 * A property that is shown masked until the reader asks to see it.
 *
 * A property whose authorization block says `"reveal": "explicit"` (a BSN) is
 * rendered as its mask, "*****7609", on every read path, for administrators
 * too: the render boundary, GraphQL, access links, exports, history and MCP.
 * The value is shown only to a single-object read that names the property in
 * `_reveal` ({@see grantFor()}, set by ObjectsController::show), and only after
 * the property's read rule; that reveal is recorded through the RevealCollector.
 *
 * Masking stands down for a system operation (SystemOperationContext) and for
 * server code that asks for the value on purpose ({@see trustedDuring()}), the
 * same two doors the rest of the render boundary leaves open to trusted reads.
 *
 * Because the value never leaves masked, it must not be learnable another way:
 * filtering and sorting on it are refused ({@see refuseOracleQuery()}), and it
 * takes no part in free-text search or facets.
 *
 * Writing back what a reader was shown must not overwrite the value with its
 * mask: {@see restoreMasked()} puts the stored value back.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ExplicitRevealQueryException;
use OCA\OpenRegister\Service\SystemOperationContext;

/**
 * The request's reveal grants, and the pure rules of explicit reveal.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::isActive() is the process-wide system flag.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitReveal {

	/**
	 * The authorization key and value that make a property explicit-reveal.
	 */
	public const KEY = 'reveal';

	public const EXPLICIT = 'explicit';

	/**
	 * How many characters of the value the mask keeps.
	 */
	private const VISIBLE = 4;

	/**
	 * The properties a read may see, per object id.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $grants = [];

	/**
	 * How deep the current trusted call nests.
	 *
	 * @var int
	 */
	private int $trusted = 0;

	/**
	 * Let the read of one object see the named properties.
	 *
	 * @param string $objectId The object's id (uuid, or the id the route named).
	 * @param string $reveal   Property names, comma separated (the `_reveal` parameter).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function grantFor(string $objectId, string $reveal): void {
		$objectId = trim($objectId);
		if ($objectId === '') {
			return;
		}

		foreach (explode(',', $reveal) as $name) {
			$name = trim($name);
			if ($name !== '') {
				$this->grants[$objectId][$name] = true;
			}
		}
	}//end grantFor()

	/**
	 * Whether the read may see this property of this object.
	 *
	 * @param array<int, string> $objectIds The ids the object answers to (uuid, id, slug).
	 * @param string             $property  The property name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function isGranted(array $objectIds, string $property): bool {
		foreach ($objectIds as $objectId) {
			if ($objectId !== '' && isset($this->grants[$objectId][$property]) === true) {
				return true;
			}
		}

		return false;
	}//end isGranted()

	/**
	 * Run server code that needs explicit-reveal values unmasked and may filter on them.
	 *
	 * @param callable $operation The code.
	 *
	 * @return mixed What the code returns.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function trustedDuring(callable $operation): mixed {
		$this->trusted++;
		try {
			return $operation();
		} finally {
			$this->trusted--;
		}
	}//end trustedDuring()

	/**
	 * Whether masking and the query refusal stand down: a system operation, or trusted server code.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function isTrusted(): bool {
		return $this->trusted > 0 || SystemOperationContext::isActive() === true;
	}//end isTrusted()

	/**
	 * Whether a property definition makes it explicit-reveal. Only the
	 * `authorization.reveal` form counts: a property with an authorization
	 * block is one every read route filters (Schema::hasPropertyAuthorization),
	 * so a top-level `reveal` is ignored rather than half honoured.
	 *
	 * @param mixed $definition The property definition.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function isExplicit(mixed $definition): bool {
		if (is_array($definition) === false) {
			return false;
		}

		return self::isExplicitRules(rules: ($definition['authorization'] ?? null));
	}//end isExplicit()

	/**
	 * Whether a property's authorization block makes it explicit-reveal.
	 *
	 * @param mixed $rules The authorization block.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function isExplicitRules(mixed $rules): bool {
		return is_array($rules) === true && ($rules[self::KEY] ?? null) === self::EXPLICIT;
	}//end isExplicitRules()

	/**
	 * The explicit-reveal properties of a schema.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function explicitProperties(?Schema $schema): array {
		if ($schema === null) {
			return [];
		}

		$names = [];
		foreach (($schema->getProperties() ?? []) as $name => $definition) {
			if (self::isExplicit(definition: $definition) === true) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end explicitProperties()

	/**
	 * The mask of a value: its last four characters behind five stars. A value
	 * of at most twice that length shows no character at all, since its tail
	 * would be most (or all) of the value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The mask, '' for an empty value.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function mask(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$text = (string)$value;
		if ($text === '') {
			return '';
		}

		if (mb_strlen($text) <= 2 * self::VISIBLE) {
			return str_repeat('*', 5);
		}

		return str_repeat('*', 5) . mb_substr($text, -self::VISIBLE);
	}//end mask()

	/**
	 * Mask every explicit-reveal value of an object, with no grant: the routes
	 * that never reveal (GraphQL, access links, exports).
	 *
	 * @param Schema|null          $schema The object's schema.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The object, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function maskAll(?Schema $schema, array $object): array {
		foreach (self::explicitProperties(schema: $schema) as $name) {
			if (array_key_exists($name, $object) === true && $object[$name] !== null && $object[$name] !== '') {
				$object[$name] = self::mask(value: $object[$name]);
			}
		}

		return $object;
	}//end maskAll()

	/**
	 * Mask the explicit-reveal values of a history entry's change set:
	 * `{prop: {old, new}}` or `{prop: value}`.
	 *
	 * @param Schema|null          $schema  The object's schema.
	 * @param array<string, mixed> $changed The change set.
	 *
	 * @return array<string, mixed> The change set, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function maskChanges(?Schema $schema, array $changed): array {
		foreach (self::explicitProperties(schema: $schema) as $name) {
			if (array_key_exists($name, $changed) === false) {
				continue;
			}

			$change = $changed[$name];
			if (is_array($change) === false) {
				$changed[$name] = self::mask(value: $change);
				continue;
			}

			foreach (['old', 'new'] as $side) {
				if (isset($change[$side]) === true) {
					$change[$side] = self::mask(value: $change[$side]);
				}
			}

			$changed[$name] = $change;
		}

		return $changed;
	}//end maskChanges()

	/**
	 * Refuse a query that filters or sorts on an explicit-reveal property.
	 *
	 * A filter or a sort on a value that is only ever shown masked would let
	 * a reader learn it without the logged reveal (try values, read the order).
	 * Trusted server code may filter: it asked for the value on purpose.
	 *
	 * @param array<mixed> $query  The search query.
	 * @param Schema|null  $schema The schema searched.
	 *
	 * @return void
	 *
	 * @throws ExplicitRevealQueryException When the query names such a property.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function refuseOracleQuery(array $query, ?Schema $schema): void {
		$names = self::explicitProperties(schema: $schema);
		if ($names === [] || $this->isTrusted() === true) {
			return;
		}

		$order = $query['_order'] ?? [];
		if (is_string($order) === true) {
			$order = (json_decode($order, true) ?? []);
		}

		foreach ($names as $name) {
			if (array_key_exists($name, $query) === true || (is_array($order) === true && array_key_exists($name, $order) === true)) {
				throw new ExplicitRevealQueryException(
					message: sprintf('\'%s\' is shown only on request, so it cannot be filtered or sorted on.', $name)
				);
			}
		}
	}//end refuseOracleQuery()

	/**
	 * Put a stored value back where a write sends its mask.
	 *
	 * @param Schema|null          $schema   The schema.
	 * @param array<string, mixed> $incoming The write payload.
	 * @param array<string, mixed> $stored   The stored object, unrendered.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function restoreMasked(?Schema $schema, array $incoming, array $stored): array {
		foreach (self::explicitProperties(schema: $schema) as $name) {
			if (array_key_exists($name, $incoming) === false || array_key_exists($name, $stored) === false) {
				continue;
			}

			$mask = self::mask(value: $stored[$name]);
			if ($mask !== '' && $incoming[$name] === $mask) {
				$incoming[$name] = $stored[$name];
			}
		}

		return $incoming;
	}//end restoreMasked()
}//end class
