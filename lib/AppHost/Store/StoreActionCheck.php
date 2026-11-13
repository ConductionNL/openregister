<?php

/**
 * OpenRegister AppHost — Store Action Check
 *
 * One question to a leaf app: may this user perform this declared action.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
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

namespace OCA\OpenRegister\AppHost\Store;

use OCP\IUser;

/**
 * A store action to authorise: the declaring app, the action and the user.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-action-posture-must-resolve-against-the-declaring-app
 */
final class StoreActionCheck {
	/**
	 * Constructor.
	 *
	 * @param string $appId  The declaring leaf app id.
	 * @param string $action The dot-separated action name.
	 * @param IUser  $user   The signed-in user.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $action,
		public readonly IUser $user,
	) {
	}//end __construct()

	/**
	 * The leaf app's action service, by convention: `OCA\<Studly>\Service\ActionAuthService`.
	 *
	 * The app id is StudlyCased the way Bootstrap does it.
	 *
	 * @return string The fully qualified class name.
	 */
	public function leafClass(): string {
		$parts = preg_split(pattern: '/[_\-]+/', subject: $this->appId);
		if ($parts === false || count($parts) === 0) {
			$parts = [$this->appId];
		}

		$studly = '';
		foreach ($parts as $part) {
			$studly .= ucfirst($part);
		}

		return 'OCA\\' . $studly . '\\Service\\ActionAuthService';
	}//end leafClass()
}//end class
