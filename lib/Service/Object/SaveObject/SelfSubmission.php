<?php

/**
 * The `@self` block a caller sent, with the user acting on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\IUser;

/**
 * One submitted `@self` block and who submits it, which decides whether a sent `@self.folder` may be bound.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-openregisters-own-account-holds-every-managed-folder-req-ofoa-001
 */
final class SelfSubmission {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $selfData   The `@self` metadata as sent.
	 * @param IUser|null           $actingUser The acting user, or null for the session user.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly array $selfData,
		public readonly ?IUser $actingUser = null,
	) {
	}//end __construct()
}//end class
