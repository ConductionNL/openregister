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
}//end interface
