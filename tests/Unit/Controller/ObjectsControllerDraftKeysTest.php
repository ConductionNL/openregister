<?php

/**
 * `GET /api/objects/{register}/{schema}?_drafts=true`: the same rows, with draft keys in `@self.drafts`.
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
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectsControllerFactory;
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
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Controller\Support\DraftKeysOnList
 * @uses   \OCA\OpenRegister\Service\Object\DraftService
 */
final class ObjectsControllerDraftKeysTest extends TestCase {

	private MockObject&DraftVersionMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(DraftVersionMapper::class);
	}

	public function testAListWithDraftsCarriesTheKeysAndKeepsItsRows(): void {
		$draft = new DraftVersion();
		$draft->setObjectUuid('uuid-2');
		$draft->setDraftKey('status-update');
		$draft->setCreatedBy('kees');
		$this->mapper->expects(self::once())->method('findForObjects')->with(['uuid-1', 'uuid-2'])->willReturn([$draft]);

		$data = $this->listing(params: ['_drafts' => 'true'])->getData();

		self::assertSame(2, $data['total']);
		self::assertSame(['uuid-1', 'uuid-2'], array_map(static fn (array $row): string => $row['@self']['id'], $data['results']));
		self::assertSame([], $data['results'][0]['@self']['drafts']);
		self::assertSame(['status-update'], $data['results'][1]['@self']['drafts']);
	}

	public function testAListWithoutTheParameterIsUntouched(): void {
		$this->mapper->expects(self::never())->method('findForObjects');

		$data = $this->listing(params: [])->getData();

		self::assertSame(self::rows(), $data['results']);
	}

	private static function rows(): array {
		return [
			['title' => 'Een', '@self' => ['id' => 'uuid-1']],
			['title' => 'Twee', '@self' => ['id' => 'uuid-2']],
		];
	}

	private function listing(array $params): \OCP\AppFramework\Http\JSONResponse {
		$params += ['register' => 'zaken', 'schema' => 'persoon'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kees');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);

		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('persoon');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(7);
		$objectService->method('getSchema')->willReturn(4);
		$objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$objectService->method('buildSearchQuery')->willReturnCallback(fn (array $requestParams = []) => $requestParams);
		$objectService->method('searchObjectsPaginated')->willReturn(
			['results' => self::rows(), 'total' => 2, 'page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0]
		);

		$drafts = new DraftService($this->mapper, $this->createMock(PermissionCheckInterface::class));
		$magicMapper = $this->createMock(MagicMapper::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				DraftService::class => $drafts,
				\OCA\OpenRegister\Db\MagicMapperInterface::class => $magicMapper,
				default => $this->createMock($id),
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$controller = ObjectsControllerFactory::create(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$registerMapper,
			$schemaMapper,
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		return $controller->index(register: 'zaken', schema: 'persoon', objectService: $objectService);
	}
}
