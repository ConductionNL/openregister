<?php

/**
 * OpenRegister ObjectReadStatePropertiesEvent
 *
 * Dispatched by SubstantiveChangeEvaluator when a write to an object whose
 * schema names its read-state properties (`x-openregister-read-state`
 * `properties`) is judged. The schema's list is the floor every object of the
 * schema shares; a listener may NARROW it for this one object, for instance to
 * what the object's type declares, and never widen it. A property the schema
 * does not name stays out whatever a listener passes.
 *
 * Narrowing composes: every `narrowTo()` intersects with what is left, so two
 * listeners can only agree on less, never undo each other.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Lets a leaf app narrow, per object, which properties make a write news.
 */
class ObjectReadStatePropertiesEvent extends Event {

	/**
	 * The properties still counting, a subset of the schema's list.
	 *
	 * @var array<int, string>
	 */
	private array $properties;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity       $object   The object as it will be stored.
	 * @param Schema             $schema   The object's schema.
	 * @param array<int, string> $declared The properties the schema names.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function __construct(
		private readonly ObjectEntity $object,
		private readonly Schema $schema,
		private readonly array $declared,
	) {
		parent::__construct();
		$this->properties = array_values($declared);
	}//end __construct()

	/**
	 * The object the write is about, as it will be stored.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * The object's schema.
	 *
	 * @return Schema The schema.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()

	/**
	 * The properties the schema names, before any listener narrowed them.
	 *
	 * @return array<int, string> The schema's list.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function getDeclared(): array {
		return $this->declared;
	}//end getDeclared()

	/**
	 * Keep only these of the properties still counting.
	 *
	 * @param array<int, mixed> $properties The properties this object's own declaration names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function narrowTo(array $properties): void {
		$names = array_filter($properties, static fn ($name): bool => is_string($name) === true && $name !== '');
		$this->properties = array_values(array_intersect($this->properties, $names));
	}//end narrowTo()

	/**
	 * The properties that count for this object.
	 *
	 * @return array<int, string> The narrowed list.
	 *
	 * @spec openspec/specs/object-read-state/spec.md
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()
}//end class
