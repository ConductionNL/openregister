<?php

/**
 * Pins the audit rows the organisation lifecycle auditor writes: the action,
 * the changed fields (only those that differ), the actor taken from the
 * session, and that a failed write is logged and never thrown.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Organisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Organisation;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OrganisationLifecycleAuditorGoldenTest extends TestCase {

	/**
	 * The rows handed to the mapper.
	 *
	 * @var AuditTrail[]
	 */
	private array $rows = [];

	private LoggerInterface&MockObject $logger;

	private function auditor(?IUser $user, bool $failing = false): OrganisationLifecycleAuditor {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($failing): void {
				if ($failing === true) {
					throw new RuntimeException('db down');
				}

				foreach ($entries as $entry) {
					$this->rows[] = $entry;
				}
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new OrganisationLifecycleAuditor(mapper: $mapper, userSession: $session, logger: $this->logger);
	}//end auditor()

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn('Alice A.');
		return $user;
	}//end user()

	private function organisation(?string $status): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid('org-1');
		$organisation->setStatus($status);
		return $organisation;
	}//end organisation()

	public function testAStatusChangeIsRecordedAgainstTheSessionUser(): void {
		$this->auditor(user: $this->user())->recordStatus(organisation: $this->organisation(status: 'archived'), before: 'active');

		$this->assertCount(1, $this->rows);
		$row = $this->rows[0];
		$this->assertSame(OrganisationLifecycleAuditor::ACTION_STATUS, $row->getAction());
		$this->assertSame('org-1', $row->getObjectUuid());
		$this->assertSame('alice', $row->getUser());
		$this->assertSame('Alice A.', $row->getUserName());
		$this->assertSame(['status' => ['old' => 'active', 'new' => 'archived']], $row->getChanged());
		$this->assertNotSame('', (string)$row->getUuid());
		$this->assertNotNull($row->getCreated());
	}//end testAStatusChangeIsRecordedAgainstTheSessionUser()

	public function testAnUpdateRecordsOnlyTheFieldsThatDiffer(): void {
		$this->auditor(user: null)->recordUpdate(
			organisation: $this->organisation(status: 'active'),
			before: ['name' => 'A', 'slug' => 'a', 'quota' => 1],
			after: ['name' => 'B', 'slug' => 'a', 'quota' => '1', 'new' => 'x']
		);

		$this->assertCount(1, $this->rows);
		$row = $this->rows[0];
		$this->assertSame(OrganisationLifecycleAuditor::ACTION_UPDATED, $row->getAction());
		$this->assertSame('system', $row->getUser());
		$this->assertSame('System', $row->getUserName());
		$this->assertSame(
			[
				'name' => ['old' => 'A', 'new' => 'B'],
				'quota' => ['old' => 1, 'new' => '1'],
				'new' => ['old' => null, 'new' => 'x'],
			],
			$row->getChanged()
		);
	}//end testAnUpdateRecordsOnlyTheFieldsThatDiffer()

	public function testAFailedWriteIsLoggedAndNotThrown(): void {
		$auditor = $this->auditor(user: $this->user(), failing: true);
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('Could not record a change of an organisation'),
			$this->callback(
				static fn (array $context): bool => $context['action'] === OrganisationLifecycleAuditor::ACTION_UPDATED
					&& $context['uuid'] === 'org-1'
					&& $context['exception'] === 'db down'
			)
		);

		$auditor->recordUpdate(organisation: $this->organisation(status: 'active'), before: [], after: ['name' => 'B']);

		$this->assertSame([], $this->rows);
	}//end testAFailedWriteIsLoggedAndNotThrown()
}//end class
