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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\MovedObjectLookupInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
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

	/**
	 * What the first find() answers instead of a miss: the object from whichever table it lives in.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $foundElsewhere = null;

	protected function setUp(): void {
		parent::setUp();

		$this->lookup = $this->createMock(MovedObjectLookupInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, $options) {
				$this->finds[] = [$id, $options->register, $options->schema];
				if ($this->foundElsewhere !== null && count($this->finds) === 1) {
					return $this->foundElsewhere;
				}

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
			$this->createMock(originalClassName: IEventDispatcher::class),
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

	/**
	 * find() resolves a uuid across tables, so the old address of a moved object
	 * comes back FOUND, from its new table. That is still the old address, and it
	 * is read again at the new one rather than rendered here without saying so.
*
	 * @return void
	 */
	public function testAnObjectFoundInAnotherTableIsReadAgainAtItsNewAddress(): void {
		$this->foundElsewhere = $this->entityAt(register: 9, schema: 5);
		$this->lookup->expects($this->once())->method('movedFrom')->with('the-uuid', '7', '4', true)
			->willReturn(['register' => '9', 'schema' => '5', 'uuid' => 'the-uuid', 'movedAt' => '2026-10-01T09:00:00+00:00']);

		$this->controller->show('the-uuid', 'zaken', 'persoon', $this->objectService);

		$this->assertSame([['the-uuid', 'zaken', 'persoon'], ['the-uuid', '9', '5']], $this->finds);
	}

	/**
	 * An object that lives where it was asked for never reaches the move lookup.
*
	 * @return void
	 */
	public function testAnObjectThatLivesHereIsNotLookedUp(): void {
		$this->foundElsewhere = $this->entityAt(register: 7, schema: 4);
		$this->lookup->expects($this->never())->method('movedFrom');

		try {
			$this->controller->show('the-uuid', 'zaken', 'persoon', $this->objectService);
		} catch (\Throwable $e) {
			// Rendering is not under test here; only that no lookup was made.
		}

		$this->assertSame([['the-uuid', 'zaken', 'persoon']], $this->finds);
	}

	/**
	 * An object in another table that did not move from here is not rerouted.
*
	 * @return void
	 */
	public function testAnObjectElsewhereThatDidNotMoveFromHereIsNotRerouted(): void {
		$this->foundElsewhere = $this->entityAt(register: 9, schema: 5);
		$this->lookup->expects($this->once())->method('movedFrom')->willReturn(null);

		try {
			$this->controller->show('the-uuid', 'zaken', 'persoon', $this->objectService);
		} catch (\Throwable $e) {
			// Rendering is not under test here; only that no second read was made.
		}

		$this->assertSame([['the-uuid', 'zaken', 'persoon']], $this->finds);
	}

	private function entityAt(int $register, int $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('the-uuid');
		$entity->setRegister((string) $register);
		$entity->setSchema((string) $schema);

		return $entity;
	}
}//end class
