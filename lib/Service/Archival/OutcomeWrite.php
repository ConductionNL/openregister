<?php

/**
 * Writing one review outcome onto the record it is about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The record, what is being done to it and what is being written, for the messages a failure gives.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class OutcomeWrite {
	/**
	 * Hold the values.
	 *
	 * @param string $entryUuid The uuid of the record.
	 * @param string $verb      What is being done to it.
	 * @param string $what      What is being written.
	 */
	public function __construct(
		public readonly string $entryUuid,
		public readonly string $verb,
		public readonly string $what,
	) {
	}//end __construct()
}//end class
