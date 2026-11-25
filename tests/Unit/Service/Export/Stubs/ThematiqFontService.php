<?php

/**
 * Minimal stand-in for thematiq's FontService under its real class name, so
 * DocumentStyleReader's `class_exists()` check passes in a test run where
 * thematiq is not installed. The reader resolves the service from the
 * container, which the test doubles, so this body is never the one called.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Thematiq\Service;

if (class_exists('OCA\\Thematiq\\Service\\FontService') === false) {
	/**
	 * Stand-in for thematiq's real font store.
	 */
	class FontService {

		/**
		 * An uploaded font's bytes.
		 *
		 * @param string $id The font id.
		 *
		 * @return string|null The bytes.
		 */
		public function readFontBytes(string $id): ?string {
			return null;
		}//end readFontBytes()
	}//end class
}//end if
