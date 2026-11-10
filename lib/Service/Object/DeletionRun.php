<?php

/**
 * OpenRegister DeletionRun
 *
 * The resolved context of one root deletion, shared by DeleteObject's integrity helpers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;

/**
 * The deletion context (object, register, schema) and the uuid the caller asked to delete.
 *
 * Built and used only inside DeleteObject.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DeletionRun {

	/**
	 * Constructor.
	 *
	 * @param array  $context The deletion context from DeleteObject::resolveDeletionContext().
	 * @param string $uuid    The identifier the caller asked to delete.
	 *
	 * @psalm-param array{object: ObjectEntity, register?: mixed, schema?: mixed} $context
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly array $context,
		public readonly string $uuid,
	) {
	}//end __construct()

	/**
	 * The object being deleted.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function object(): ObjectEntity {
		return $this->context['object'];
	}//end object()

	/**
	 * The context's register and schema when both are concrete entities, otherwise null.
	 *
	 * @return RegisterSchema|null The resolved table.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function table(): ?RegisterSchema {
		$register = ($this->context['register'] ?? null);
		$schema   = ($this->context['schema'] ?? null);
		if ($register instanceof Register && $schema instanceof Schema) {
			return new RegisterSchema(register: $register, schema: $schema);
		}

		return null;
	}//end table()

	/**
	 * The slug of the context's schema, the trigger schema of a cascade, or null.
	 *
	 * @return string|null The trigger schema slug.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function triggerSlug(): ?string {
		$schema = ($this->context['schema'] ?? null);
		if ($schema instanceof Schema) {
			return $schema->getSlug();
		}

		return null;
	}//end triggerSlug()
}//end class
