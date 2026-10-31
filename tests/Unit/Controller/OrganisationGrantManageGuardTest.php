<?php

/**
 * Who may change the special right `grant_manage` on an organisation.
 *
 * Only an administrator or the organisation's owner may change an organisation
 * at all, so a member cannot touch the list of who manages the grants on its
 * records: a member who could add their own group to it would decide who reads
 * every record. The owner edits the rest of the block as before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
class OrganisationGrantManageGuardTest extends TestCase {

	private IRequest&MockObject $request;

	private OrganisationService&MockObject $organisationService;

	private OrganisationMapper&MockObject $organisationMapper;

	private Organisation $organisation;

	private OrganisationController $controller;

	private GrantChangeAuditor&MockObject $auditor;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);

		$this->organisation = new Organisation();
		$this->organisation->setUuid('org-1');
		$this->organisation->setName('Gemeente');
		$this->organisation->setAuthorization(['grant_manage' => ['records-service'], 'object_publish' => []]);
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation);
		$this->organisationMapper->method('save')->willReturnArgument(0);
		$this->organisationMapper->method('update')->willReturnArgument(0);
		$this->auditor = $this->createMock(GrantChangeAuditor::class);

		$this->controller = new OrganisationController(
			'openregister',
			$this->request,
			$this->organisationService,
			$this->organisationMapper,
			new NullLogger(),
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(originalClassName: IGroupManager::class),
			$this->createMock(originalClassName: \OCA\OpenRegister\Service\OrganisationUsageService::class),
			$this->auditor
		);
	}//end setUp()

	/**
	 * @dataProvider changes
	 *
	 * @param array<string, mixed> $authorization The authorization block a member sends.
	 */
	public function testAMemberMayNotChangeWhoHoldsTheRight(array $authorization): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->request->method('getParams')->willReturn(['authorization' => $authorization]);
		$this->organisationMapper->expects($this->never())->method('save');
		$this->organisationMapper->expects($this->never())->method('update');

		$response = $this->controller->update('org-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['records-service'], $this->organisation->getAuthorization()['grant_manage']);
	}//end testAMemberMayNotChangeWhoHoldsTheRight()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function changes(): array {
		return [
			'adding their own group' => [['grant_manage' => ['records-service', 'my-team']]],
			'taking it away' => [['grant_manage' => []]],
			'leaving the key out, which would drop it' => [['object_publish' => ['editors']]],
			'widening a narrowed entry' => [['grant_manage' => [['group' => 'records-service']]]],
		];
	}//end changes()

	public function testTheOwnerStillEditsTheRestOfTheBlock(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['authorization' => ['grant_manage' => ['records-service'], 'object_publish' => ['editors']]]
		);

		$response = $this->controller->update('org-1');

		$this->assertNotSame(403, $response->getStatus());
		$this->assertSame(['editors'], $this->organisation->getAuthorization()['object_publish']);
	}//end testTheOwnerStillEditsTheRestOfTheBlock()

	public function testAChangeOfWhoHoldsTheRightIsRecordedOnTheTrail(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['authorization' => ['grant_manage' => ['records-service', 'second-service']]]
		);
		$this->auditor->expects($this->once())->method('recordSpecialRight')->with(
			'org-1',
			['records-service'],
			['records-service', 'second-service']
		);

		$this->controller->update('org-1');
	}//end testAChangeOfWhoHoldsTheRightIsRecordedOnTheTrail()

	public function testASaveThatLeavesTheListAloneRecordsNothing(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(true);
		$this->request->method('getParams')->willReturn(['name' => 'Gemeente Utrecht']);
		$this->auditor->expects($this->never())->method('recordSpecialRight');

		$this->controller->update('org-1');
	}//end testASaveThatLeavesTheListAloneRecordsNothing()

	public function testARefusedChangeRecordsNothing(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->request->method('getParams')->willReturn(['authorization' => ['grant_manage' => []]]);
		$this->auditor->expects($this->never())->method('recordSpecialRight');

		$this->assertSame(403, $this->controller->update('org-1')->getStatus());
	}//end testARefusedChangeRecordsNothing()

	public function testTheOwnerOrAnAdministratorChangesWhoHoldsTheRight(): void {
		$this->organisationService->method('isOrganisationAdmin')->with('org-1')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['authorization' => ['grant_manage' => ['records-service', 'second-service']]]
		);

		$response = $this->controller->update('org-1');

		$this->assertNotSame(403, $response->getStatus());
		$this->assertSame(
			['records-service', 'second-service'],
			$this->organisation->getAuthorization()['grant_manage']
		);
	}//end testTheOwnerOrAnAdministratorChangesWhoHoldsTheRight()
}//end class
