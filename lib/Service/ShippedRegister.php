<?php

/**
 * A register slug as one app ships it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * A register slug together with the app that ships the configuration declaring it.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class ShippedRegister {
	/**
	 * Hold the values.
	 *
	 * @param string $appId The app that ships the configuration.
	 * @param string $slug  A register slug the configuration declares.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $slug,
	) {
	}//end __construct()
}//end class
