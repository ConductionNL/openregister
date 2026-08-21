<?php

/**
 * OpenRegister TransitionEngine transition-inputs tests
 *
 * Covers the `inputs` allowlist on static lifecycle transitions: declared
 * values merge into the SAME write that flips the status field, undeclared
 * payload keys are rejected, required inputs missing (or empty-string) are
 * rejected, a transition without `inputs` rejects any payload (today's exact
 * behaviour preserved), and graph-mode transitions reject payloads outright.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
class TransitionEngineInputsTest extends TestCase {
	private const OBJ = '00000000-0000-0000-0000-0000000000ee';

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private IEventDispatcher&MockObject $dispatcher;

	private IUserSession&MockObject $userSession;

	private PermissionHandler&MockObject $permission;

	private RegisterMapper&MockObject $registerMapper;

	private IAppConfig&MockObject $appConfig;

	private LoggerInterface&MockObject $logger;

	private TransitionEngine $engine;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->permission = $this->createMock(PermissionHandler::class);
		$this->permission->method('hasPermission')->willReturn(true);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// The slug contract ships DEFAULT OFF; pin the flag to its default.
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);

		$this->engine = new TransitionEngine(
			$this->objectService,
			$this->schemaMapper,
			$this->dispatcher,
			$this->userSession,
			$this->permission,
			$this->registerMapper,
			$this->appConfig,
			$this->logger
		);
	}//end setUp()

	/**
	 * Build the timesheet object in `draft` state.
	 */
	private function timesheet(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::OBJ);
		$entity->setSchema('timesheet');
		$entity->setRegister('1');
		$entity->setObject(['status' => 'draft', 'employee' => 'e-1']);
		return $entity;
	}//end timesheet()

	/**
	 * Static annotation with a single `submit` transition.
	 *
	 * @param array<int, array<string, mixed>>|null $inputs The `inputs` list, or null to omit the key.
	 *
	 * @return array<string, mixed>
	 */
	private function annotation(?array $inputs = null): array {
		$transition = [
			'from' => ['draft'],
			'to' => 'submitted',
		];
		if ($inputs !== null) {
			$transition['inputs'] = $inputs;
		}

		return [
			'field' => 'status',
			'transitions' => ['submit' => $transition],
		];
	}//end annotation()

	/**
	 * Wire find()/schema for the given object + annotation.
	 */
	private function wire(ObjectEntity $object, array $annotation): void {
		$this->objectService->method('find')->willReturn($object);
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-lifecycle' => $annotation]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end wire()

	/**
	 * Declared input values land in the SAME saveObject() write that flips
	 * the status field — one write, observed together by pre-save listeners.
	 *
	 * @return void
	 */
	public function testDeclaredInputsMergeIntoTheSameWrite(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation(
				[
					['field' => 'hours', 'required' => true],
					['field' => 'note', 'required' => false],
				]
			)
		);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$captured): ObjectEntity {
					$captured = $object;
					return $this->timesheet();
				}
			);

		$this->engine->transition(self::OBJ, 'submit', ['hours' => 8, 'note' => 'week 33']);

		$this->assertIsArray($captured);
		$this->assertSame('submitted', $captured['status']);
		$this->assertSame(8, $captured['hours']);
		$this->assertSame('week 33', $captured['note']);
		// Untouched existing fields survive the merge.
		$this->assertSame('e-1', $captured['employee']);
	}//end testDeclaredInputsMergeIntoTheSameWrite()

	/**
	 * An optional (`required: false`) input may be omitted from the payload.
	 *
	 * @return void
	 */
	public function testOptionalInputMayBeOmitted(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation(
				[
					['field' => 'hours', 'required' => true],
					['field' => 'note', 'required' => false],
				]
			)
		);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$captured): ObjectEntity {
					$captured = $object;
					return $this->timesheet();
				}
			);

		$this->engine->transition(self::OBJ, 'submit', ['hours' => 8]);

		$this->assertIsArray($captured);
		$this->assertSame('submitted', $captured['status']);
		$this->assertSame(8, $captured['hours']);
		$this->assertArrayNotHasKey('note', $captured);
	}//end testOptionalInputMayBeOmitted()

	/**
	 * A payload key the transition does not declare is rejected with the
	 * offending key named, and nothing is saved or dispatched.
	 *
	 * @return void
	 */
	public function testUnknownKeyIsRejectedAndNothingSaved(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation([['field' => 'hours', 'required' => true]])
		);

		$this->objectService->expects($this->never())->method('saveObject');
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		try {
			$this->engine->transition(self::OBJ, 'submit', ['hours' => 8, 'salary' => 99999]);
			$this->fail('Expected InvalidTransitionInputException was not thrown.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertStringContainsString('"salary"', $e->getMessage());
			$this->assertSame(['salary'], $e->getFields());
		}
	}//end testUnknownKeyIsRejectedAndNothingSaved()

	/**
	 * A `required: true` input absent from the payload is rejected with the
	 * missing field named, and nothing is saved.
	 *
	 * @return void
	 */
	public function testMissingRequiredInputIsRejected(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation([['field' => 'hours', 'required' => true]])
		);

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->engine->transition(self::OBJ, 'submit', []);
			$this->fail('Expected InvalidTransitionInputException was not thrown.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertStringContainsString('"hours"', $e->getMessage());
			$this->assertSame(['hours'], $e->getFields());
		}
	}//end testMissingRequiredInputIsRejected()

	/**
	 * An empty-string value for a `required: true` input counts as missing.
	 *
	 * @return void
	 */
	public function testEmptyStringRequiredInputIsRejected(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation([['field' => 'hours', 'required' => true]])
		);

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->engine->transition(self::OBJ, 'submit', ['hours' => '']);
			$this->fail('Expected InvalidTransitionInputException was not thrown.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertSame(['hours'], $e->getFields());
		}
	}//end testEmptyStringRequiredInputIsRejected()

	/**
	 * A transition that declares no `inputs` rejects ANY payload — nothing is
	 * allowlisted, so today's exact behaviour is preserved for schemas that
	 * never opted in.
	 *
	 * @return void
	 */
	public function testNoInputsDeclaredRejectsAnyPayload(): void {
		$this->wire($this->timesheet(), $this->annotation());

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->engine->transition(self::OBJ, 'submit', ['note' => 'hi']);
			$this->fail('Expected InvalidTransitionInputException was not thrown.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertSame(['note'], $e->getFields());
		}
	}//end testNoInputsDeclaredRejectsAnyPayload()

	/**
	 * Without a payload, a transition that declares no `inputs` behaves
	 * exactly as before: the write carries only the flipped status field.
	 *
	 * @return void
	 */
	public function testNoInputsWithoutPayloadKeepsTodayBehaviour(): void {
		$this->wire($this->timesheet(), $this->annotation());

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$captured): ObjectEntity {
					$captured = $object;
					return $this->timesheet();
				}
			);

		$this->engine->transition(self::OBJ, 'submit');

		$this->assertIsArray($captured);
		$this->assertSame('submitted', $captured['status']);
		$this->assertSame('e-1', $captured['employee']);
		// No stray keys beyond what getObject() already carried (the entity
		// mirrors its uuid into `id`) plus the flipped status field.
		$this->assertSame([], array_diff(array_keys($captured), ['id', 'status', 'employee']));
	}//end testNoInputsWithoutPayloadKeepsTodayBehaviour()

	/**
	 * A declared input naming the lifecycle field itself cannot override the
	 * transition target: the status flip is applied AFTER the merge.
	 *
	 * @return void
	 */
	public function testInputCannotOverrideTheLifecycleField(): void {
		$this->wire(
			$this->timesheet(),
			$this->annotation([['field' => 'status', 'required' => false]])
		);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$captured): ObjectEntity {
					$captured = $object;
					return $this->timesheet();
				}
			);

		$this->engine->transition(self::OBJ, 'submit', ['status' => 'hacked']);

		$this->assertIsArray($captured);
		$this->assertSame('submitted', $captured['status']);
	}//end testInputCannotOverrideTheLifecycleField()

	/**
	 * Graph-derived actions declare no `inputs`, so a graph-mode transition
	 * rejects any payload before even fetching siblings.
	 *
	 * @return void
	 */
	public function testGraphModeRejectsAnyPayload(): void {
		$object = $this->timesheet();
		$object->setObject(['caseType' => 'p-1', 'status' => 's-1']);
		$this->wire(
			$object,
			[
				'field' => 'status',
				'graph' => [
					'schema' => 'statustype',
					'parentField' => 'caseType',
					'parentFrom' => 'caseType',
					'orderField' => 'order',
					'finalField' => 'isFinal',
					'allowedMoves' => 'forward',
				],
			]
		);

		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->engine->transition(self::OBJ, 'move-to-s-2', ['note' => 'hi']);
			$this->fail('Expected InvalidTransitionInputException was not thrown.');
		} catch (InvalidTransitionInputException $e) {
			$this->assertSame(['note'], $e->getFields());
		}
	}//end testGraphModeRejectsAnyPayload()
}//end class
