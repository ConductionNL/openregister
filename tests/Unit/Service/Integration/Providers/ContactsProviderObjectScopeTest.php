<?php

/**
 * ContactsProvider scopes link-id writes to the addressed object.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\Providers\AppProviderDescription;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\Integration\Providers\ContactsProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContactsProviderObjectScopeTest extends TestCase {

	/** @var ContactService&MockObject */
	private ContactService $contacts;

	private ContactsProvider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->contacts = $this->createMock(ContactService::class);
		$this->provider = new ContactsProvider(new AppProviderDescription($this->createMock(IAppManager::class), $this->createMock(IL10N::class)), $this->contacts);
	}

	public function testUpdatePassesTheObjectTheLinkMustBelongTo(): void {
		$link = new ContactLink();
		$link->setRole('applicant');
		$this->contacts->expects($this->once())
			->method('updateRole')
			->with(42, 'applicant', 'abc-123')
			->willReturn($link);

		$this->assertSame('applicant', $this->provider->update(new ObjectRef(register: 'reg', schema: 'sch', id: 'abc-123'), '42', ['role' => 'applicant'])['role']);
	}

	public function testDeletePassesTheObjectTheLinkMustBelongTo(): void {
		$this->contacts->expects($this->once())
			->method('unlinkContact')
			->with(42, 'abc-123');

		$this->provider->delete(new ObjectRef(register: 'reg', schema: 'sch', id: 'abc-123'), '42');
	}

	public function testALinkOfAnotherObjectIsNotFound(): void {
		$this->contacts->method('unlinkContact')
			->willThrowException(new \Exception('Contact link not found', 404));

		$this->expectExceptionCode(404);
		$this->provider->delete(new ObjectRef(register: 'reg', schema: 'sch', id: 'abc-123'), '77');
	}
}
