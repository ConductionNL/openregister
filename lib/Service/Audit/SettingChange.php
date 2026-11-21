<?php

/**
 * OpenRegister SettingChange
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

/**
 * One security-relevant setting whose value changed.
 */
final class SettingChange {

	/**
	 * Constructor.
	 *
	 * @param string $path The setting path.
	 * @param mixed  $old  The value before.
	 * @param mixed  $new  The value after.
	 */
	public function __construct(
		public readonly string $path,
		public readonly mixed $old,
		public readonly mixed $new,
	) {
	}//end __construct()
}//end class
