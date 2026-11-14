<?php

/**
 * Unit tests for CredentialOrganisationController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CredentialOrganisationController;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint lists the organisations the caller may hold credentials for,
 * flags the caller's active one, returns only uuid/name/active, and refuses an
 * anonymous caller with 401.
 *
 * @covers \OCA\OpenRegister\Controller\CredentialOrganisationController
 *
 * @uses \OCA\OpenRegister\Db\Organisation
 *
 * @spec openspec/changes/broker-acts-for-an-organisation-member/specs/credential-broker/spec.md#requirement-an-organisation-credential-names-and-lets-the-admin-choose-its-organisation
 */
class CredentialOrganisationControllerTest extends TestCase {

	/** @var OrganisationServiceInterface&MockObject */
	private OrganisationServiceInterface $organisationService;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	protected function setUp(): void {
		$this->organisationService = $this->createMock(OrganisationServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	private function controller(): CredentialOrganisationController {
		return new CredentialOrganisationController(
			'openregister',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->organisationService
		);
	}//end controller()

	private function organisation(string $uuid, string $name): Organisation {
		$organisation = new Organisation();
		$organisation->setUuid($uuid);
		$organisation->setName($name);

		return $organisation;
	}//end organisation()

	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedInAs()

	public function testAnAnonymousCallerIsUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->organisationService->expects($this->never())->method('getManageableOrganisations');

		$response = $this->controller()->index();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testAnAnonymousCallerIsUnauthorized()

	public function testTheManageableOrganisationsAreReturnedWithTheActiveOneFlagged(): void {
		$this->signedInAs('alice');
		$this->organisationService->method('getActiveOrganisation')->willReturn($this->organisation('u-2', 'Two'));
		$this->organisationService->expects($this->once())
			->method('getManageableOrganisations')
			->with('alice')
			->willReturn([$this->organisation('u-1', 'One'), $this->organisation('u-2', 'Two')]);

		$response = $this->controller()->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'results' => [
					['uuid' => 'u-1', 'name' => 'One', 'active' => false],
					['uuid' => 'u-2', 'name' => 'Two', 'active' => true],
				],
			],
			$response->getData()
		);
	}//end testTheManageableOrganisationsAreReturnedWithTheActiveOneFlagged()

	public function testNoActiveOrganisationFlagsNothing(): void {
		$this->signedInAs('alice');
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->organisationService->method('getManageableOrganisations')->willReturn([$this->organisation('u-1', 'One')]);

		$results = $this->controller()->index()->getData()['results'];

		$this->assertFalse($results[0]['active']);
	}//end testNoActiveOrganisationFlagsNothing()

	public function testTheCallerWithNoManageableOrganisationsGetsAnEmptyList(): void {
		$this->signedInAs('alice');
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->organisationService->method('getManageableOrganisations')->willReturn([]);

		$this->assertSame(['results' => []], $this->controller()->index()->getData());
	}//end testTheCallerWithNoManageableOrganisationsGetsAnEmptyList()
}//end class
