<?php

/**
 * The old address of a moved object: show() asks where it went on a miss,
 * under the caller's own scope, and reads it again at its new address, once.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\MovedObjectLookupInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerMovedAddressTest extends TestCase {

	private MovedObjectLookupInterface&MockObject $lookup;

	private ObjectService&MockObject $objectService;

	private ObjectsController $controller;

	/**
	 * The find() calls, as [id, register, schema].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: mixed}>
	 */
	private array $finds = [];

	protected function setUp(): void {
		parent::setUp();

		$this->lookup = $this->createMock(MovedObjectLookupInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, $options) {
				$this->finds[] = [$id, $options->register, $options->schema];
				throw new DoesNotExistException('not here');
			}
		);

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
			fn (string $id) => ($id === MovedObjectLookupInterface::class ? $this->lookup : $magicMapper)
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['users']);
		$groupManager->method('isAdmin')->willReturn(false);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([]);

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

	public function testAMissAsksWhereTheObjectWentUnderTheCallersScope(): void {
		$this->lookup->expects($this->once())->method('movedFrom')->with('some-uuid', '7', '4', true)->willReturn(null);

		$response = $this->controller->show('some-uuid', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([['some-uuid', 'zaken', 'persoon']], $this->finds);
	}

	public function testAMovedObjectIsReadAgainAtItsNewAddressOnceOnly(): void {
		// The lookup would answer for every address; the second read must not
		// ask it again, or a move back and forth would loop.
		$this->lookup->expects($this->once())->method('movedFrom')
			->willReturn(['register' => '7', 'schema' => '4', 'uuid' => 'the-uuid', 'movedAt' => '2026-10-01T09:00:00+00:00']);

		$response = $this->controller->show('old-slug', 'zaken', 'persoon', $this->objectService);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame([['old-slug', 'zaken', 'persoon'], ['the-uuid', '7', '4']], $this->finds);
	}
}//end class
