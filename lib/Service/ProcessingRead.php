<?php

/**
 * OpenRegister ProcessingRead
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One read or export of personal data to be recorded in the processing log: what, how, by whom and with which query.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class ProcessingRead {

	/**
	 * Constructor.
	 *
	 * @param string               $action  The processing action (`read` | `export`).
	 * @param string|null          $channel The access channel, detected when null.
	 * @param string|null          $actor   The actor, the current user when null.
	 * @param array<string, mixed> $query   The query parameters of a list or search read.
	 */
	public function __construct(
		public readonly string $action='read',
		public readonly ?string $channel=null,
		public readonly ?string $actor=null,
		public readonly array $query=[],
	) {

	}//end __construct()
}//end class
