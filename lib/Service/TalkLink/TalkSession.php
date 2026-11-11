<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * Talk's Manager as one user reads it: room lookups are scoped to that user.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class TalkSession {
	/**
	 * Build the session.
	 *
	 * @param object $manager Talk's Manager.
	 * @param string $userUid The user the rooms are read as.
	 */
	public function __construct(
		public readonly object $manager,
		public readonly string $userUid,
	) {
	}//end __construct()
}//end class
