<?php

/**
 * OpenRegister linked entity query
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One foreign-table query the linked-entity enricher runs: the SQL, its bound
 * parameters, and the debug line logged when it fails (null logs nothing).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LinkedEntityQuery {
	/**
	 * Constructor.
	 *
	 * @param string      $sql     The query, with `?` placeholders.
	 * @param array       $params  The bound parameters.
	 * @param string|null $failure The debug message to log when the query fails, or null to log nothing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $params,
		public readonly ?string $failure=null,
	) {
	}//end __construct()
}//end class
