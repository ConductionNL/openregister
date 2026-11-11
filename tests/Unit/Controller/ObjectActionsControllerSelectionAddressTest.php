<?php

/**
 * Characterisation of what a selection run asks for, and which runs it refuses.
 *
 * Pins, before the selection travels as one value, that the declaration is
 * looked up on the schema the route names with the route's register, that
 * each run (single or selected) carries the route's register and schema as
 * its subject, and that an action not offered on a selection never runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectActionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRequest;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectActionsControllerFactory;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectActionsController
 */
class ObjectActionsControllerSelectionAddressTest extends TestCase {

	/**
	 * The schema loader double.
	 *
	 * @var MacroActionResolver&MockObject
	 */
	private MacroActionResolver&MockObject $macros;

	/**
	 * The declaration lookup double.
	 *
	 * @var ObjectActionDefinitions&MockObject
	 */
	private ObjectActionDefinitions&MockObject $definitions;

	/**
	 * The runner double.
	 *
	 * @var ObjectActionRunner&MockObject
	 */
	private ObjectActionRunner&MockObject $runner;

	/**
	 * Build the controller over the request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ObjectActionsController The controller.
	 */
	private function controller(array $params): ObjectActionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (string $id): ObjectEntity {
				$object = new ObjectEntity();
				$object->setUuid('uuid-'.$id);
				$object->setSchema('5');

				return $object;
			}
		);
		$this->macros = $this->createMock(MacroActionResolver::class);
		$this->definitions = $this->createMock(ObjectActionDefinitions::class);
		$this->runner = $this->createMock(ObjectActionRunner::class);

		return ObjectActionsControllerFactory::create(
			$request,
			$objects,
			$this->macros,
			$this->definitions,
			$this->createMock(ObjectActionList::class),
			$this->runner,
			$this->createMock(ObjectActionHistory::class),
			$this->createMock(ObjectActionBulk::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A declaration offered on a selection, or one that is not.
	 *
	 * @param bool $bulk Whether the action is offered on the bulk surface.
	 *
	 * @return ObjectActionDefinition The declaration.
	 */
	private function definition(bool $bulk): ObjectActionDefinition {
		$surfaces = ['row'];
		if ($bulk === true) {
			$surfaces[] = 'bulk';
		}

		$declaring = new Schema();
		$declaring->setConfiguration(
			[
				'x-openregister-action' => [
					'declared' => [
						'name' => 'Declared',
						'description' => 'x',
						'surfaces' => $surfaces,
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['assignee' => 'x']]]],
					],
				],
			]
		);
		$definition = (new ObjectActionDefinitions($this->createMock(RegisterMapper::class)))->forSchema($declaring)['declared'] ?? null;
		$this->assertNotNull($definition);

		return $definition;
	}//end definition()

	/**
	 * A selection looks the declaration up on the named schema with the route's register, and runs each id with the route as subject.
	 *
	 * @return void
	 */
	public function testASelectionRunsUnderTheRouteAddress(): void {
		$controller = $this->controller(params: ['ids' => ['a', 'b', 'a'], 'inputs' => ['x' => 1]]);
		$schema = new Schema();
		$schema->setSlug('ticket');
		$loaded = [];
		$this->macros->method('loadSchema')->willReturnCallback(
			static function (string $named) use (&$loaded, $schema): Schema {
				$loaded[] = $named;

				return $schema;
			}
		);
		$this->definitions->expects($this->once())->method('forSchema')->with($schema, 'r')
			->willReturn(['assign' => $this->definition(bulk: true)]);
		$subjects = [];
		$this->runner->expects($this->exactly(2))->method('run')->willReturnCallback(
			static function (ObjectActionTarget $target, ObjectActionRequest $request) use (&$subjects): array {
				[$key, $given] = [$request->key, $request->given];
				$subject    = $target->subject();
				$subjects[] = [$key, $given, $subject];

				return ['status' => Http::STATUS_OK, 'body' => ['run' => 'run-'.$subject['uuid'], 'outcome' => 'completed']];
			}
		);

		$response = $controller->invokeOnSelection('r', 'ticket', 'assign');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['succeeded' => 2, 'failed' => 0], $response->getData()['summary']);
		$this->assertSame('assign', $response->getData()['action']);
		$this->assertSame(['ticket', '5', '5'], $loaded);
		$this->assertSame(
			[
				['assign', ['x' => 1], ['uuid' => 'uuid-a', 'register' => 'r', 'schema' => 'ticket']],
				['assign', ['x' => 1], ['uuid' => 'uuid-b', 'register' => 'r', 'schema' => 'ticket']],
			],
			$subjects
		);
	}//end testASelectionRunsUnderTheRouteAddress()

	/**
	 * An action the declaration does not offer on a selection is refused before any object runs.
	 *
	 * @return void
	 */
	public function testAnActionNotOfferedOnASelectionNeverRuns(): void {
		$controller = $this->controller(params: ['ids' => ['a'], 'inputs' => []]);
		$this->macros->method('loadSchema')->willReturn(new Schema());
		$this->definitions->expects($this->once())->method('forSchema')->with($this->anything(), 'r')
			->willReturn(['publish' => $this->definition(bulk: false)]);
		$this->runner->expects($this->never())->method('run');

		$response = $controller->invokeOnSelection('r', 'ticket', 'publish');

		$this->assertSame(
			[Http::STATUS_BAD_REQUEST, ['error' => 'Action "publish" is not offered on a selection.']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testAnActionNotOfferedOnASelectionNeverRuns()

	/**
	 * A schema that does not load refuses the selection without asking for a declaration.
	 *
	 * @return void
	 */
	public function testASchemaThatDoesNotLoadIsNotOffered(): void {
		$controller = $this->controller(params: ['ids' => ['a'], 'inputs' => []]);
		$this->macros->method('loadSchema')->willReturn(null);
		$this->definitions->expects($this->never())->method('forSchema');
		$this->runner->expects($this->never())->method('run');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->invokeOnSelection('r', 'ticket', 'assign')->getStatus());
	}//end testASchemaThatDoesNotLoadIsNotOffered()

	/**
	 * A single run carries the route's register and schema as its subject.
	 *
	 * @return void
	 */
	public function testASingleRunCarriesTheRouteAsSubject(): void {
		$controller = $this->controller(params: ['inputs' => ['y' => 2]]);
		$this->macros->method('loadSchema')->willReturn(new Schema());
		$this->runner->expects($this->once())->method('run')
			->with($this->callback(static fn (ObjectActionTarget $target): bool => $target->subject() === ['uuid' => 'uuid-7', 'register' => 'reg', 'schema' => 'sch']), new ObjectActionRequest('close', ['y' => 2]))
			->willReturn(['status' => Http::STATUS_FORBIDDEN, 'body' => ['error' => 'refused']]);

		$response = $controller->invoke('reg', 'sch', '7', 'close');

		$this->assertSame([Http::STATUS_FORBIDDEN, ['error' => 'refused']], [$response->getStatus(), $response->getData()]);
	}//end testASingleRunCarriesTheRouteAsSubject()
}//end class
