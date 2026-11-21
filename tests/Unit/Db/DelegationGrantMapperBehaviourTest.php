<?php

/**
 * Behaviour test for DelegationGrantMapper and the DelegationGrant entity.
 *
 * Asserts which grants each lookup filters on, that an outstanding request only
 * matches when its scope is the same regardless of key order, which status and
 * dates make a grant live, and how a grant serialises.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class DelegationGrantMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private function mapper(array $rows): DelegationGrantMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return new DelegationGrantMapper($db);
	}

	private static function row(int $id, ?array $scope = null, string $status = 'requested'): array {
		$encoded = null;
		if ($scope !== null) {
			$encoded = json_encode($scope);
		}

		return [
			'id' => $id,
			'uuid' => 'g' . $id,
			'principal' => 'alice',
			'acting_as' => 'bob',
			'scope' => $encoded,
			'status' => $status,
		];
	}

	private static function uuids(array $grants): array {
		return array_map(static fn (DelegationGrant $g): string => $g->getUuid(), $grants);
	}

	private function utc(string $time): DateTime {
		return new DateTime($time, new DateTimeZone('UTC'));
	}

	public function testFindForFiltersOnPrincipalAndActingAsNewestFirst(): void {
		$mapper = $this->mapper([[self::row(3), self::row(2)]]);
		$this->assertSame(['g3', 'g2'], self::uuids($mapper->findFor('alice', 'bob')));
		$this->assertContains('#1 param :p1 = alice, 2', $this->queryLog);
		$this->assertContains('#1 param :p2 = bob, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(principal, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 expr.eq(acting_as, :p2, NULL)', $this->queryLog);
		$this->assertContains('#1 orderBy(id, DESC)', $this->queryLog);
	}

	public function testFindOutstandingRequestMatchesTheSameScopeInAnyKeyOrder(): void {
		$stored = ['schemas' => ['b' => 2, 'a' => 1], 'register' => 'r'];
		$asked = ['register' => 'r', 'schemas' => ['a' => 1, 'b' => 2]];
		$mapper = $this->mapper([[self::row(9, $stored)]]);

		$found = $mapper->findOutstandingRequest(self::wanted('alice', 'bob', $asked));
		$this->assertSame('g9', $found?->getUuid());
		$this->assertContains('#1 param :p3 = ["requested","pending"], 102', $this->queryLog);
		$this->assertContains('#1 setMaxResults(1)', $this->queryLog);
	}

	public function testFindOutstandingRequestIgnoresADifferentScope(): void {
		$mapper = $this->mapper([[self::row(9, ['register' => 'r'])]]);
		$this->assertNull($mapper->findOutstandingRequest(self::wanted('alice', 'bob', ['register' => 'other'])));
	}

	public function testFindOutstandingRequestTreatsAMissingScopeAsEmpty(): void {
		$mapper = $this->mapper([[self::row(4)]]);
		$this->assertSame('g4', $mapper->findOutstandingRequest(self::wanted('alice', 'bob', []))?->getUuid());
	}

	public function testFindOutstandingRequestReturnsNullWhenNothingIsOpen(): void {
		$mapper = $this->mapper([[]]);
		$this->assertNull($mapper->findOutstandingRequest(self::wanted('alice', 'bob', [])));
	}

	public function testFindAwaitingAnswerByOnlyAsksForOpenStatuses(): void {
		$mapper = $this->mapper([[self::row(5)]]);
		$this->assertSame(['g5'], self::uuids($mapper->findAwaitingAnswerBy('bob')));
		$this->assertContains('#1 param :p1 = bob, 2', $this->queryLog);
		$this->assertContains('#1 expr.eq(acting_as, :p1, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p2 = ["requested","pending"], 102', $this->queryLog);
		$this->assertContains('#1 expr.in(status, :p2, NULL)', $this->queryLog);
	}

	public function testFindGrantsOverFiltersOnlyOnActingAs(): void {
		$mapper = $this->mapper([[self::row(6, null, 'granted')]]);
		$this->assertSame(['g6'], self::uuids($mapper->findGrantsOver('bob')));
		$this->assertContains('#1 expr.eq(acting_as, :p1, NULL)', $this->queryLog);
		$this->assertSame(1, $this->parameterCount);
	}

	public function testFindHeldByFiltersOnlyOnPrincipal(): void {
		$mapper = $this->mapper([[self::row(7), self::row(1)]]);
		$this->assertSame(['g7', 'g1'], self::uuids($mapper->findHeldBy('alice')));
		$this->assertContains('#1 expr.eq(principal, :p1, NULL)', $this->queryLog);
		$this->assertSame(1, $this->parameterCount);
	}

	public function testFindByUuidReturnsTheSingleMatch(): void {
		$mapper = $this->mapper([[self::row(8)]]);
		$this->assertSame(8, $mapper->findByUuid('g8')->getId());
		$this->assertContains('#1 expr.eq(uuid, :p1, NULL)', $this->queryLog);
	}

	public function testFindByUuidThrowsWhenAbsent(): void {
		$mapper = $this->mapper([[]]);
		$this->expectException(DoesNotExistException::class);
		$mapper->findByUuid('nope');
	}

	public function testFindByUuidThrowsOnDuplicates(): void {
		$mapper = $this->mapper([[self::row(8), self::row(8)]]);
		$this->expectException(MultipleObjectsReturnedException::class);
		$mapper->findByUuid('g8');
	}

	public function testOnlyAGrantedGrantIsLive(): void {
		$grant = new DelegationGrant();
		$grant->setStatus(DelegationGrant::STATUS_PENDING);
		$this->assertFalse($grant->isLiveAt($this->utc('2026-01-01')));

		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$this->assertTrue($grant->isLiveAt($this->utc('2026-01-01')));
	}

	public function testARevokedGrantIsNotLive(): void {
		$grant = new DelegationGrant();
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setRevokedAt($this->utc('2025-12-01'));
		$this->assertFalse($grant->isLiveAt($this->utc('2026-01-01')));
	}

	public function testAGrantIsLiveUntilTheMomentItExpires(): void {
		$grant = new DelegationGrant();
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setExpiresAt($this->utc('2026-01-01 12:00:00'));

		$this->assertTrue($grant->isLiveAt($this->utc('2026-01-01 11:59:59')));
		$this->assertFalse($grant->isLiveAt($this->utc('2026-01-01 12:00:00')));
	}

	public function testAGrantSerialisesEveryFieldWithIsoDates(): void {
		$grant = new DelegationGrant();
		$grant->setId(3);
		$grant->setUuid('u-3');
		$grant->setPrincipal('alice');
		$grant->setActingAs('bob');
		$grant->setScope(['register' => 'r']);
		$grant->setStatus('granted');
		$grant->setGrantedBy('bob');
		$grant->setReason('care');
		$grant->setOrganisation('org-1');
		$grant->setRequestedAt($this->utc('2026-01-01 08:00:00'));
		$grant->setAnsweredAt($this->utc('2026-01-02 08:00:00'));
		$grant->setExpiresAt($this->utc('2026-02-01 08:00:00'));
		$grant->setRevokedAt($this->utc('2026-01-15 08:00:00'));

		$this->assertSame(
			[
				'id' => 3,
				'uuid' => 'u-3',
				'principal' => 'alice',
				'actingAs' => 'bob',
				'scope' => ['register' => 'r'],
				'status' => 'granted',
				'grantedBy' => 'bob',
				'reason' => 'care',
				'organisation' => 'org-1',
				'requestedAt' => '2026-01-01T08:00:00+00:00',
				'answeredAt' => '2026-01-02T08:00:00+00:00',
				'expiresAt' => '2026-02-01T08:00:00+00:00',
				'revokedAt' => '2026-01-15T08:00:00+00:00',
			],
			$grant->jsonSerialize()
		);
	}

	public function testAnEmptyGrantSerialisesAnEmptyScopeAndNullDates(): void {
		$json = (new DelegationGrant())->jsonSerialize();
		$this->assertSame([], $json['scope']);
		$this->assertNull($json['requestedAt']);
		$this->assertNull($json['revokedAt']);
	}

	private static function wanted(string $principal, string $actingAs, array $scope): DelegationGrant {
		$wanted = new DelegationGrant();
		$wanted->setPrincipal($principal);
		$wanted->setActingAs($actingAs);
		$wanted->setScope($scope);
		return $wanted;
	}
}
