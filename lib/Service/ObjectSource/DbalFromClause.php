<?php

/**
 * OpenRegister DBAL from clause
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;

/**
 * Where an aggregation reads from: the external database connection and the
 * quoted table (or derived query) expression on it.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalFromClause {
	/**
	 * Constructor.
	 *
	 * @param Connection $connection The DBAL connection.
	 * @param string     $table      The quoted table (or derived query) expression.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly Connection $connection,
		public readonly string $table,
	) {
	}//end __construct()
}//end class
