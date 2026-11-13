<?php

/**
 * ShareListCheck: what is asked of one share list.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * The raw `sharedWith` value and the permissions that satisfy
 * {@see SharePrincipalDeriver::grants()}.
 *
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-a-brokered-credential-carries-a-principal-share-list
 */
final class ShareListCheck {

	/**
	 * Constructor.
	 *
	 * @param mixed              $sharedWith  The raw `sharedWith` value.
	 * @param array<string|null> $permissions Permissions that satisfy the check; null matches a share entry
	 *                                        that carries no explicit permission.
	 */
	public function __construct(
		public readonly mixed $sharedWith,
		public readonly array $permissions,
	) {
	}//end __construct()
}//end class
