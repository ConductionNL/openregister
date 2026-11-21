<?php

/**
 * The audit reads that answer about the whole instance are for administrators.
 *
 * The subject lookup searches every entry of the trail, and the processing
 * activity overview and the purpose report count every entry of the trail,
 * whatever register, schema or organisation it belongs to. A non-admin caller
 * gets a refusal before the trail is read, so nothing it is not given can be
 * inferred from the answer or its timing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-instance-wide-audit-reads-are-admin-only
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\AuditTrailControllerFactory;
use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Controller\ProcessingPurposeController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Service\Audit\AuditTrailPageService;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Audit\PurposeRegistry;
use OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister;
use OCA\OpenRegister\Service\AuditHashService;
use OCA\OpenRegister\Service\LogService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who may call the instance-wide audit reads.
 */
final class InstanceWideAuditReadsTest extends TestCase {

	/**
	 * The audit mapper, so a test can assert the trail was never read.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $audit;

	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuditTrailMapper::class);
	}//end setUp()

	/**
	 * A session for the given uid, or an anonymous one.
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * A group manager in which only `admin` is an administrator.
	 *
	 * @return IGroupManager The group manager.
	 */
	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($user->getUID() === 'admin') ? ['admin'] : ['users']
		);

		return $groups;
	}//end groupManager()

	/**
	 * A request answering the given parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return IRequest The request.
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		return $request;
	}//end request()

	/**
	 * The audit controller for one caller.
	 *
	 * @param string|null          $uid    The caller.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return AuditTrailController The controller.
	 */
	private function auditController(?string $uid, array $params): AuditTrailController {
		$logService = $this->createMock(LogService::class);
		$logService->method('maskExplicitReveal')->willReturnArgument(0);

		return AuditTrailControllerFactory::create(
			'openregister',
			$this->request($params),
			$logService,
			$this->audit,
			$this->createMock(AuditHashService::class),
			$this->session($uid),
			$this->groupManager(),
			$this->createMock(ReadableAuditTrailLister::class),
			$this->createMock(AuditTrailPageService::class),
			$this->createMock(ObjectHistoryForReaders::class)
		);
	}//end auditController()

	/**
	 * The purpose controller for one caller.
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return ProcessingPurposeController The controller.
	 */
	private function purposeController(?string $uid): ProcessingPurposeController {
		return new ProcessingPurposeController(
			$this->request([]),
			new \OCA\OpenRegister\Controller\Support\ProcessingPurposeControllerServices(
				$this->createMock(ProcessingPurposeMapper::class),
				$this->createMock(PurposeRegistry::class),
				$this->audit,
				new \OCA\OpenRegister\Controller\Support\ControllerSession($this->session($uid), $this->groupManager(), $this->createMock(\Psr\Log\LoggerInterface::class)),
			)
		);
	}//end purposeController()

	public function testASignedInUserCannotSearchTheWholeTrailForASubject(): void {
		$this->audit->expects($this->never())->method('findByIdentifier');

		$response = $this->auditController(uid: 'bob', params: ['identifier' => '123456789'])->subjectAuditTrail();

		self::assertSame(403, $response->getStatus());
		self::assertArrayNotHasKey('results', $response->getData());
	}//end testASignedInUserCannotSearchTheWholeTrailForASubject()

	public function testAnAnonymousCallerCannotSearchTheTrailForASubject(): void {
		$this->audit->expects($this->never())->method('findByIdentifier');

		$response = $this->auditController(uid: null, params: ['identifier' => '123456789'])->subjectAuditTrail();

		self::assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerCannotSearchTheTrailForASubject()

	public function testTheRefusalComesBeforeTheIdentifierIsChecked(): void {
		$response = $this->auditController(uid: 'bob', params: [])->subjectAuditTrail();

		self::assertSame(403, $response->getStatus());
	}//end testTheRefusalComesBeforeTheIdentifierIsChecked()

	public function testAnAdministratorStillSearchesTheTrailForASubject(): void {
		$this->audit->expects($this->once())->method('findByIdentifier')->with('123456789')
			->willReturn(['7' => [['id' => 1, 'changed' => '{"bsn":"123456789"}']]]);

		$response = $this->auditController(uid: 'admin', params: ['identifier' => '123456789'])->subjectAuditTrail();

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $response->getData()['7'][0]['id']);
	}//end testAnAdministratorStillSearchesTheTrailForASubject()

	public function testASignedInUserCannotCountTheTrailPerProcessingActivity(): void {
		$this->audit->expects($this->never())->method('getProcessingActivities');

		$response = $this->auditController(uid: 'bob', params: ['organisationId' => 'org-b'])->processingActivities();

		self::assertSame(403, $response->getStatus());
	}//end testASignedInUserCannotCountTheTrailPerProcessingActivity()

	public function testAnAnonymousCallerCannotCountTheTrailPerProcessingActivity(): void {
		$this->audit->expects($this->never())->method('getProcessingActivities');

		$response = $this->auditController(uid: null, params: [])->processingActivities();

		self::assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerCannotCountTheTrailPerProcessingActivity()

	public function testAnAdministratorStillCountsTheTrailPerProcessingActivity(): void {
		$this->audit->expects($this->once())->method('getProcessingActivities')->with('org-b')
			->willReturn([['processingActivityId' => 'create', 'entryCount' => 4]]);

		$response = $this->auditController(uid: 'admin', params: ['organisationId' => 'org-b'])->processingActivities();

		self::assertSame(200, $response->getStatus());
		self::assertSame(4, $response->getData()[0]['entryCount']);
	}//end testAnAdministratorStillCountsTheTrailPerProcessingActivity()

	public function testASignedInUserCannotCountTheTrailPerPurpose(): void {
		$this->audit->expects($this->never())->method('countByPurpose');

		$response = $this->purposeController(uid: 'bob')->report();

		self::assertSame(403, $response->getStatus());
		self::assertArrayNotHasKey('counts', $response->getData());
	}//end testASignedInUserCannotCountTheTrailPerPurpose()

	public function testAnAnonymousCallerCannotCountTheTrailPerPurpose(): void {
		$this->audit->expects($this->never())->method('countByPurpose');

		$response = $this->purposeController(uid: null)->report();

		self::assertSame(401, $response->getStatus());
	}//end testAnAnonymousCallerCannotCountTheTrailPerPurpose()

	public function testAnAdministratorStillCountsTheTrailPerPurpose(): void {
		$this->audit->expects($this->once())->method('countByPurpose')->willReturn(['brp' => 2, 'null' => 1]);

		$body = $this->purposeController(uid: 'admin')->report()->getData();

		self::assertSame(3, $body['total']);
		self::assertSame(1, $body['unattributed']);
	}//end testAnAdministratorStillCountsTheTrailPerPurpose()
}//end class
