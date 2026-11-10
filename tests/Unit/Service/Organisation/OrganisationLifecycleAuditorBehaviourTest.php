<?php

/**
 * Behaviour tests for OrganisationLifecycleAuditor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Organisation
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

namespace OCA\OpenRegister\Tests\Unit\Service\Organisation;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * No-op changes write nothing, and a change without a session is attributed to the system.
 *
 * @covers \OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor
 */
class OrganisationLifecycleAuditorBehaviourTest extends TestCase {

	/**
	 * The auditor with nobody signed in, and the mapper it writes to.
	 *
	 * @param AuditTrailMapperInterface $mapper The trail.
	 *
	 * @return OrganisationLifecycleAuditor
	 */
	private function auditor(AuditTrailMapperInterface $mapper): OrganisationLifecycleAuditor {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new OrganisationLifecycleAuditor(mapper: $mapper, userSession: $session, logger: $this->createMock(LoggerInterface::class));
	}//end auditor()

	/**
	 * An organisation in a status.
	 *
	 * @param string|null $status The status.
	 *
	 * @return Organisation
	 */
	private function organisation(?string $status): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setStatus($status);
		return $organisation;
	}//end organisation()

	/**
	 * A status that did not move, and an edit that changed nothing, write nothing.
	 *
	 * @return void
	 */
	public function testNoOpChangesWriteNothing(): void {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->expects($this->never())->method('insertAuditTrails');
		$auditor = $this->auditor(mapper: $mapper);

		$auditor->recordStatus(organisation: $this->organisation(status: 'active'), before: 'active');
		$auditor->recordStatus(organisation: $this->organisation(status: null), before: '');
		$auditor->recordUpdate(organisation: $this->organisation(status: 'active'), before: ['name' => 'A', 'extra' => 1], after: ['name' => 'A']);
	}//end testNoOpChangesWriteNothing()

	/**
	 * A change without a session is written as the system, with only the moved fields.
	 *
	 * @return void
	 */
	public function testChangeWithoutSessionIsAttributedToTheSystem(): void {
		$written = [];
		$mapper  = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			static function (array $entries) use (&$written): int {
				$written = array_merge($written, $entries);
				return count($entries);
			}
		);
		$auditor = $this->auditor(mapper: $mapper);

		$auditor->recordUpdate(
			organisation: $this->organisation(status: 'active'),
			before: ['name' => 'A', 'quota' => 5],
			after: ['name' => 'B', 'quota' => 5, 'slug' => 'b']
		);
		$auditor->recordStatus(organisation: $this->organisation(status: 'suspended'), before: 'active');

		$this->assertCount(2, $written);
		/** @var AuditTrail $update */
		$update = $written[0];
		$this->assertSame('organisation.updated', $update->getAction());
		$this->assertSame('system', $update->getUser());
		$this->assertSame('System', $update->getUserName());
		$this->assertSame('org-1', $update->getObjectUuid());
		$this->assertSame(['name' => ['old' => 'A', 'new' => 'B'], 'slug' => ['old' => null, 'new' => 'b']], $update->getChanged());
		$this->assertSame(['status' => ['old' => 'active', 'new' => 'suspended']], $written[1]->getChanged());
		$this->assertSame('organisation.status_changed', $written[1]->getAction());
	}//end testChangeWithoutSessionIsAttributedToTheSystem()
}//end class
