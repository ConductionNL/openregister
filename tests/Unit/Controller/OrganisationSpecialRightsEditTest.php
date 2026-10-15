<?php

/**
 * Who may change an organisation's special rights.
 *
 * Decision 239: every special right, not only `grant_manage`, is changed by an
 * administrator or the organisation's owner. A member keeps editing the rest
 * of the organisation. `PUT /api/organisations/{uuid}` is an ordinary
 * Controller, so the 403 asserted here is the status the caller gets
 * (OCSMiddleware only rewrites an OCSController's status).
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
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Controller\OrganisationController
 * @uses   \OCA\OpenRegister\Db\Organisation
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */
class OrganisationSpecialRightsEditTest extends TestCase {

	private IRequest&MockObject $request;

	private OrganisationService&MockObject $organisationService;

	private OrganisationMapper&MockObject $organisationMapper;

	private Organisation $organisation;

	private OrganisationController $controller;

	/**
	 * The stored block every test starts from, with the retired `object_publish`
	 * and `dashboard_view` still in it, as an organisation saved before
	 * decisions 247 and 248 has.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = [
		'object' => ['create' => [], 'read' => ['staff'], 'update' => [], 'delete' => []],
		'object_publish' => ['*'],
		'agent_use' => ['admin', '*'],
		'dashboard_view' => ['*'],
		'llm_use' => ['ai-users'],
		'grant_manage' => [],
	];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->organisationService->method('hasAccessToOrganisation')->willReturn(true);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);

		$this->organisation = new Organisation();
		$this->organisation->setUuid('org-1');
		$this->organisation->setName('Gemeente');
		$this->organisation->setOwner('owner');
		$this->organisation->setAuthorization(self::STORED);
		$this->organisationMapper->method('findByUuid')->willReturn($this->organisation);
		$this->organisationMapper->method('update')->willReturnArgument(0);

		$this->controller = new OrganisationController(
			'openregister',
			$this->request,
			$this->organisationService,
			$this->organisationMapper,
			new NullLogger(),
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);
	}//end setUp()

	/**
	 * The stored block with one key replaced.
	 *
	 * @param string $key The key.
	 * @param mixed $value The new value.
	 *
	 * @return array<string, mixed> The block.
	 */
	private static function storedWith(string $key, mixed $value): array {
		$block = self::STORED;
		$block[$key] = $value;

		return $block;
	}//end storedWith()

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function changes(): array {
		$withoutLlm = self::STORED;
		unset($withoutLlm['llm_use']);

		return [
			'narrowing agent_use' => [self::storedWith('agent_use', ['admin']), 'agent_use'],
			'opening llm_use to everyone' => [self::storedWith('llm_use', ['ai-users', '*']), 'llm_use'],
			'leaving llm_use out, which reopens it' => [$withoutLlm, 'llm_use'],
			'giving grant_manage' => [self::storedWith('grant_manage', ['my-team']), 'grant_manage'],
		];
	}//end changes()

	/**
	 * @dataProvider changes
	 *
	 * @param array<string, mixed> $authorization The block a member sends.
	 * @param string $right The right it changes.
	 */
	public function testAMemberIsRefusedWith403(array $authorization, string $right): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->request->method('getParams')->willReturn(['authorization' => $authorization]);
		$this->organisationMapper->expects($this->never())->method('update');

		$response = $this->controller->update('org-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([$right], $response->getData()['rights']);
		$this->assertSame(self::STORED, $this->organisation->getAuthorization());
	}//end testAMemberIsRefusedWith403()

	/**
	 * @dataProvider changes
	 *
	 * @param array<string, mixed> $authorization The block the owner or an administrator sends.
	 */
	public function testTheOwnerOrAnAdministratorChangesIt(array $authorization): void {
		$this->organisationService->method('isOrganisationAdmin')->with('org-1')->willReturn(true);
		$this->request->method('getParams')->willReturn(['authorization' => $authorization]);

		$response = $this->controller->update('org-1');

		$this->assertNotSame(403, $response->getStatus());
		$this->assertSame($authorization, $this->organisation->getAuthorization());
	}//end testTheOwnerOrAnAdministratorChangesIt()

	public function testAMemberStillEditsTheCrudListsAndTheName(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$block = self::storedWith('object', ['create' => ['staff'], 'read' => ['staff'], 'update' => [], 'delete' => []]);
		$this->request->method('getParams')->willReturn(['name' => 'Gemeente Zuid', 'authorization' => $block]);

		$response = $this->controller->update('org-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['staff'], $this->organisation->getAuthorization()['object']['create']);
		$this->assertSame('Gemeente Zuid', $this->organisation->getName());
	}//end testAMemberStillEditsTheCrudListsAndTheName()

	public function testTheRetiredRightsAreNotSpecialRightsAnyMore(): void {
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$block = self::STORED;
		unset($block['object_publish'], $block['dashboard_view']);
		$this->request->method('getParams')->willReturn(['authorization' => $block]);

		$response = $this->controller->update('org-1');

		$this->assertSame(200, $response->getStatus());
	}//end testTheRetiredRightsAreNotSpecialRightsAnyMore()

	public function testLeavingOutAKeyTheOrganisationNeverSetIsNoChange(): void {
		$this->organisation->setAuthorization(['object' => ['read' => ['staff']]]);
		$this->organisationService->method('isOrganisationAdmin')->willReturn(false);
		$this->request->method('getParams')->willReturn(
			['authorization' => ['object' => ['read' => ['staff', 'guests']], 'agent_use' => ['*']]]
		);

		$response = $this->controller->update('org-1');

		$this->assertSame(200, $response->getStatus());
	}//end testLeavingOutAKeyTheOrganisationNeverSetIsNoChange()

	public function testTheEndpointIsAPlainControllerSoThe403ReachesTheCaller(): void {
		$this->assertTrue(is_subclass_of(OrganisationController::class, Controller::class));
		$this->assertFalse(is_subclass_of(OrganisationController::class, OCSController::class));
	}//end testTheEndpointIsAPlainControllerSoThe403ReachesTheCaller()
}//end class
