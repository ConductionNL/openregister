<?php

/**
 * Reads and writes delegation grants.
 *
 * 🔴 WHY THIS IS A MAPPER AND NOT AN OPENREGISTER OBJECT
 *
 * The obvious home for a grant is a register + schema, and it is wrong here. A
 * grant stored as an OR object is governed by the RBAC it exists to decide:
 * resolving a delegation would require an acting subject, and resolving the
 * acting subject would require the delegation. That loop has only two exits, and
 * both are worse than a table:
 *
 *  - Elevate to a trusted userless principal for every grant read. That puts the
 *    single most security-critical read in the app behind exactly the escape
 *    hatch ADR-099 rule 9 forbids on request paths, and makes "check a grant" a
 *    standing reason to reach for it.
 *  - Special-case the grant schema inside the RBAC evaluator. A carve-out in the
 *    thing being carved out of.
 *
 * So the authoritative read is here, on a plain table, outside object-level
 * access control. The record may later be PROJECTED into a register for
 * administration; the projection must never become the source of truth.
 *
 * WHAT THIS MAPPER DELIBERATELY DOES NOT DO
 *
 * It does not decide whether a grant permits anything. That question needs the
 * current time, and a lookup that consults the clock internally cannot be tested
 * at the boundary that matters — see {@see DelegationGrant::isLiveAt()}. This
 * class finds candidates; the entity decides liveness; the caller supplies the
 * moment.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finds delegation grants. Never judges them.
 *
 * @template-extends QBMapper<DelegationGrant>
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationGrantMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_delegation_grants', entityClass: DelegationGrant::class);

	}//end __construct()

	/**
	 * Every grant a principal holds over a given identity, newest first.
	 *
	 * Returns CANDIDATES, in every status. Filtering to the live ones is the
	 * caller's job with a clock in hand, because "expired" and "revoked" and
	 * "denied" are different answers a caller may need to report differently —
	 * collapsing them here would leave the caller with only "no".
	 *
	 * @param string $principal The uid that would act.
	 * @param string $actingAs  The uid whose rights would be used.
	 *
	 * @return array<int, DelegationGrant> The candidate grants.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findFor(string $principal, string $actingAs): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('principal', $qb->createNamedParameter($principal, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('acting_as', $qb->createNamedParameter($actingAs, IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findFor()

	/**
	 * An outstanding request for this exact delegation, if one exists.
	 *
	 * 🔴 The dedup key is (principal, actingAs, scope) and NOT the unit of work
	 * that needed it. Keyed per run, a backlog of two hundred queued runs needing
	 * one grant sends two hundred notifications — which does not merely annoy the
	 * recipient, it trains them to dismiss the prompt, and a prompt that gets
	 * dismissed by reflex is a consent control that has stopped working.
	 *
	 * Keyed on the delegation itself, one request represents the whole backlog and
	 * one answer drains it.
	 *
	 * @param string $principal The uid that would act.
	 * @param string $actingAs  The uid whose rights would be used.
	 * @param array  $scope     The scope being asked for.
	 *
	 * @return DelegationGrant|null The outstanding request, or null.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function findOutstandingRequest(string $principal, string $actingAs, array $scope): ?DelegationGrant {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('principal', $qb->createNamedParameter($principal, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('acting_as', $qb->createNamedParameter($actingAs, IQueryBuilder::PARAM_STR)))
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(
						[DelegationGrant::STATUS_REQUESTED, DelegationGrant::STATUS_PENDING],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				)
			)
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		foreach ($this->findEntities(query: $qb) as $candidate) {
			if ($this->sameScope(a: ($candidate->getScope() ?? []), b: $scope) === true) {
				return $candidate;
			}
		}

		return null;
	}//end findOutstandingRequest()

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
	public function findAwaitingAnswerBy(string $actingAs): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('acting_as', $qb->createNamedParameter($actingAs, IQueryBuilder::PARAM_STR)))
			->andWhere(
				$qb->expr()->in(
					'status',
					$qb->createNamedParameter(
						[DelegationGrant::STATUS_REQUESTED, DelegationGrant::STATUS_PENDING],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				)
			)
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findAwaitingAnswerBy()

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
	public function findGrantsOver(string $actingAs): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('acting_as', $qb->createNamedParameter($actingAs, IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findGrantsOver()

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
	public function findByUuid(string $uuid): DelegationGrant {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid, IQueryBuilder::PARAM_STR)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

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
	public function findHeldBy(string $principal): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('principal', $qb->createNamedParameter($principal, IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findHeldBy()

	/**
	 * Whether two scopes are the same delegation.
	 *
	 * Order-insensitive, because a scope is a set and two requests that differ
	 * only in key order are the same request — treating them as different would
	 * defeat the dedup and reintroduce the notification storm it prevents.
	 *
	 * @param array $a One scope.
	 * @param array $b The other.
	 *
	 * @return boolean Whether they describe the same delegation.
	 */
	private function sameScope(array $a, array $b): bool {
		$normalise = static function (array $scope) use (&$normalise): array {
			ksort($scope);
			foreach ($scope as $key => $value) {
				if (is_array($value) === true) {
					$scope[$key] = $normalise($value);
				}
			}

			return $scope;
		};

		return $normalise($a) === $normalise($b);
	}//end sameScope()
}//end class
