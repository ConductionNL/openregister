<?php

/**
 * OpenRegister credential update target
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
 * The credential an update writes to: the id it was asked by, the checked row's
 * uuid, its scope and the secret the update rotates in, if any.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final readonly class CredentialUpdateTarget {
	/**
	 * Bundle what the update's two writes need.
	 *
	 * @param string      $id      The credential identifier as the route named it.
	 * @param string      $uuid    The uuid of the row the guard checked.
	 * @param string      $scope   The credential's scope (personal|organisation).
	 * @param string|null $rotated The new secret, or null when the update rotates none.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public string $id,
		public string $uuid,
		public string $scope,
		public ?string $rotated,
	) {
	}//end __construct()
}//end class
