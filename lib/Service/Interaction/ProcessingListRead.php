<?php

/**
 * One list or search read as the AVG processing log records it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

/**
 * The query parameters and the action (`read` or `export`) of one list or
 * search read, logged once per register and schema (ADR-011 Rule 2).
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-reads-of-personal-data-are-registered-through-the-one-read-registration
 */
final class ProcessingListRead {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query  The query parameters of the list or search.
	 * @param string               $action `read` or `export`.
	 */
	public function __construct(
		public readonly array $query = [],
		public readonly string $action = ReadHistoryService::READ_ACTION,
	) {
	}//end __construct()
}//end class
