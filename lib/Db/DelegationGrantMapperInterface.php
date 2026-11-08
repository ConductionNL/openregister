<?php

/**
 * DelegationGrantMapperInterface: what callers outside the component use of DelegationGrantMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The delegation grant lookups the delegation endpoints use.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DelegationGrantMapperInterface {

	/**
	 * Grants awaiting an answer from a given user.
	 *
	 * The consent inbox. A user may answer only for their OWN identity, so this
	 * queries `acting_as` rather than `principal`.
	 *
	 * @param string $actingAs The uid being asked to consent.
	 *
	 * @return array<int, DelegationGrant> The pending requests.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findAwaitingAnswerBy(string $actingAs): array;

	/**
	 * One grant, by its uuid.
	 *
	 * The uuid rather than the numeric id, deliberately: the answer and revoke
	 * endpoints take this value from a URL, and a sequential id there invites
	 * walking the store — a person who may answer request 41 learns that 40 and
	 * 42 exist and can probe them. The authorization check stops the probe from
	 * succeeding; an opaque identifier stops it from being informative.
	 *
	 * @param string $uuid The grant's uuid.
	 *
	 * @return DelegationGrant The grant.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such grant exists.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When the uuid is not unique.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findByUuid(string $uuid): DelegationGrant;

	/**
	 * Everyone permitted to act as a given user.
	 *
	 * The question the whole store exists to answer. An administrator asking "who
	 * can act as the mayor?" must get a complete list from one query — if that
	 * needs correlating logs, the delegation is not auditable and an unauditable
	 * delegation is indistinguishable from an unauthorized one.
	 *
	 * @param string $actingAs The uid being acted as.
	 *
	 * @return array<int, DelegationGrant> Every grant over that identity.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findGrantsOver(string $actingAs): array;

	/**
	 * Every grant a given principal holds or has asked for.
	 *
	 * The other side of {@see self::findGrantsOver()}: "what may I do on whose
	 * behalf", asked by the principal rather than about the identity.
	 *
	 * @param string $principal The uid that would act.
	 *
	 * @return array<int, DelegationGrant> Every grant held by that principal.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findHeldBy(string $principal): array;
}//end interface
