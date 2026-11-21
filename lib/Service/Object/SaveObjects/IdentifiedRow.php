<?php

/**
 * One bulk row on the transform path: its @self data with generated identifiers, the row and its position.
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

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * A row whose identifiers were generated, read by the required-field check and the database-row build.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class IdentifiedRow {

	/**
	 * The row's @self data with id, register and schema filled in.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $selfData;

	/**
	 * The row as the caller passed it.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $object;

	/**
	 * The row's position in the batch, for error reporting.
	 *
	 * @var int
	 */
	public readonly int $index;

	/**
	 * Record an identified row.
	 *
	 * @param array $selfData The @self data with identifiers.
	 * @param array $object   The row.
	 * @param int   $index    The row's position.
	 */
	public function __construct(array $selfData, array $object, int $index) {
		$this->selfData = $selfData;
		$this->object   = $object;
		$this->index    = $index;
	}//end __construct()
}//end class
