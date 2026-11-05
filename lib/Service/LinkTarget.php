<?php

/**
 * OpenRegister Link Target
 *
 * The OpenRegister object an integration link is attached to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The object (uuid, register, schema) a link row points at.
 */
final class LinkTarget {

	/**
	 * Schema id; 0 when the object has none.
	 *
	 * @var integer
	 */
	private int $schemaId = 0;

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object's uuid.
	 * @param int    $registerId The object's register id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly int $registerId,
	) {
	}//end __construct()

	/**
	 * A copy in the given schema.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function inSchema(int $schemaId): self {
		$copy           = clone $this;
		$copy->schemaId = $schemaId;
		return $copy;
	}//end inSchema()

	/**
	 * The schema id; 0 when none was given.
	 *
	 * @return int The schema id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function schemaId(): int {
		return $this->schemaId;
	}//end schemaId()
}//end class
