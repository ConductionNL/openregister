<?php

/**
 * StoredEntity: narrows a Kernel entity interface back to the stored Db row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRowInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;

/**
 * Kernel contracts take the entity interfaces, so lib/Kernel names nothing in
 * lib/Db. Their implementations work on stored rows; these helpers turn the
 * interface back into the row and refuse anything else.
 */
final class StoredEntity {

	/**
	 * The stored Schema row behind a SchemaInterface, or null for null.
	 *
	 * @param SchemaInterface|null $schema The schema a contract received.
	 *
	 * @return Schema|null
	 *
	 * @psalm-return ($schema is null ? null : Schema)
	 * @phpstan-return ($schema is null ? null : Schema)
	 *
	 * @throws InvalidArgumentException When the schema is not a stored Schema row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function schema(?SchemaInterface $schema): ?Schema {
		if ($schema === null || $schema instanceof Schema) {
			return $schema;
		}

		throw new InvalidArgumentException('Only a stored Schema row is accepted here.');
	}//end schema()

	/**
	 * The stored Register row behind a RegisterInterface, or null for null.
	 *
	 * @param RegisterInterface|null $register The register a contract received.
	 *
	 * @return Register|null
	 *
	 * @psalm-return ($register is null ? null : Register)
	 * @phpstan-return ($register is null ? null : Register)
	 *
	 * @throws InvalidArgumentException When the register is not a stored Register row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function register(?RegisterInterface $register): ?Register {
		if ($register === null || $register instanceof Register) {
			return $register;
		}

		throw new InvalidArgumentException('Only a stored Register row is accepted here.');
	}//end register()

	/**
	 * The stored ObjectEntity row behind a ObjectRowInterface, or null for null.
	 *
	 * @param ObjectRowInterface|null $object The object a contract received.
	 *
	 * @return ObjectEntity|null
	 *
	 * @psalm-return ($object is null ? null : ObjectEntity)
	 * @phpstan-return ($object is null ? null : ObjectEntity)
	 *
	 * @throws InvalidArgumentException When the object is not a stored ObjectEntity row.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public static function object(?ObjectRowInterface $object): ?ObjectEntity {
		if ($object === null || $object instanceof ObjectEntity) {
			return $object;
		}

		throw new InvalidArgumentException('Only a stored ObjectEntity row is accepted here.');
	}//end object()
}//end class
