<?php

/**
 * The filter set one object-write match sends to the store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;
use RuntimeException;

/**
 * Routes each match pair into the property bag or the `@self` bag.
 *
 * A schema property and an object's metadata live in different places: the
 * property in the row's JSON document, the metadata in the magic table's
 * underscore-prefixed columns. A filter therefore has to be addressed to the
 * right one. Every match pair used to go into the property bag
 * unconditionally, so `uuid` — which is metadata and never a document field —
 * was looked up as a property that does not exist and hit the
 * `WHERE 1 = 0` branch. Zero rows, no error: an update or delete matched
 * nothing while the run reported success, and an upsert inserted a duplicate.
 *
 * That `uuid` in particular is what `ObjectReadNode` puts on every item it
 * emits, expressly so a follow-up write or delete can name the row — so the
 * most natural way to chain a read into a write was the one way that could
 * not work.
 *
 * Routing rules, in order:
 *   1. An explicit `@self.<field>` prefix always addresses metadata.
 *   2. A name the schema declares as a property addresses the property. This
 *      keeps precedence with the schema, so a schema that genuinely has a
 *      `name` or `owner` property behaves exactly as before.
 *   3. A name that is a known metadata field addresses metadata.
 *   4. Anything else is refused, by name. It could only ever have produced
 *      `1 = 0`, and a guard that silently matches nothing is not a guard.
 */
final class ObjectWriteMatchFilters {

	/**
	 * Prefix that addresses a match pair to object metadata rather than a property.
	 *
	 * @var string
	 */
	private const SELF_PREFIX = '@self.';

	/**
	 * Metadata fields a match pair may name.
	 *
	 * Deliberately narrower than the full metadata column set: these are the
	 * fields that identify or scope a row and are stable enough to match on.
	 * `uuid` is the one that matters most — it is what `ObjectReadNode` puts on
	 * every item it emits so a follow-up write can name the row.
	 *
	 * @var string[]
	 */
	private const MATCHABLE_METADATA_FIELDS = [
		'uuid',
		'slug',
		'uri',
		'version',
		'owner',
		'organisation',
		'application',
		'name',
		'created',
		'updated',
	];

	/**
	 * The filters so far, scoped to the plan's register and schema.
	 *
	 * @var array<string, mixed>
	 */
	public array $all;

	/**
	 * The schema's declared properties.
	 *
	 * @var array<string, mixed>
	 */
	private array $properties;

	/**
	 * Constructor.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param IL10N           $l10n Translations.
	 */
	public function __construct(ObjectWritePlan $plan, private readonly IL10N $l10n) {
		$this->all = ['register' => $plan->register->getId(), 'schema' => $plan->schema->getId()];
		$this->properties = ($plan->schema->getProperties() ?? []);
	}//end __construct()

	/**
	 * Route one resolved match pair.
	 *
	 * @param string $property The configured match property name.
	 * @param mixed  $value    The resolved match value.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the property is neither a schema property nor metadata.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function add(string $property, mixed $value): void {
		if (str_starts_with($property, self::SELF_PREFIX) === true) {
			$this->addExplicitMetadata(field: substr($property, strlen(self::SELF_PREFIX)), value: $value);
			return;
		}

		$this->addBareName(property: $property, value: $value);
	}//end add()

	/**
	 * Address a name without a prefix; the schema wins for any name it declares.
	 *
	 * @param string $property The configured match property name.
	 * @param mixed  $value    The resolved match value.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the property is neither a schema property nor metadata.
	 */
	private function addBareName(string $property, mixed $value): void {
		$isDeclared = array_key_exists($property, $this->properties);
		if ($isDeclared === false && in_array($property, self::MATCHABLE_METADATA_FIELDS, true) === true) {
			$this->all['@self'][$property] = $value;
			return;
		}

		if ($isDeclared === false) {
			$this->refuseUndeclared(property: $property);
		}

		$this->all[$property] = $value;
	}//end addBareName()

	/**
	 * Address a metadata field named with the `@self.` prefix.
	 *
	 * @param string $field The field.
	 * @param mixed  $value The resolved match value.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When no such field is matchable.
	 */
	private function addExplicitMetadata(string $field, mixed $value): void {
		if (in_array($field, self::MATCHABLE_METADATA_FIELDS, true) === false) {
			throw new RuntimeException(
				$this->l10n->t(
					'The match names metadata field "%1$s", which does not exist. Matchable metadata fields are: %2$s.',
					[$field, implode(', ', self::MATCHABLE_METADATA_FIELDS)]
				)
			);
		}

		$this->all['@self'][$field] = $value;
	}//end addExplicitMetadata()

	/**
	 * Refuse a name that is neither a declared property nor metadata.
	 *
	 * Refuses only when the schema actually told us what it has. An empty property
	 * list cannot distinguish "this schema declares nothing" from "the declaration
	 * was not available here", and a guard that fires on missing information would
	 * break working flows rather than protect them. With no declaration to check
	 * against, the query layer answers.
	 *
	 * @param string $property The configured match property name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the schema declares properties and this is not one.
	 */
	private function refuseUndeclared(string $property): void {
		if ($this->properties === []) {
			return;
		}

		throw new RuntimeException(
			$this->l10n->t(
				'The match names "%1$s", which is neither a property of this schema nor a metadata field, '
				. 'so it could never match anything. Matchable metadata fields are: %2$s.',
				[$property, implode(', ', self::MATCHABLE_METADATA_FIELDS)]
			)
		);
	}//end refuseUndeclared()
}//end class
