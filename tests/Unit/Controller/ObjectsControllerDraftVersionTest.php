<?php

/**
 * Reading an object at a draft version through show(): a draft the caller may not see
 * answers like a missing object.
 *
 * @spec openspec/changes/records-draft-versions/design.md
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * `GET .../{id}?version=<key>` on a draft the caller may not see (records-draft-versions 2.1).
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\DraftVersionRead
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class ObjectsControllerDraftVersionTest extends TestCase {

	private DraftService&MockObject $drafts;

	private ObjectService&MockObject $objectService;

	private ObjectsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->drafts = $this->createMock(DraftService::class);
		$live = new ObjectEntity();
		$live->setUuid('obj-1');
		$live->setObject(['title' => 'Old title']);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturn($live);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('persoon');

		$this->objectService->method('getRegister')->willReturn(7);
		$this->objectService->method('getSchema')->willReturn(4);
		$this->objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$this->objectService->method('getCurrentSchemaEntity')->willReturn($schema);

		$magicMapper = $this->createMock(MagicMapper::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === DraftService::class ? $this->drafts : $magicMapper)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);
		$groupManager->method('isAdmin')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['version' => 'update-1']);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'version' ? 'update-1' : $default)
		);

		$this->controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			windowService: $this->createMock(DeletionWindowService::class)
		);
	}

	public function testADraftTheCallerMayNotSeeAnswersNotFound(): void {
		$this->drafts->expects($this->once())->method('find')
			->with($this->isInstanceOf(ObjectEntity::class), $this->isInstanceOf(Schema::class), 'update-1', 'bob')
			->willReturn(null);
		$this->drafts->expects($this->never())->method('merged');

		$response = $this->controller->show('obj-1', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Not Found'], $response->getData());
	}
}//end class
