<?php

/**
 * Failure-path tests for ReachRevocationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationLog;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCA\OpenRegister\Service\Rbac\ReachSources;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A revocation that cannot finish names what it could not remove, and never
 * reports a partial act as a whole one.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ReachRevocationService
 */
class ReachRevocationServiceFailureTest extends TestCase {

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * @var DerivedGrantStore&MockObject
	 */
	private DerivedGrantStore $derived;

	/**
	 * @var DelegationGrantMapperInterface&MockObject
	 */
	private DelegationGrantMapperInterface $delegations;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var AuthorizationAuditService&MockObject
	 */
	private AuthorizationAuditService $audit;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->derived     = $this->createMock(DerivedGrantStore::class);
		$this->delegations = $this->createMock(DelegationGrantMapperInterface::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->audit       = $this->createMock(AuthorizationAuditService::class);
	}//end setUp()

	/**
	 * Run a revocation of `alice` with nobody signed in.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function revoke(): array {
		$reach = $this->createMock(PrincipalReachService::class);
		$reach->method('listFor')->with('alice')->willReturn(['total' => 0, 'entries' => []]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$service = new ReachRevocationService(
			reach: $reach,
			sources: new ReachSources(schemaMapper: $this->schemas, derived: $this->derived, delegations: $this->delegations),
			log: new ReachRevocationLog(audit: $this->audit, logger: $this->logger),
			userSession: $session
		);

		return $service->revokeAll(principal: '  alice ', reason: 'left');
	}//end revoke()

	/**
	 * A live delegation held by alice.
	 *
	 * @param string $uuid The grant uuid.
	 *
	 * @return DelegationGrant
	 */
	private function delegation(string $uuid): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setUuid($uuid);
		$grant->setActingAs('bram');
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setExpiresAt(new DateTime('2999-01-01T00:00:00+00:00'));
		return $grant;
	}//end delegation()

	/**
	 * Every source failing is named in `failed`, nothing is claimed removed, and the
	 * act is attributed to `system` when nobody is signed in.
	 *
	 * @return void
	 */
	public function testEverySourceFailingIsRecordedAsFailed(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('schemas down'));
		$this->schemas->expects($this->never())->method('update');
		$this->derived->method('grantsFor')->willThrowException(new RuntimeException('derived down'));
		$this->derived->expects($this->never())->method('forget');
		$this->delegations->method('findHeldBy')->willThrowException(new RuntimeException('delegations down'));
		$this->logger->expects($this->once())->method('error')
			->with('[ReachRevocation] Could not list the schemas, so nothing was revoked from them', ['error' => 'schemas down']);
		$this->logger->expects($this->once())->method('warning');

		$record = $this->revoke();

		$this->assertSame('alice', $record['principal']);
		$this->assertSame('system', $record['revokedBy']);
		$this->assertSame([], $record['removed']);
		$this->assertSame(
			[
				['source' => 'derived', 'error' => 'derived down'],
				['source' => 'delegation', 'error' => 'delegations down'],
			],
			$record['failed']
		);
		$this->assertSame(0, $record['removedCount']);
		$this->assertSame(2, $record['failedCount']);
	}//end testEverySourceFailingIsRecordedAsFailed()

	/**
	 * A schema whose update fails is named in `failed`; untouched and empty schemas are
	 * not written; non-list values in the block survive.
	 *
	 * @return void
	 */
	public function testSchemaUpdateFailureIsRecordedPerSchema(): void {
		$empty = new Schema();
		$empty->setId(1);
		$untouched = new Schema();
		$untouched->setId(2);
		$untouched->setAuthorization(['read' => ['staff', ['group' => 'alice-fans']], 'mode' => 'strict']);
		$failing = new Schema();
		$failing->setId(3);
		$failing->setAuthorization(['read' => ['alice', 'staff', 7], 'mode' => 'strict']);
		$this->schemas->method('findAll')->willReturn([$empty, $untouched, $failing]);
		$this->schemas->expects($this->once())->method('update')->with($failing)->willThrowException(new RuntimeException('locked'));
		$this->audit->expects($this->never())->method('logSchemaAuthorizationChange');
		$this->derived->method('grantsFor')->willReturn([]);
		$this->derived->expects($this->never())->method('forget');
		$this->delegations->method('findHeldBy')->willReturn([]);

		$record = $this->revoke();

		$this->assertSame(['read' => ['staff', 7], 'mode' => 'strict'], $failing->getAuthorization());
		$this->assertSame([['source' => 'authorization', 'schema' => 3, 'error' => 'locked']], $record['failed']);
		$this->assertSame([], $record['removed']);
	}//end testSchemaUpdateFailureIsRecordedPerSchema()

	/**
	 * A failing forget and a failing delegation update are both recorded, and a
	 * delegation that does update is still revoked.
	 *
	 * @return void
	 */
	public function testRemovalFailuresAreRecordedAlongsideSuccesses(): void {
		$this->schemas->method('findAll')->willReturn([]);
		$this->derived->method('grantsFor')->willReturn([['schema' => 4]]);
		$this->derived->method('forget')->willThrowException(new RuntimeException('cache down'));
		$ok   = $this->delegation(uuid: 'd-ok');
		$fail = $this->delegation(uuid: 'd-fail');
		$this->delegations->method('findHeldBy')->willReturn([$fail, $ok]);
		$this->delegations->method('update')->willReturnCallback(
			static fn (DelegationGrant $g): DelegationGrant => $g->getUuid() === 'd-fail'
				? throw new RuntimeException('write refused')
				: $g
		);

		$record = $this->revoke();

		$this->assertSame(
			[
				['source' => 'derived', 'error' => 'cache down'],
				['source' => 'delegation', 'uuid' => 'd-fail', 'error' => 'write refused'],
			],
			$record['failed']
		);
		$this->assertSame([['source' => 'delegation', 'uuid' => 'd-ok', 'actingAs' => 'bram']], $record['removed']);
		$this->assertSame(DelegationGrant::STATUS_REVOKED, $ok->getStatus());
	}//end testRemovalFailuresAreRecordedAlongsideSuccesses()
}//end class
