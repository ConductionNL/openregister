<?php

/**
 * BrokerIdentity — who a brokered GitHub call is made for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
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

namespace OCA\OpenRegister\Service\Config;

/**
 * The app id and the optional acting user a brokered call names.
 *
 * The broker checks the app id against the credential's allowed apps, so an
 * app that federates its own configuration (hermiq's agent templates) names
 * itself instead of OpenRegister. The acting user only matters for a call
 * without a user session (a background job): the broker lets a session
 * identity win over it. Without either, the call is made exactly as before:
 * as OpenRegister, with no asserted user.
 *
 * @spec openspec/changes/store-through-federated-config
 */
final class BrokerIdentity {

	/**
	 * The app id OpenRegister's own calls name.
	 */
	public const DEFAULT_APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param string      $appId        The calling app id the broker checks.
	 * @param string|null $actingUserId The asserted user for a sessionless call, or null.
	 */
	private function __construct(
		public readonly string $appId,
		public readonly ?string $actingUserId,
	) {
	}//end __construct()

	/**
	 * The identity for an app and an acting user; empty values fall back to
	 * OpenRegister and to no asserted user.
	 *
	 * @param string|null $appId        The calling app id, or null for OpenRegister.
	 * @param string|null $actingUserId The acting user, or null for none.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public static function of(?string $appId = null, ?string $actingUserId = null): self {
		$app = trim((string)$appId);
		if ($app === '') {
			$app = self::DEFAULT_APP_ID;
		}

		$user = trim((string)$actingUserId);
		if ($user === '') {
			$user = null;
		}

		return new self(appId: $app, actingUserId: $user);
	}//end of()
}//end class
