<?php

/**
 * A lock the caller may not take answers 403.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/lock-needs-update-permission/specs/lock-needs-update-permission/spec.md#requirement-a-user-lock-needs-update-permission-on-the-object
 */
class ObjectsControllerLockRefusalTest extends TestCase {

	/**
	 * A refused lock answers 403 with the refusal, not 500.
	 *
	 * @return void
	 */
	public function testARefusedLockAnswersForbidden(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

		$service = $this->createMock(ObjectService::class);
		$service->method('lockObject')->willThrowException(new NotAuthorizedException(message: 'No update permission'));

		$controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$service,
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->lock(register: 'tickets', schema: 'ticket', id: 'abc');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'No update permission'], $response->getData());
	}//end testARefusedLockAnswersForbidden()
}//end class
