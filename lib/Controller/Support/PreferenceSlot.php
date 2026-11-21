<?php

/**
 * OpenRegister PreferenceSlot
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * Where one user's preference is stored: the user id and the `pref_` key.
 *
 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
 */
final class PreferenceSlot {
	/**
	 * Constructor.
	 *
	 * @param string $userId    The user id.
	 * @param string $storedKey The stored (`pref_`) key.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md#requirement-generic-preferences-controller
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $storedKey,
	) {
	}//end __construct()
}//end class
