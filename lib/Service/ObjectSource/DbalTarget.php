<?php

/**
 * DbalTarget: an opened external table, ready to read or write.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;

/**
 * The connection to the external database, the object reference it was opened
 * for, and the schema's scalar columns (the only columns a call may touch).
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalTarget {

	/**
	 * Constructor.
	 *
	 * @param Connection         $connection The DBAL connection.
	 * @param DbalObjectRef      $ref        The object the call addresses.
	 * @param array<int, string> $columns    The schema's scalar columns.
	 */
	public function __construct(
		public readonly Connection $connection,
		public readonly DbalObjectRef $ref,
		public readonly array $columns,
	) {
	}//end __construct()
}//end class
