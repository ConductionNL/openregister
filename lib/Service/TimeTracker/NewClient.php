<?php

/**
 * NewClient: a TimeManager client to create for a user.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TimeTracker
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TimeTracker;

/**
 * The name and the owning user of a TimeManager client created from OpenRegister.
 *
 * @internal
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */
final class NewClient {

	/**
	 * Describe it.
	 *
	 * @param string $name The client name.
	 * @param string $uid  The owning user.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $uid,
	) {
	}//end __construct()
}//end class
