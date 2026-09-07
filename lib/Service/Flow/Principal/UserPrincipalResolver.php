<?php

/**
 * A user is one user, if that user exists.
 *
 * 🔑 EXISTENCE IS CHECKED, and that is the only thing this adds over returning
 * the id. A reference to a deleted account must resolve to NOTHING rather than
 * to its own uid: returning the uid would let the guard authorise an identity
 * nobody can log in as, and would make "resolved to nobody" — the signal that
 * fails a step loudly — unreachable for the commonest way a performer goes
 * away.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use OCP\IUserManager;

/**
 * Resolves `{type: 'user'}` references.
 */
class UserPrincipalResolver implements IPrincipalResolver {

	/**
	 * Constructor.
	 *
	 * @param IUserManager $users Nextcloud's user manager.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(private readonly IUserManager $users) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string `user`.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function type(): string {
		return PrincipalReference::DEFAULT_TYPE;

	}//end type()

	/**
	 * The user, if there is one.
	 *
	 * @param string $id The uid.
	 *
	 * @return array<int, string> The uid, or nothing when no such user exists.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolve(string $id): array {
		$uid = trim($id);
		if ($uid === '' || $this->users->userExists($uid) === false) {
			return [];
		}

		return [$uid];

	}//end resolve()
}//end class
