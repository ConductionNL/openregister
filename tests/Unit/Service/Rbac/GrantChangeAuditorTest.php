<?php

/**
 * Who widened or narrowed access to a record is on the audit trail.
 *
 * The auditor is checked on its own, then through the three writers that call
 * it: a holder's grant and revoke, a change of the owning group, and a change
 * of the list that holds the special right.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\SettingsChangeAuditor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\GrantChangeAuditor
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class GrantChangeAuditorTest extends TestCase {

	private AuditTrailMapper&MockObject $mapper;

	private SettingsChangeAuditor&MockObject $settings;

	private LoggerInterface&MockObject $logger;

	private GrantChangeAuditor $auditor;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(AuditTrailMapper::class);
		$this->settings = $this->createMock(SettingsChangeAuditor::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->auditor = new GrantChangeAuditor($this->mapper, $this->settings, $this->logger);
	}//end setUp()

	/**
	 * A record.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('record-1');

		return $object;
	}//end record()

	public function testAGrantIsOneTypedEntryNamingThePrincipalAndHowTheCallerWasAdmitted(): void {
		$record = $this->record();
		$this->mapper->expects($this->once())->method('createAuditTrailEntry')->with(
			$record,
			GrantChangeAuditor::ACTION_GRANTED,
			[
				'grant' => 'ocinternal:9',
				'type' => 'user',
				'sharedWith' => 'reviewer',
				'permissions' => 1,
				'verbs' => ['use'],
				'admittedAs' => GrantManageGuard::AS_HOLDER,
			]
		);

		$this->auditor->recordGrant(
			object: $record,
			grant: ['id' => 'ocinternal:9', 'type' => 'user', 'sharedWith' => 'reviewer', 'permissions' => 1, 'verbs' => ['use']],
			admittedAs: GrantManageGuard::AS_HOLDER
		);
	}//end testAGrantIsOneTypedEntryNamingThePrincipalAndHowTheCallerWasAdmitted()

	public function testARevokeIsItsOwnAction(): void {
		$this->mapper->expects($this->once())->method('createAuditTrailEntry')->with(
			$this->anything(),
			GrantChangeAuditor::ACTION_REVOKED,
			$this->callback(static fn (array $changed): bool => $changed['sharedWith'] === 'reviewer' && $changed['admittedAs'] === GrantManageGuard::AS_OWNER)
		);

		$this->auditor->recordRevoke(
			object: $this->record(),
			grant: ['id' => 'ocinternal:9', 'type' => 'user', 'sharedWith' => 'reviewer', 'permissions' => 1],
			admittedAs: GrantManageGuard::AS_OWNER
		);
	}//end testARevokeIsItsOwnAction()

	public function testAChangeOfTheOwningGroupNamesBothSides(): void {
		$this->mapper->expects($this->once())->method('createAuditTrailEntry')->with(
			$this->anything(),
			GrantChangeAuditor::ACTION_OWNER_GROUP,
			['ownerGroup' => ['old' => null, 'new' => 'supervisors']]
		);

		$this->auditor->recordOwnerGroup(object: $this->record(), before: null, after: 'supervisors');
	}//end testAChangeOfTheOwningGroupNamesBothSides()

	public function testNamingTheSameOwningGroupAgainIsNoChange(): void {
		$this->mapper->expects($this->never())->method('createAuditTrailEntry');

		$this->auditor->recordOwnerGroup(object: $this->record(), before: 'supervisors', after: 'supervisors');
	}//end testNamingTheSameOwningGroupAgainIsNoChange()

	public function testAChangeOfTheSpecialRightListIsASettingsChangeOnTheSameChain(): void {
		$this->settings->expects($this->once())->method('recordUpdate')->with(
			'openregister',
			['organisation org-1: grant_manage' => ['records-service']],
			['organisation org-1: grant_manage' => ['records-service', 'second-service']]
		);

		$this->auditor->recordSpecialRight(
			organisationUuid: 'org-1',
			before: ['records-service'],
			after: ['records-service', 'second-service']
		);
	}//end testAChangeOfTheSpecialRightListIsASettingsChangeOnTheSameChain()

	public function testAnEntryThatCannotBeWrittenIsLoggedAndDoesNotFailTheGrant(): void {
		$this->mapper->method('createAuditTrailEntry')->willThrowException(new \RuntimeException('chain unavailable'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('Could not record'), $this->anything());

		$this->auditor->recordGrant(object: $this->record(), grant: ['id' => 'x'], admittedAs: null);
	}//end testAnEntryThatCannotBeWrittenIsLoggedAndDoesNotFailTheGrant()
}//end class
