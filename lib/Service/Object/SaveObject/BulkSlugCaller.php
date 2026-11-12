<?php

/**
 * OpenRegister BulkSlugCaller
 *
 * The register and schema a bulk save's caller passed, as entities or ids (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Which table the rows of a bulk slug pass are written to, as far as the caller says.
 *
 * With a caller schema (the fast path) every row is written into the caller's
 * register and schema, whatever a row's advisory `@self.register` says; without
 * one (the mixed path) each row names its own, falling back to the caller's register.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
 */
final class BulkSlugCaller {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The caller's register, if given.
	 * @param Schema|string|int|null   $schema   The caller's schema, if given.
	 */
	public function __construct(
		public readonly Register|string|int|null $register,
		public readonly Schema|string|int|null $schema,
	) {
	}//end __construct()

	/**
	 * The register and schema id a row is written to.
	 *
	 * @param mixed $self The row's metadata (`@self`, or the flat row).
	 *
	 * @return array{0: string|int|null, 1: string|int|null} Register id and schema id; null when unknown.
	 */
	public function idsOf(mixed $self): array {
		$registerId = $this->idOf(entity: $this->register);
		$schemaId = $this->idOf(entity: $this->schema);
		if ($schemaId !== null) {
			return [($registerId ?? $self['register'] ?? null), $schemaId];
		}

		return [($self['register'] ?? $registerId), ($self['schema'] ?? null)];
	}//end idsOf()

	/**
	 * The caller's register entity, when it is the one a group is written to.
	 *
	 * @param string $id The group's register id.
	 *
	 * @return Register|null The register, or null when the caller passed only an id or another one.
	 */
	public function registerFor(string $id): Register|null {
		if ($this->register instanceof Register && (string)$this->register->getId() === $id) {
			return $this->register;
		}

		return null;
	}//end registerFor()

	/**
	 * The caller's schema entity, when it is the one a group is written to.
	 *
	 * @param string $id The group's schema id.
	 *
	 * @return Schema|null The schema, or null when the caller passed only an id or another one.
	 */
	public function schemaFor(string $id): Schema|null {
		if ($this->schema instanceof Schema && (string)$this->schema->getId() === $id) {
			return $this->schema;
		}

		return null;
	}//end schemaFor()

	/**
	 * The id of a register or schema the caller passed, as an entity or an id.
	 *
	 * @param Register|Schema|string|int|null $entity The entity or id.
	 *
	 * @return string|int|null The id.
	 */
	private function idOf(Register|Schema|string|int|null $entity): string|int|null {
		if ($entity instanceof Register || $entity instanceof Schema) {
			return $entity->getId();
		}

		return $entity;
	}//end idOf()
}//end class
