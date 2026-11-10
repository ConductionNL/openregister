<?php

/**
 * Golden outcomes of the object-actions routes.
 *
 * Pins, before a restructure, the exact status and body of every refusal and
 * of an allowed run, list, history and selection.
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

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ObjectActionsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionTarget;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ObjectActionsControllerFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectActionsController
 */
class ObjectActionsControllerA24GoldenTest extends TestCase {

	/**
	 * The object read double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The schema loader double.
	 *
	 * @var MacroActionResolver&MockObject
	 */
	private MacroActionResolver&MockObject $macros;

	/**
	 * The list double.
	 *
	 * @var ObjectActionList&MockObject
	 */
	private ObjectActionList&MockObject $list;

	/**
	 * The runner double.
	 *
	 * @var ObjectActionRunner&MockObject
	 */
	private ObjectActionRunner&MockObject $runner;

	/**
	 * The history double.
	 *
	 * @var ObjectActionHistory&MockObject
	 */
	private ObjectActionHistory&MockObject $history;

	/**
	 * The bulk double.
	 *
	 * @var ObjectActionBulk&MockObject
	 */
	private ObjectActionBulk&MockObject $bulk;

	/**
	 * Build the controller over request parameters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return ObjectActionsController The controller.
	 */
	private function controller(array $params): ObjectActionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$this->objects = $this->createMock(ObjectService::class);
		$this->macros = $this->createMock(MacroActionResolver::class);
		$this->list = $this->createMock(ObjectActionList::class);
		$this->runner = $this->createMock(ObjectActionRunner::class);
		$this->runner->method('target')->willReturnCallback(
			static fn (ObjectEntity $object, Schema $schema, string $register, string $schemaRef): ObjectActionTarget => new ObjectActionTarget($object, $schema, $register, $schemaRef)
		);
		$this->history = $this->createMock(ObjectActionHistory::class);
		$this->bulk = $this->createMock(ObjectActionBulk::class);

		return ObjectActionsControllerFactory::create(
			$request,
			$this->objects,
			$this->macros,
			new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
			$this->list,
			$this->runner,
			$this->history,
			$this->bulk,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A schema offering `assign` on bulk with a `next` of `stay`.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('ticket');
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'assign' => [
						'name' => 'Assign',
						'description' => 'Take it.',
						'surfaces' => ['row', 'bulk'],
						'next' => 'stay',
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['a' => 'x']]]],
					],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * The objects the caller can reach, by id.
	 *
	 * @param array<int, string> $ids The ids.
	 *
	 * @return void
	 */
	private function reachable(array $ids): void {
		$this->objects->method('find')->willReturnCallback(
			static function (string $id) use ($ids): ?ObjectEntity {
				if (in_array($id, $ids, true) === false) {
					throw new \RuntimeException('not found');
				}

				$object = new ObjectEntity();
				$object->setUuid('uuid-'.$id);
				$object->setSchema('5');

				return $object;
			}
		);
	}//end reachable()

	/**
	 * A response as status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array<int, mixed> Status and body.
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * An object the caller cannot reach, or whose schema does not load, is 404 everywhere.
	 *
	 * @return void
	 */
	public function testAnUnreachableObjectIs404(): void {
		$controller = $this->controller(params: ['inputs' => []]);
		$this->reachable(ids: ['schemaless']);
		$this->macros->method('loadSchema')->willReturn(null);
		$this->runner->expects($this->never())->method('run');

		foreach (['gone', 'schemaless'] as $id) {
			$this->assertSame([404, ['error' => 'No such object']], $this->outcome($controller->index('r', 's', $id)));
			$this->assertSame([404, ['error' => 'No such object']], $this->outcome($controller->history('r', 's', $id)));
			$this->assertSame([404, ['error' => 'No such object']], $this->outcome($controller->invoke('r', 's', $id, 'assign')));
		}

		$this->assertSame([404, ['error' => 'No such schema']], $this->outcome($controller->selection('r', 's')));
	}//end testAnUnreachableObjectIs404()

	/**
	 * The list answers 403 without the right and 502 when a provider cannot be asked.
	 *
	 * @return void
	 */
	public function testTheListRefusals(): void {
		$controller = $this->controller(params: []);
		$this->reachable(ids: ['a']);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->list->method('forObject')->willReturnOnConsecutiveCalls(
			$this->throwException(new NotAuthorizedException('no read')),
			$this->throwException(new LifecycleProviderException('provider down')),
			[['key' => 'assign']]
		);
		$this->history->method('forObject')->willThrowException(new NotAuthorizedException('no history'));

		$this->assertSame([403, ['error' => 'no read']], $this->outcome($controller->index('r', 's', 'a')));
		$this->assertSame([502, ['error' => 'provider down']], $this->outcome($controller->index('r', 's', 'a')));
		$this->assertSame([200, ['actions' => [['key' => 'assign']]]], $this->outcome($controller->index('r', 's', 'a')));
		$this->assertSame([403, ['error' => 'no history']], $this->outcome($controller->history('r', 's', 'a')));
	}//end testTheListRefusals()

	/**
	 * A run answers the runner's own status and body.
	 *
	 * @return void
	 */
	public function testARunAnswersTheRunner(): void {
		$controller = $this->controller(params: ['inputs' => ['x' => 1]]);
		$this->reachable(ids: ['a']);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->runner->expects($this->once())->method('run')
			->with(
				$this->callback(static fn (ObjectActionTarget $target): bool => $target->subject() === ['uuid' => 'uuid-a', 'register' => 'r', 'schema' => 's']),
				'assign',
				['x' => 1]
			)
			->willReturn(['status' => 403, 'body' => ['error' => 'refused']]);

		$this->assertSame([403, ['error' => 'refused']], $this->outcome($controller->invoke('r', 's', 'a', 'assign')));
	}//end testARunAnswersTheRunner()

	/**
	 * A malformed selection is 400 before anything is loaded.
	 *
	 * @return void
	 */
	public function testAMalformedSelectionIs400(): void {
		$body = ['error' => 'Send the selection as a non-empty list "ids" and the inputs as an object "inputs".'];
		foreach ([['ids' => []], ['ids' => 'a'], ['ids' => ['a'], 'inputs' => 'x']] as $params) {
			$controller = $this->controller(params: $params);
			$this->macros->expects($this->never())->method('loadSchema');
			$this->assertSame([400, $body], $this->outcome($controller->invokeOnSelection('r', 's', 'assign')));
		}

		$unknown = $this->controller(params: ['ids' => ['a']]);
		$this->macros->method('loadSchema')->willReturn(null);
		$this->assertSame(
			[400, ['error' => 'Action "assign" is not offered on a selection.']],
			$this->outcome($unknown->invokeOnSelection('r', 's', 'assign'))
		);
	}//end testAMalformedSelectionIs400()

	/**
	 * Each selected object is run alone, and the rows say how each went.
	 *
	 * @return void
	 */
	public function testTheSelectionRows(): void {
		$controller = $this->controller(params: ['ids' => ['a', 'gone', 'b', 'c', 'd', 7], 'inputs' => ['k' => 'v']]);
		$this->reachable(ids: ['a', 'b', 'c', 'd', '7']);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->runner->method('run')->willReturnOnConsecutiveCalls(
			['status' => 200, 'body' => ['run' => 'r1', 'outcome' => 'done']],
			['status' => 202, 'body' => ['run' => 'r2', 'status' => 'queued']],
			['status' => 403, 'body' => ['message' => 'no right']],
			['status' => 422, 'body' => ['error' => 'bad input']],
			['status' => 409, 'body' => []]
		);

		$this->assertSame(
			[
				200,
				[
					'action' => 'assign',
					'summary' => ['succeeded' => 2, 'failed' => 4],
					'results' => [
						['id' => 'a', 'status' => 'succeeded', 'run' => 'r1', 'outcome' => 'done'],
						['id' => 'gone', 'status' => 'failed', 'reason' => 'No such object'],
						['id' => 'b', 'status' => 'succeeded', 'run' => 'r2', 'outcome' => 'queued'],
						['id' => 'c', 'status' => 'failed', 'reason' => 'no right'],
						['id' => 'd', 'status' => 'failed', 'reason' => 'bad input'],
						['id' => '7', 'status' => 'failed', 'reason' => 'refused'],
					],
					'next' => 'stay',
				],
			],
			$this->outcome($controller->invokeOnSelection('r', 's', 'assign'))
		);
	}//end testTheSelectionRows()

	/**
	 * A large selection the bulk job refuses answers 422 or 400.
	 *
	 * @return void
	 */
	public function testARefusedBulkJob(): void {
		$ids = array_map(static fn (int $n): string => 'id-'.$n, range(1, 101));
		$controller = $this->controller(params: ['ids' => $ids]);
		$this->macros->method('loadSchema')->willReturn($this->schema());
		$this->bulk->method('start')->willReturnOnConsecutiveCalls(
			$this->throwException(new BulkJobRefusedException('too many', 'limit')),
			$this->throwException(new InvalidArgumentException('bad inputs'))
		);
		$this->runner->expects($this->never())->method('run');

		$this->assertSame([422, ['error' => 'too many', 'reason' => 'limit']], $this->outcome($controller->invokeOnSelection('r', 's', 'assign')));
		$this->assertSame([400, ['error' => 'bad inputs']], $this->outcome($controller->invokeOnSelection('r', 's', 'assign')));
	}//end testARefusedBulkJob()
}//end class
