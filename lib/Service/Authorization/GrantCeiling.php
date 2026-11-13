<?php

/**
 * OpenRegister GrantCeiling
 *
 * Nobody grants a right they do not hold (REQ-RAH-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Exception\GrantExceedsGranterException;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * The one check every write that grants a right calls before it stores.
 *
 * A Nextcloud administrator may grant anything. Anyone else may grant only an
 * action they hold on that schema themselves, by the same evaluation every
 * other permission check uses, and may never grant `manage`: managing a
 * definition includes editing who may manage it, so a manager who could hand
 * it on could multiply themselves without an administrator.
 *
 * A right whose holding cannot be determined is not held: the grant is refused
 * rather than stored on a guess.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */
class GrantCeiling {

	/**
	 * The action only an administrator may grant.
	 */
	public const MANAGE = 'manage';

	/**
	 * Build the check.
	 *
	 * @param IGroupManager              $groups      Answers whether the granter is an administrator.
	 * @param PermissionHandlerInterface $permissions Answers whether the granter holds an action.
	 */
	public function __construct(
		private readonly IGroupManager $groups,
		private readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()

	/**
	 * Refuse the grants when any gives more than the granter holds.
	 *
	 * @param IUser               $granter The person granting.
	 * @param array<GrantedRight> $grants  The rights the write would give.
	 *
	 * @return void
	 *
	 * @throws GrantExceedsGranterException Naming every right given beyond the granter's own.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
	 */
	public function assertWithin(IUser $granter, array $grants): void {
		if ($grants === [] || $this->groups->isAdmin($granter->getUID()) === true) {
			return;
		}

		$excess = [];
		foreach ($grants as $grant) {
			if ($this->holds(granter: $granter, grant: $grant) === false) {
				$excess[] = $grant->named();
			}
		}

		if ($excess !== []) {
			throw new GrantExceedsGranterException(excess: $excess);
		}
	}//end assertWithin()

	/**
	 * Whether the granter holds the right they are giving.
	 *
	 * @param IUser        $granter The person granting, not an administrator.
	 * @param GrantedRight $grant   The right given.
	 *
	 * @return bool True when they hold it.
	 */
	private function holds(IUser $granter, GrantedRight $grant): bool {
		if ($grant->action === self::MANAGE) {
			return false;
		}

		try {
			return $this->permissions->hasPermission(
				schema: $grant->schema,
				action: $grant->action,
				userId: $granter->getUID(),
				register: $grant->register
			);
		} catch (Throwable $e) {
			return false;
		}
	}//end holds()
}//end class
