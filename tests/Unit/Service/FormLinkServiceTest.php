<?php

/**
 * Unit tests for FormLinkService — Tier-2 forms integration leaf.
 *
 * Focuses on the surface contract:
 *   - getLinkedForms() groups submission rows under their form-level parent;
 *   - linkForm() / linkFormSubmission() are idempotent (returns existing
 *     row on duplicate insert);
 *   - createAndLinkForm() requires Forms app + a logged-in user;
 *   - unlinkForm() / unlinkSubmission() delegate to the mapper.
 *
 * DB interaction is mocked at the IDBConnection boundary so the
 * snapshot/upstream queries can be exercised without a real Forms
 * install.
 *
 * @category Tests
 * @package  Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\FormLink;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Service\FormLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FormLinkServiceTest extends TestCase {

	/**
	 * @var FormLinkMapper&MockObject
	 */
	private FormLinkMapper $formLinkMapper;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager $appManager;

	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	private FormLinkService $service;

	protected function setUp(): void {
		$this->formLinkMapper = $this->getMockBuilder(FormLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'findByObjectUuid',
				'findFormLink',
				'findSubmissionLink',
				'countByObjectUuid',
				'deleteByObjectUuid',
				'deleteByObjectAndForm',
				'insert',
				'delete',
			])
			->getMock();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new FormLinkService(
			$this->formLinkMapper,
			$this->container,
			$this->appManager,
			$this->db,
			$this->userSession,
			$this->logger
		);

	}//end setUp()

	private function setupUser(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end setupUser()

	public function testGetLinkedFormsGroupsSubmissionsUnderParent(): void {
		$formLink = new FormLink();
		$formLink->setObjectUuid('obj-1');
		$formLink->setRegisterId(1);
		$formLink->setFormId(42);
		$formLink->setSubmissionId(null);
		$formLink->setTitle('Budget intake');

		$sub1 = new FormLink();
		$sub1->setObjectUuid('obj-1');
		$sub1->setRegisterId(1);
		$sub1->setFormId(42);
		$sub1->setSubmissionId(1001);

		$sub2 = new FormLink();
		$sub2->setObjectUuid('obj-1');
		$sub2->setRegisterId(1);
		$sub2->setFormId(42);
		$sub2->setSubmissionId(1002);

		$this->formLinkMapper->method('findByObjectUuid')->with('obj-1')
			->willReturn([$formLink, $sub1, $sub2]);

		$result = $this->service->getLinkedForms('obj-1');

		$this->assertCount(1, $result);
		$this->assertSame(42, $result[0]['formId']);
		$this->assertCount(2, $result[0]['submissions']);
		$this->assertSame(1001, $result[0]['submissions'][0]['submissionId']);
		$this->assertSame(1002, $result[0]['submissions'][1]['submissionId']);

	}//end testGetLinkedFormsGroupsSubmissionsUnderParent()

	public function testGetLinkedFormsSyntheticParentForOrphanSubmission(): void {
		$sub = new FormLink();
		$sub->setObjectUuid('obj-1');
		$sub->setRegisterId(1);
		$sub->setFormId(99);
		$sub->setSubmissionId(5005);

		$this->formLinkMapper->method('findByObjectUuid')->willReturn([$sub]);

		$result = $this->service->getLinkedForms('obj-1');

		$this->assertCount(1, $result);
		$this->assertSame(99, $result[0]['formId']);
		$this->assertTrue($result[0]['synthetic']);
		$this->assertCount(1, $result[0]['submissions']);

	}//end testGetLinkedFormsSyntheticParentForOrphanSubmission()

	public function testLinkFormIsIdempotent(): void {
		$this->setupUser('alice');

		$existing = new FormLink();
		$existing->setObjectUuid('obj-1');
		$existing->setFormId(42);
		$existing->setSubmissionId(null);

		$this->formLinkMapper->method('findFormLink')->with('obj-1', 42)->willReturn($existing);
		// Mapper insert MUST NOT be called when an existing row matches.
		$this->formLinkMapper->expects($this->never())->method('insert');

		$link = $this->service->linkForm(objectUuid: 'obj-1', registerId: 1, formId: 42);

		$this->assertSame($existing, $link);

	}//end testLinkFormIsIdempotent()

	public function testLinkFormRequiresUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->linkForm(objectUuid: 'obj-1', registerId: 1, formId: 42);

	}//end testLinkFormRequiresUser()

	public function testLinkFormSubmissionIsIdempotent(): void {
		$this->setupUser('alice');

		$existing = new FormLink();
		$existing->setFormId(42);
		$existing->setSubmissionId(1001);

		$this->formLinkMapper->method('findSubmissionLink')->with('obj-1', 42, 1001)->willReturn($existing);
		$this->formLinkMapper->expects($this->never())->method('insert');

		$link = $this->service->linkFormSubmission(
			objectUuid: 'obj-1',
			registerId: 1,
			formId: 42,
			submissionId: 1001
		);

		$this->assertSame($existing, $link);

	}//end testLinkFormSubmissionIsIdempotent()

	public function testUnlinkFormDelegatesToMapper(): void {
		$this->formLinkMapper->method('deleteByObjectAndForm')
			->with('obj-1', 42)
			->willReturn(3);

		$removed = $this->service->unlinkForm(objectUuid: 'obj-1', formId: 42);

		$this->assertSame(3, $removed);

	}//end testUnlinkFormDelegatesToMapper()

	public function testUnlinkSubmissionReturnsFalseWhenMissing(): void {
		$this->formLinkMapper->method('findSubmissionLink')->willReturn(null);
		$this->formLinkMapper->expects($this->never())->method('delete');

		$result = $this->service->unlinkSubmission(
			objectUuid: 'obj-1',
			formId: 42,
			submissionId: 9999
		);

		$this->assertFalse($result);

	}//end testUnlinkSubmissionReturnsFalseWhenMissing()

	public function testUnlinkSubmissionDeletesFoundRow(): void {
		$existing = new FormLink();
		$existing->setSubmissionId(1001);

		$this->formLinkMapper->method('findSubmissionLink')->willReturn($existing);
		$this->formLinkMapper->expects($this->once())->method('delete')->with($existing);

		$result = $this->service->unlinkSubmission(
			objectUuid: 'obj-1',
			formId: 42,
			submissionId: 1001
		);

		$this->assertTrue($result);

	}//end testUnlinkSubmissionDeletesFoundRow()

	public function testCreateAndLinkFormRequiresFormsApp(): void {
		$this->setupUser('alice');
		$this->appManager->method('isInstalled')->with('forms')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('NC Forms app is not installed');

		$this->service->createAndLinkForm(
			objectUuid: 'obj-1',
			registerId: 1,
			title: 'Demo form'
		);

	}//end testCreateAndLinkFormRequiresFormsApp()

	public function testGetAvailableFormsReturnsEmptyWhenFormsMissing(): void {
		$this->appManager->method('isInstalled')->with('forms')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableForms());

	}//end testGetAvailableFormsReturnsEmptyWhenFormsMissing()

	public function testGetAvailableFormsReturnsEmptyWhenNoUser(): void {
		$this->appManager->method('isInstalled')->with('forms')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertSame([], $this->service->getAvailableForms());

	}//end testGetAvailableFormsReturnsEmptyWhenNoUser()

}//end class
