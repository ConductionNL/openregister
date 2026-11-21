<?php

/**
 * Minimal stand-in for thematiq's DocumentStyleService under its real class
 * name, so DocumentStyleReader's `class_exists()` check passes in a test run
 * where thematiq is not installed. The reader resolves the service from the
 * container, which the test doubles, so this body is never the one called.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Thematiq\Service;

if (class_exists('OCA\\Thematiq\\Service\\DocumentStyleService') === false) {
	/**
	 * Stand-in for thematiq's real service.
	 */
	class DocumentStyleService {

		/**
		 * The profile for a user.
		 *
		 * @param string|null $uid The user.
		 *
		 * @return array<string, mixed> The profile.
		 */
		public function forUser(?string $uid): array {
			return [];
		}//end forUser()
	}//end class
}//end if
