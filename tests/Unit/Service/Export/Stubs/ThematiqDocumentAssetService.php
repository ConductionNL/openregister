<?php

/**
 * Minimal stand-in for thematiq's DocumentAssetService under its real class
 * name, so DocumentStyleReader's `class_exists()` check passes in a test run
 * where thematiq is not installed. The reader resolves the service from the
 * container, which the test doubles, so this body is never the one called.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Thematiq\Service;

if (class_exists('OCA\\Thematiq\\Service\\DocumentAssetService') === false) {
	/**
	 * Stand-in for thematiq's real asset store.
	 */
	class DocumentAssetService {

		/**
		 * An uploaded asset.
		 *
		 * @param string $kind `logo` or `cover`.
		 *
		 * @return array{bytes: string, mime: string}|null The asset.
		 */
		public function read(string $kind): ?array {
			return null;
		}//end read()
	}//end class
}//end if
