<?php

/**
 * CredentialProviderApprovalController: only a real administrator decides, nothing is written for anyone else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CredentialProviderApprovalController;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisionRefused;
use OCA\OpenRegister\Service\Credential\DeclaredProviderDecisions;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class CredentialProviderApprovalControllerTest extends TestCase {

	private IUserSession&MockObject $session;

	private IGroupManager&MockObject $groups;

	private DeclaredProviderDecisions&MockObject $decisions;

	private IRequest&MockObject $request;

	protected function setUp(): void {
		$this->session   = $this->createMock(IUserSession::class);
		$this->groups    = $this->createMock(IGroupManager::class);
		$this->decisions = $this->createMock(DeclaredProviderDecisions::class);
		$this->request   = $this->createMock(IRequest::class);
	}//end setUp()

	private function controller(): CredentialProviderApprovalController {
		return new CredentialProviderApprovalController(request: $this->request, groups: $this->groups, userSession: $this->session, decisions: $this->decisions);
	}//end controller()

	private function signedIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with($uid)->willReturn($admin);
	}//end signedIn()

	public function testAnAnonymousCallerGets401AndNothingIsWritten(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->decisions->expects($this->never())->method('decide');

		$response = $this->controller()->approve(identifier: 'app:p');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testAnAnonymousCallerGets401AndNothingIsWritten()

	public function testANonAdministratorGets403AndNothingIsWritten(): void {
		$this->signedIn(uid: 'delegate', admin: false);
		$this->decisions->expects($this->never())->method('decide');

		$response = $this->controller()->revoke(identifier: 'app:p');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Only administrators may decide on declared credential providers.'], $response->getData());
	}//end testANonAdministratorGets403AndNothingIsWritten()

	public function testAnAdministratorsDecisionIsRecordedWithTheirNameAndNote(): void {
		$this->signedIn(uid: 'admin', admin: true);
		$this->request->method('getParam')->with('note', '')->willReturn('checked');
		$this->decisions->expects($this->once())->method('decide')
			->with('app:p', DeclaredProviderDecisions::REJECT, 'admin', 'checked')
			->willReturn(['status' => 'rejected', 'origin' => 'declared']);

		$response = $this->controller()->reject(identifier: 'app:p');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['identifier' => 'app:p', 'status' => 'rejected', 'origin' => 'declared'], $response->getData());
	}//end testAnAdministratorsDecisionIsRecordedWithTheirNameAndNote()

	public function testARefusedDecisionAnswersWithItsStatus(): void {
		$this->signedIn(uid: 'admin', admin: true);
		$this->request->method('getParam')->willReturn('');
		$this->decisions->method('decide')->willThrowException(new DeclaredProviderDecisionRefused('unknown provider', 404));

		$response = $this->controller()->approve(identifier: 'app:x');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'unknown provider'], $response->getData());
	}//end testARefusedDecisionAnswersWithItsStatus()
}//end class
