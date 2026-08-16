<?php

/**
 * Unit tests for ProcessingLogController — AVG processing-log inquiry
 * access model.
 *
 * Verifies the privacy-correct access guard on the read-log query /
 * extract surface:
 *   - unauthenticated → 401, no data;
 *   - authenticated but neither admin nor privacy-officer → 403 (fail
 *     closed), no data;
 *   - privacy-officer (FG) → succeeds, tenant-scoped, confidential
 *     entries included;
 *   - admin → succeeds, unscoped;
 *   - per-subject extract requires identifiers (400) and bounds the
 *     period (422);
 *   - append-only by surface: the controller exposes no update/delete.
 *
 * Pure-logic test: collaborators mocked, no Nextcloud runtime.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ProcessingLogController;
use OCA\OpenRegister\Db\ProcessingLogMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Controller\ProcessingLogController
 */
class ProcessingLogControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * @var ProcessingLogMapper&MockObject
	 */
	private $logMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * @var IAppConfig&MockObject
	 */
	private $appConfig;

	/**
	 * @var OrganisationService&MockObject
	 */
	private $organisationService;

	private ProcessingLogController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->logMapper = $this->createMock(ProcessingLogMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->organisationService = $this->createMock(OrganisationService::class);

		$this->appConfig->method('getValueString')->willReturn('privacy-officer');
		$this->appConfig->method('getValueInt')->willReturn(366);

		$this->controller = new ProcessingLogController(
			appName: 'openregister',
			request: $this->request,
			logMapper: $this->logMapper,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			appConfig: $this->appConfig,
			organisationService: $this->organisationService
		);

	}//end setUp()

	/**
	 * Set the current user and their groups.
	 *
	 * @param string|null $uid User id, or null for anonymous.
	 * @param array<int, string> $groups Group memberships.
	 *
	 * @return void
	 */
	private function asUser(?string $uid, array $groups = []): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

	}//end asUser()

	/**
	 * Anonymous access is rejected with 401 and no data.
	 *
	 * @return void
	 */
	public function testAnonymousIsUnauthorized(): void {
		$this->asUser(null);
		$this->logMapper->expects($this->never())->method('findFiltered');

		$response = $this->controller->index();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousIsUnauthorized()

	/**
	 * A plain authenticated user (no admin, no FG) is forbidden.
	 *
	 * @return void
	 */
	public function testNonPrivilegedUserIsForbidden(): void {
		$this->asUser('bob', ['users']);
		$this->logMapper->expects($this->never())->method('findFiltered');

		$response = $this->controller->index();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testNonPrivilegedUserIsForbidden()

	/**
	 * The privacy-officer succeeds, is tenant-scoped, and sees
	 * confidential entries.
	 *
	 * @return void
	 */
	public function testPrivacyOfficerIsScopedAndSeesConfidential(): void {
		$this->asUser('fg', ['privacy-officer']);
		$org = new \OCA\OpenRegister\Db\Organisation();
		$org->setUuid('org-7');
		$this->organisationService->method('getUserOrganisations')->willReturn([$org]);
		$this->request->method('getParam')->willReturn(null);

		$this->logMapper->expects($this->once())
			->method('findFiltered')
			->willReturnCallback(
				function (
					array $filters,
					$from,
					$to,
					?string $organisationId,
					bool $includeConfidential,
				): array {
					$this->assertSame('org-7', $organisationId);
					$this->assertTrue($includeConfidential);
					return [];
				}
			);

		$response = $this->controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testPrivacyOfficerIsScopedAndSeesConfidential()

	/**
	 * Admin succeeds and is unscoped (organisationId null).
	 *
	 * @return void
	 */
	public function testAdminIsUnscoped(): void {
		$this->asUser('root', ['admin']);
		$this->request->method('getParam')->willReturn(null);

		$this->logMapper->expects($this->once())
			->method('findFiltered')
			->willReturnCallback(
				function (
					array $filters,
					$from,
					$to,
					?string $organisationId,
					bool $includeConfidential,
				): array {
					$this->assertNull($organisationId);
					$this->assertTrue($includeConfidential);
					return [];
				}
			);

		$response = $this->controller->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAdminIsUnscoped()

	/**
	 * Per-subject extract without identifiers is a 400.
	 *
	 * @return void
	 */
	public function testExtractRequiresSubjectIdentifiers(): void {
		$this->asUser('fg', ['privacy-officer']);
		$org = new \OCA\OpenRegister\Db\Organisation();
		$org->setUuid('org-7');
		$this->organisationService->method('getUserOrganisations')->willReturn([$org]);
		$this->request->method('getParam')->willReturn(null);

		$response = $this->controller->involvedParty();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testExtractRequiresSubjectIdentifiers()

	/**
	 * A range exceeding the configured maximum is a 422.
	 *
	 * @return void
	 */
	public function testExtractRangeTooWideIs422(): void {
		$this->asUser('fg', ['privacy-officer']);
		$org = new \OCA\OpenRegister\Db\Organisation();
		$org->setUuid('org-7');
		$this->organisationService->method('getUserOrganisations')->willReturn([$org]);

		// 500-day span against a 366-day maximum.
		$this->request->method('getParam')->willReturnMap(
			[
				['subjectIdType', null, 'BSN'],
				['subjectIdValue', null, '123456789'],
				['from', null, '2024-01-01'],
				['to', null, '2025-05-15'],
			]
		);

		$response = $this->controller->involvedParty();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testExtractRangeTooWideIs422()

	/**
	 * Append-only by surface: no update/delete-single endpoints exist.
	 *
	 * @return void
	 */
	public function testControllerHasNoMutationEndpoints(): void {
		$methods = get_class_methods(ProcessingLogController::class);
		foreach (['update', 'destroy', 'delete', 'create'] as $forbidden) {
			$this->assertNotContains($forbidden, $methods, "Processing-log controller must not expose '$forbidden'");
		}

	}//end testControllerHasNoMutationEndpoints()
}//end class
