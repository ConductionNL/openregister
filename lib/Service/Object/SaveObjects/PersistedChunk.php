<?php

/**
 * One chunk of a bulk save as it was written: the rows sent and what the mapper returned.
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
 * The outcome of persisting a chunk, read by the steps that classify it into results.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class PersistedChunk {

	/**
	 * The rows that were written, in database format.
	 *
	 * @var array<int|string, mixed>
	 */
	public readonly array $rows;

	/**
	 * What ultraFastBulkSave returned: classified rows, a legacy UUID array, or something unexpected.
	 *
	 * @var mixed
	 */
	public readonly mixed $returned;

	/**
	 * Record a written chunk.
	 *
	 * @param array $rows     The rows that were written.
	 * @param mixed $returned What the mapper returned for them.
	 */
	public function __construct(array $rows, mixed $returned) {
		$this->rows     = $rows;
		$this->returned = $returned;
	}//end __construct()
}//end class
