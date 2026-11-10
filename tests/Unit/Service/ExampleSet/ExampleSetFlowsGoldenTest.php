<?php

/**
 * Golden test for ExampleSetFlows: what refusal() answers for a fixed table
 * of flow declarations, and what update(), lost() and revert() answer and
 * call on the flow mapper and services, including the fields update() sets.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 */
class ExampleSetFlowsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-flows-golden.json';

	/**
	 * @var array<int, mixed> Every call made on the fakes, in order.
	 */
	private array $calls = [];

	/**
	 * @var array<string, mixed> How the fakes behave in the running case.
	 */
	private array $world = [];

	public function testFlowsMatchGolden(): void {
		$actual = [];
		foreach ($this->refusals() as $name => $data) {
			$actual['refusal '.$name] = $this->subject()->refusal($data, []);
		}

		foreach ($this->updates() as $name => $case) {
			$this->calls = [];
			$this->world = $case['world'];
			$answer      = $this->subject()->update($case['entry'], new ExampleSetRow('zaak', 'intake', $case['data']));
			$actual['update '.$name] = ['answer' => $answer, 'calls' => $this->calls];
		}

		$worlds = ['there' => [], 'gone' => ['missing' => true], 'down' => ['broken' => true], 'delete fails' => ['deleteFails' => true]];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			$this->world = $world;
			$subject     = $this->subject();
			$lost        = $subject->lost(['uuid' => 'f-1']);
			$actual['entry '.$name] = ['lost' => $lost, 'revert' => $subject->revert(['uuid' => 'f-1']), 'calls' => $this->calls];
		}

		$this->assertGolden($actual);
	}

	private function assertGolden(array $actual): void {
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame(json_encode($outcome), json_encode($actual[$name]), $name);
		}
	}

	private function subject(): ExampleSetFlows {
		$services  = [
			FlowMapper::class         => $this->flowMapper(),
			FlowVersionService::class => $this->versions(),
			FlowService::class        => $this->flowService(),
			OrganisationServiceInterface::class => $this->createMock(OrganisationServiceInterface::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new ExampleSetFlows($container);
	}

	private function storedFlow(): Flow {
		$flow = new Flow();
		$flow->setUuid('f-1');
		$flow->setName('Intake');
		$flow->setExecutionMode(Flow::MODE_ASYNC);
		$flow->setNodes([['id' => 'a', 'type' => 'start']]);
		$flow->setEdges([]);
		$flow->setAnnotations([]);
		$flow->setLimits([]);

		return $flow;
	}

	private function flowMapper(): FlowMapper {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(function (string $uuid): Flow {
			$this->calls[] = ['findByUuid', $uuid];
			if (($this->world['missing'] ?? false) === true) {
				throw new DoesNotExistException('no flow');
			}

			if (($this->world['broken'] ?? false) === true) {
				throw new RuntimeException('database is gone');
			}

			return $this->storedFlow();
		});
		$mapper->method('update')->willReturnCallback(function (Flow $flow): Flow {
			$this->calls[] = ['update', $this->fields($flow)];
			if (($this->world['updateFails'] ?? false) === true) {
				throw new RuntimeException('update refused');
			}

			return $flow;
		});
		$mapper->method('insert')->willReturnCallback(function (Flow $flow): Flow {
			$this->calls[] = ['insert'];
			throw new RuntimeException('no inserts in this test');
		});

		return $mapper;
	}

	private function versions(): FlowVersionService {
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('publishShippedUpdate')->willReturnCallback(function (Flow $flow) {
			$this->calls[] = ['publishShippedUpdate', $flow->getUuid()];
			return null;
		});

		return $versions;
	}

	private function flowService(): FlowService {
		$service = $this->createMock(FlowService::class);
		$service->method('deleteStored')->willReturnCallback(function (Flow $flow): void {
			$this->calls[] = ['deleteStored', $flow->getUuid()];
			if (($this->world['deleteFails'] ?? false) === true) {
				throw new RuntimeException('delete refused');
			}
		});

		return $service;
	}

	/**
	 * The declarable fields of a flow and whether its updated moment is set.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(Flow $flow): array {
		return [
			'name'            => $flow->getName(),
			'description'     => $flow->getDescription(),
			'trigger'         => $flow->getTrigger(),
			'triggerRegister' => $flow->getTriggerRegister(),
			'triggerSchema'   => $flow->getTriggerSchema(),
			'cron'            => $flow->getCron(),
			'executionMode'   => $flow->getExecutionMode(),
			'nodes'           => $flow->getNodes(),
			'edges'           => $flow->getEdges(),
			'annotations'     => $flow->getAnnotations(),
			'limits'          => $flow->getLimits(),
			'applicationSlug' => $flow->getApplicationSlug(),
			'updatedIsSet'    => ($flow->getUpdated() !== null),
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function refusals(): array {
		$ok = [
			'name'  => 'Intake',
			'nodes' => [['id' => 'a', 'type' => 'start'], ['id' => 'b', 'type' => 'end']],
			'edges' => [['from' => 'a', 'to' => 'b']],
		];
		return [
			'ok'                => $ok,
			'unknown fields'    => $ok + ['colour' => 'red', 'owner' => 'x'],
			'no name'           => ['nodes' => $ok['nodes']],
			'blank name'        => ['name' => ' '] + $ok,
			'name not text'     => ['name' => 3] + $ok,
			'no nodes'          => ['name' => 'Intake'],
			'empty nodes'       => ['name' => 'Intake', 'nodes' => []],
			'nodes text'        => ['name' => 'Intake', 'nodes' => 'a'],
			'node without type' => ['name' => 'Intake', 'nodes' => [['id' => 'a']]],
			'node id number'    => ['name' => 'Intake', 'nodes' => [['id' => 1, 'type' => 'x']]],
			'node object'       => ['name' => 'Intake', 'nodes' => [(object) ['id' => 'a', 'type' => 'x']]],
			'edge to nowhere'   => ['edges' => [['from' => 'a', 'to' => 'z']]] + $ok,
			'edge from nowhere' => ['edges' => [['to' => 'a']]] + $ok,
			'edge object'       => ['edges' => [(object) ['from' => 'a', 'to' => 'b']]] + $ok,
			'edges scalar'      => ['edges' => 'a'] + $ok,
			'publish text'      => $ok + ['publish' => 'yes'],
			'enabled number'    => $ok + ['enabled' => 1],
			'publish null'      => $ok + ['publish' => null],
			'slug number'       => $ok + ['applicationSlug' => 5],
			'slug text'         => $ok + ['applicationSlug' => 'zaak'],
			'on unpublished'    => $ok + ['enabled' => true, 'publish' => false],
			'on published'      => $ok + ['enabled' => true, 'publish' => true],
			'on default'        => $ok + ['enabled' => true],
			'off unpublished'   => $ok + ['enabled' => false, 'publish' => false],
			'runs text'         => $ok + ['runs' => 'x'],
			'runs bad'          => $ok + ['runs' => [['status' => 'nope']]],
		];
	}

	/**
	 * @return array<string, array{world: array<string, mixed>, entry: array<string, mixed>, data: array<string, mixed>}>
	 */
	private function updates(): array {
		$same  = ['name' => 'Intake', 'nodes' => [['id' => 'a', 'type' => 'start']]];
		$other = [
			'name'            => 'Intake v2',
			'description'     => 'Uses {{flow:self.uuid}} here',
			'trigger'         => 'object.created',
			'triggerRegister' => 'zaken',
			'triggerSchema'   => 'zaak',
			'cron'            => '0 * * * *',
			'executionMode'   => 'sync',
			'nodes'           => [['id' => 'a', 'type' => 'start', 'config' => ['self' => '{{flow:self.uuid}}', 'n' => 3]]],
			'edges'           => [['from' => 'a', 'to' => 'a']],
			'annotations'     => [['text' => 'note']],
			'limits'          => ['runs' => 5],
			'applicationSlug' => 'zaak',
		];
		$entry = ['uuid' => 'f-1', 'runs' => ['r1' => 'run-1'], 'waitsOn' => ['run-1' => 'wait'], 'other' => 'dropped'];
		return [
			'unchanged'           => ['world' => [], 'entry' => $entry, 'data' => $same],
			'changed'             => ['world' => [], 'entry' => $entry, 'data' => $other],
			'changed unpublished' => ['world' => [], 'entry' => $entry, 'data' => $other + ['publish' => false]],
			'changed publish on'  => ['world' => [], 'entry' => ['uuid' => 'f-1'], 'data' => $other + ['publish' => true]],
			'update fails'        => ['world' => ['updateFails' => true], 'entry' => $entry, 'data' => $other],
			'gone rewrites'       => ['world' => ['missing' => true], 'entry' => $entry, 'data' => $same],
			'no uuid'             => ['world' => ['missing' => true], 'entry' => [], 'data' => $same],
		];
	}
}
