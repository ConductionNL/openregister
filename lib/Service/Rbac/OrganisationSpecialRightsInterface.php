<?php

/**
 * OrganisationSpecialRightsInterface: the special-right names, the read and the in-process writer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The methods of OrganisationSpecialRights that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface OrganisationSpecialRightsInterface {

	/**
	 * The special right to manage the grants and the owning group of a record.
	 *
	 * @var string
	 */
	public const GRANT_MANAGE = 'grant_manage';

	/**
	 * The special right to run an agent.
	 *
	 * @var string
	 */
	public const AGENT_USE = 'agent_use';

	/**
	 * The special right to have a language model called on one's behalf.
	 *
	 * @var string
	 */
	public const LLM_USE = 'llm_use';

	/**
	 * Every special right an organisation carries, in the order the editor shows them.
	 *
	 * @var string[]
	 */
	public const ALL = [
		self::AGENT_USE,
		self::LLM_USE,
		self::GRANT_MANAGE,
	];

	/**
	 * The rights where `*` means every signed-in user, and a missing key means nobody narrowed it.
	 *
	 * Not `grant_manage`: a right that decides who reads a record is never given
	 * to everybody by a wildcard or by leaving it out.
	 *
	 * @var string[]
	 */
	public const OPEN_BY_DEFAULT = [
		self::AGENT_USE,
		self::LLM_USE,
	];

	/**
	 * The entry that gives a right to every signed-in user.
	 *
	 * @var string
	 */
	public const EVERYONE = '*';

	/**
	 * Whether a caller holds a special right on one record.
	 *
	 * The right is read from the organisation the RECORD belongs to, not from
	 * the caller's active organisation: a service identity has no active
	 * organisation, and a right over a record is the business of whoever the
	 * record belongs to. A record that names no organisation is read against
	 * the instance's default organisation, which is where the tenancy layer
	 * puts it too.
	 *
	 * @param string $right The special right's key.
	 * @param string[] $userGroups The caller's group ids.
	 * @param ObjectEntity $object The record.
	 *
	 * @return bool True when one of the caller's groups holds the right on this record.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function holdsOn(string $right, array $userGroups, ObjectEntity $object): bool;

	/**
	 * Give a group a special right in one organisation, or narrow or widen what it has.
	 *
	 * For code shipped with an app: an app that brings a service identity names
	 * what that identity may do when the app is installed. It is not reachable
	 * over HTTP; the organisation endpoint is, and it lets only an administrator
	 * or the organisation's owner change this right.
	 *
	 * One entry per group. Giving a group the right again replaces its entry, so
	 * an app that changes its list of schemas does not leave the old one behind.
	 *
	 * @param string            $organisationUuid The organisation.
	 * @param SpecialRightGrant $grant            The right, the group that holds it and what it is narrowed to.
	 *
	 * @return bool True when the stored block changed.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When there is no such organisation.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function give(string $organisationUuid, SpecialRightGrant $grant): bool;

	/**
	 * Give a group a special right in every organisation of the instance.
	 *
	 * The right is read from the organisation a record belongs to, so an app
	 * whose service identity works on records of every organisation names it in
	 * each. Run again on every upgrade: an organisation made since is covered
	 * then, and one that already has the entry is not written.
	 *
	 * @param SpecialRightGrant $grant The right, the group that holds it and what it is narrowed to.
	 *
	 * @return array{organisations: int, changed: int} How many organisations were seen and written.
	 *
	 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
	 */
	public function giveInEveryOrganisation(SpecialRightGrant $grant): array;

	/**
	 * Whether a caller may use something a special right guards, in one organisation.
	 *
	 * For the rights in {@see OPEN_BY_DEFAULT}; any other right answers false.
	 * An administrator may. A key the organisation does not carry is not
	 * narrowed; a list holding `*` admits every signed-in user; otherwise the
	 * caller must be in one of the listed groups.
	 *
	 * @param string      $right            The special right's key.
	 * @param string      $userId           The caller.
	 * @param string|null $organisationUuid The organisation the used thing belongs to, or null.
	 *
	 * @return bool True when the caller may.
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function mayUse(string $right, string $userId, ?string $organisationUuid): bool;

	/**
	 * Give every signed-in user a special right in every organisation that does not already.
	 *
	 * @param string $right The special right's key, one of {@see OPEN_BY_DEFAULT}.
	 *
	 * @return array{organisations: int, changed: int} How many organisations were seen and written.
	 *
	 * @throws \InvalidArgumentException When the right may not be given to everybody.
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function openToEveryoneInEveryOrganisation(string $right): array;
}//end interface
