<?php

/**
 * ObjectChange
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One object change the caches have to follow.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectChange {
	/**
	 * The register changed in.
	 *
	 * @var int|null
	 */
	public ?int $registerId = null;

	/**
	 * The schema changed in.
	 *
	 * @var int|null
	 */
	public ?int $schemaId = null;

	/**
	 * Start a change.
	 *
	 * @param ObjectEntity|null $object    The object, when the change names one
	 * @param string            $operation The operation
	 */
	public function __construct(
		public readonly ?ObjectEntity $object,
		public readonly string $operation,
	) {
	}//end __construct()

	/**
	 * Name the register and schema; what is not named comes from the object.
	 *
	 * @param int|null $registerId The register
	 * @param int|null $schemaId   The schema
	 *
	 * @return self
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function within(?int $registerId, ?int $schemaId): self {
		$this->registerId = ($registerId ?? self::idOf(value: $this->object?->getRegister()));
		$this->schemaId   = ($schemaId ?? self::idOf(value: $this->object?->getSchema()));

		return $this;
	}//end within()

	/**
	 * What a log line reports about the change.
	 *
	 * @return array{operation: string, registerId: int|null, schemaId: int|null}
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function facts(): array {
		return ['operation' => $this->operation, 'registerId' => $this->registerId, 'schemaId' => $this->schemaId];
	}//end facts()

	/**
	 * A stored id as an integer.
	 *
	 * @param string|int|null $value The stored id
	 *
	 * @return int|null The id
	 */
	private static function idOf(string|int|null $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int) $value;
	}//end idOf()
}//end class
