<?php

/**
 * OpenRegister credential caller
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
 * The principal a share list is judged for: the caller and their groups.
 *
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-shares-are-discoverable-by-owner-and-recipient
 */
final readonly class CredentialCaller {
	/**
	 * Bundle the caller with the groups they are in.
	 *
	 * @param string   $uid    The caller's user id.
	 * @param string[] $groups The caller's group ids.
	 *
	 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-shares-are-discoverable-by-owner-and-recipient
	 */
	public function __construct(
		public string $uid,
		public array $groups,
	) {
	}//end __construct()
}//end class
