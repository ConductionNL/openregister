<?php

/**
 * TransitionEngine names the transition it performs, and always un-names it.
 *
 * The listeners only see object data, so the engine declares the action on the
 * shared LifecycleActionContext for the duration of its save. Two properties
 * matter: the declaration is present DURING the save, where the listeners read
 * it, and it is gone AFTER, including when the save throws. A leaked declaration
 * would make a later direct edit of the same object be judged as that action.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Declaration lifetime around the engine's save.
 */
class TransitionEngineActionContextTest extends TestCase {

	private const OBJ = '00000000-0000-0000-0000-0000000000ac';

	private ObjectService&MockObject $objectService;

	private LifecycleActionContext $context;

	private TransitionEngine $engine;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$permission = $this->createMock(PermissionHandler::class);
		$permission->method('hasPermission')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$object = new ObjectEntity();
		$object->setUuid(self::OBJ);
		$object->setSchema('bezwaar');
		$object->setRegister('1');
		$object->setObject(['status' => 'received']);
		$this->objectService->method('find')->willReturn($object);

		// Two transitions sharing a from/to pair: the case the declaration exists for.
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'transitions' => [
						'startVerifying' => ['from' => ['received'], 'to' => 'verifying'],
						'assign' => ['from' => ['received'], 'to' => 'verifying'],
					],
				],
			]
		);
		$schemaMapper->method('find')->willReturn($schema);

		$this->context = new LifecycleActionContext();
		$this->engine = new TransitionEngine(
			$this->objectService,
			$schemaMapper,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$permission,
			$this->createMock(RegisterMapper::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			new LifecycleWriteBoundary($this->context)
		);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testTheNamedActionIsDeclaredDuringTheSave(): void {
		$seen = 'nothing';
		$this->objectService->method('saveObject')->willReturnCallback(
			function () use (&$seen): ObjectEntity {
				// This is where the listeners run: inside saveObject.
				$seen = $this->context->declaredFor(uuid: self::OBJ);
				$saved = new ObjectEntity();
				$saved->setUuid(self::OBJ);
				$saved->setObject(['status' => 'verifying']);
				return $saved;
			}
		);

		$this->engine->transition(self::OBJ, 'assign', []);

		// `assign`, not `startVerifying`: the second twin, which first-match
		// by value would never have picked.
		$this->assertSame('assign', $seen);
	}//end testTheNamedActionIsDeclaredDuringTheSave()

	/**
	 * @return void
	 */
	public function testTheDeclarationIsReleasedAfterTheSave(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (): ObjectEntity {
				$saved = new ObjectEntity();
				$saved->setUuid(self::OBJ);
				$saved->setObject(['status' => 'verifying']);
				return $saved;
			}
		);

		$this->engine->transition(self::OBJ, 'assign', []);

		$this->assertNull($this->context->declaredFor(uuid: self::OBJ));
	}//end testTheDeclarationIsReleasedAfterTheSave()

	/**
	 * @return void
	 */
	public function testTheDeclarationIsReleasedWhenTheSaveThrows(): void {
		// A refused transition surfaces as an exception out of saveObject. If
		// the release were on the success path only, the name would leak into
		// the next save of this object in the same process.
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('refused'));

		try {
			$this->engine->transition(self::OBJ, 'assign', []);
			$this->fail('The save was set up to throw.');
		} catch (RuntimeException $e) {
			$this->assertSame('refused', $e->getMessage());
		}

		$this->assertNull($this->context->declaredFor(uuid: self::OBJ));
	}//end testTheDeclarationIsReleasedWhenTheSaveThrows()
}//end class
