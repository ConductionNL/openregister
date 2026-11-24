<?php

/**
 * LockHandler::claim(): a job claims a lock for a named owner without a
 * session; the object is read in system context, the mapper's atomic claim
 * decides, and a winning claim is recorded like any lock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRunStep;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockAuthorization;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\LockStores;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LockHandler::claim().
 */
#[CoversClass(LockHandler::class)]
#[UsesClass(LockStores::class)]
#[UsesClass(LockRequest::class)]
#[UsesClass(LockRunStep::class)]
#[UsesClass(ObjectEntity::class)]
class LockHandlerClaimTest extends TestCase {

	private MagicMapper&MockObject $mapper;

	private AuditTrailMapper&MockObject $audit;

	private AdvisoryLockStore&MockObject $advisory;

	private LockHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper   = $this->createMock(MagicMapper::class);
		$this->audit    = $this->createMock(AuditTrailMapper::class);
		$this->advisory = $this->createMock(AdvisoryLockStore::class);
		$authorization  = $this->createMock(LockAuthorization::class);
		// A claim has no user to authorise: the person-facing checks are not asked.
		$authorization->expects($this->never())->method($this->anything());

		$this->handler = new LockHandler(
			new LockStores(magicMapper: $this->mapper, auditTrailMapper: $this->audit, advisory: $this->advisory),
			new NullLogger(),
			$authorization
		);
	}//end setUp()

	/**
	 * The object the identifier resolves to.
	 *
	 * @return ObjectEntity
	 */
	private function resolves(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$this->mapper->expects($this->once())->method('findAcrossAllSources')
			->with('u-1', false, false, false)
			->willReturn(['object' => $entity, 'register' => new Register(), 'schema' => new Schema()]);

		return $entity;
	}//end resolves()

	public function testAWinningClaimIsTakenForTheNamedOwnerAndAudited(): void {
		$entity = $this->resolves();
		$this->mapper->expects($this->once())->method('claimObjectEntity')
			->with($entity, $this->isInstanceOf(Register::class), $this->isInstanceOf(Schema::class), 'job:sync', 60, 'sync', 'run-1')
			->willReturn(true);
		$this->audit->expects($this->once())->method('createAuditTrail')
			->with($this->isInstanceOf(ObjectEntity::class), $entity, 'lock');

		$this->assertTrue($this->handler->claim(new LockRequest(identifier: 'u-1', process: 'sync', duration: 60, runStep: new LockRunStep(runUuid: 'run-1', nodeId: 'n-1')), 'job:sync'));
	}//end testAWinningClaimIsTakenForTheNamedOwnerAndAudited()

	public function testALosingClaimAnswersFalseAndRecordsNothing(): void {
		$this->resolves();
		$this->mapper->method('claimObjectEntity')->willReturn(false);
		$this->audit->expects($this->never())->method('createAuditTrail');

		$this->assertFalse($this->handler->claim(new LockRequest(identifier: 'u-1'), 'job:sync'));
	}//end testALosingClaimAnswersFalseAndRecordsNothing()

	public function testAnIdentifierWithoutAnObjectClaimsTheAdvisoryLock(): void {
		$this->mapper->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('none'));
		$request = new LockRequest(identifier: 'createApp:x');
		$this->advisory->expects($this->once())->method('claim')->with($request, 'job:sync')->willReturn(true);
		$this->mapper->expects($this->never())->method('claimObjectEntity');

		$this->assertTrue($this->handler->claim($request, 'job:sync'));
	}//end testAnIdentifierWithoutAnObjectClaimsTheAdvisoryLock()

	public function testAClaimNeedsAnOwner(): void {
		$this->mapper->expects($this->never())->method('findAcrossAllSources');

		$this->expectException(InvalidArgumentException::class);
		$this->handler->claim(new LockRequest(identifier: 'u-1'), '  ');
	}//end testAClaimNeedsAnOwner()
}//end class
