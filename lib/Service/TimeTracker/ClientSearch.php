<?php

/**
 * ClientSearch: the clients the picker lists for a user.
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
 * The user whose clients the picker lists and the optional name filter.
 *
 * @internal
 *
 * @spec openspec/specs/integration-time-tracker/spec.md
 */
final class ClientSearch {

	/**
	 * Describe it.
	 *
	 * @param string      $uid    The user whose clients are listed.
	 * @param string|null $search Optional name filter.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly ?string $search = null,
	) {
	}//end __construct()
}//end class
