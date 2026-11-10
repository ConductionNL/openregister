<?php

/**
 * OpenRegister AppConfigValue
 *
 * Reads one of this app's own string settings, blank and unreadable as unset.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

use OCP\IAppConfig;
use Throwable;

/**
 * One administered string setting of this app, or null.
 *
 * Shared by the outbound proxy settings and the security.txt builder, which
 * both treat an unset, blank or unreadable value the same way: as not set.
 *
 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
 */
final class AppConfigValue {

	/**
	 * The app whose settings are read.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Read one of this app's own values, trimmed, or null when it is unset, blank or unreadable.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param string     $key       The configuration key.
	 *
	 * @return string|null The value, or null.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-the-instance-answers-the-well-known-paths-and-honours-an-administered-proxy-req-avs-004
	 */
	public static function nonEmpty(IAppConfig $appConfig, string $key): ?string {
		try {
			$value = trim((string)$appConfig->getValueString(self::APP_ID, $key, ''));
		} catch (Throwable) {
			return null;
		}

		if ($value === '') {
			return null;
		}

		return $value;
	}//end nonEmpty()
}//end class
