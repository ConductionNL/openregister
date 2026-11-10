<?php

/**
 * ObjectOwnershipServiceInterface: the part of ObjectOwnershipService that the object ownership controller uses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * The methods of ObjectOwnershipService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ObjectOwnershipServiceInterface {

	/**
	 * Take ownership of one record, as the acting user.
	 *
	 * THE NEW OWNER IS THE ACTING USER AND NOTHING ELSE. There is no parameter
	 * for it, which is the point: a method that accepted one would be a way to
	 * hand a record to somebody who never asked for it, and a way for a caller to
	 * put its own name on a record it may not edit.
	 *
	 * @param OwnedRecord $record  The record, its register and schema.
	 * @param bool        $cascade Whether the change carries to the record's children.
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the rules refuse this caller the edit.
	 *
	 * @return array The outcome, as {@see transfer()} describes it.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The cascade is a property of the
	 * request, not a second behaviour: one record or its family, same transfer.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function claim(OwnedRecord $record, bool $cascade = false): array;

	/**
	 * Name the group that owns one record, or take the owning group away.
	 *
	 * Kept for the apps that call it by class name; setOwnerGroupFor takes the record.
	 *
	 * @param Register     $register The record's register.
	 * @param Schema       $schema   The record's schema.
	 * @param ObjectEntity $object   The record.
	 * @param string|null  $group    The group id, or null to remove the owning group.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor administrator.
	 * @throws InvalidArgumentException When no such group exists.
	 *
	 * @return array The stored authorization block.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function setOwnerGroup(Register $register, Schema $schema, ObjectEntity $object, ?string $group): array;

	/**
	 * Name the group that owns one record, or take the owning group away.
	 *
	 * Owner or administrator, through the same resolver the read side uses, so
	 * "who may name the owning group" and "who is admitted unconditionally"
	 * cannot drift apart.
	 *
	 * @param OwnedRecord $record The record, its register and schema.
	 * @param string|null $group  The group id, or null to remove the owning group.
	 *
	 * @throws NotAuthorizedException When the caller is neither owner nor administrator.
	 * @throws InvalidArgumentException When no such group exists.
	 *
	 * @return array The stored authorization block.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function setOwnerGroupFor(OwnedRecord $record, ?string $group): array;

	/**
	 * Assign one record to a named owner.
	 *
	 * Administrators only. An ordinary caller may take a record
	 * ({@see claim()}) and may not give one away: handing a colleague a record
	 * they have not accepted makes them answerable for something they may never
	 * see, and it would let anybody park a record on somebody else's name.
	 *
	 * @param OwnedRecord       $record   The record, its register and schema.
	 * @param OwnershipHandover $handover The uid of the new owner and whether the change carries to the record's children.
	 *
	 * @throws NotAuthorizedException When the caller is not an administrator.
	 * @throws InvalidArgumentException When no such user exists.
	 *
	 * @return array The outcome, as {@see transfer()} describes it.
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function assign(OwnedRecord $record, OwnershipHandover $handover): array;

	/**
	 * Reassign many records to one owner, in one action.
	 *
	 * ONE AUDIT ENTRY PER RECORD, never one for the batch. A single entry for
	 * fifty records cannot be found from any of them: an auditor reading one
	 * record's history would see it change hands with nothing saying so.
	 *
	 * ONE REFUSAL DOES NOT LOSE THE REST. A record that cannot be resolved, or
	 * whose write fails, is reported in `failed` and the remaining records are
	 * still moved — a bulk reassignment that stops halfway leaves an
	 * administrator with no way to know how far it got.
	 *
	 * @param string[] $identifiers The records, by uuid, id, slug or uri.
	 * @param string $newOwner The uid of the new owner.
	 * @param bool $cascade Whether the change carries to each record's children.
	 *
	 * @throws NotAuthorizedException When the caller is not an administrator.
	 * @throws InvalidArgumentException When no such user exists, or no record was named.
	 *
	 * @return array{newOwner: string, transferred: array, failed: array, unchanged: array}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) As on claim().
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function reassignMany(array $identifiers, string $newOwner, bool $cascade = false): array;

	/**
	 * Reassign many records to one owner, as reassignMany() does, for a reassignment already built.
	 *
	 * @param string[]              $identifiers  The records, by uuid, id, slug or uri.
	 * @param OwnershipReassignment $reassignment The new owner and whether the change carries to each record's children.
	 *
	 * @throws NotAuthorizedException When the caller is not an administrator.
	 * @throws InvalidArgumentException When no such user exists, or no record was named.
	 *
	 * @return array{newOwner: string, transferred: array, failed: array, unchanged: array}
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	public function reassign(array $identifiers, OwnershipReassignment $reassignment): array;
}//end interface
