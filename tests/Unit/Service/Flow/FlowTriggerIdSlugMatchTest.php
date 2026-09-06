<?php

/**
 * An object saved by id matches a flow declared by slug — end to end.
 *
 * 🔴 THE MEASURED DEFECT, REPRODUCED AT THE SEAM IT LIVED IN. On a clean
 * instance (dossiq 0.3.11-unstable, openregister 2.0.13-unstable) an imported
 * `x-openregister-flows` flow sat enabled and owned while three case creations
 * queued nothing: the listener fired the object's numeric ids (`16`/`26`) and
 * the trigger index held the declaration's slugs (`dossiq`/`case`), so the
 * indexed lookup compared `16` to `dossiq` forever. This test wires the REAL
 * listener, trigger service and locator together over mapper doubles, and
 * asserts the one thing the instance could not do: an id-keyed event queues a
 * run for a slug-declared flow.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\FlowTriggerListener;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTriggerService;
use OCA\OpenRegister\Service\Flow\FlowTriggerSlugs;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Listener\FlowTriggerListener
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerService
 * @covers \OCA\OpenRegister\Service\Flow\FlowLocator
 * @covers \OCA\OpenRegister\Service\Flow\FlowTriggerSlugs
 *
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\FlowVersion
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ObjectCreatedEvent
 */
class FlowTriggerIdSlugMatchTest extends TestCase {

	/**
	 * Every queue() call's flowId.
	 *
	 * @var array<int, string>
	 */
	private array $queued = [];

	/**
	 * What flowUuidsFor() was asked for.
	 *
	 * @var array<int, array{event: string, register: string, schema: string}>
	 */
	private array $asked = [];

	/**
	 * The real listener → trigger service → locator chain over doubles.
	 *
	 * The trigger index double holds ONE row, keyed the way an imported
	 * declaration writes it: `object.created` on `dossiq`/`case`. It honours
	 * its arguments — asking for `16`/`26` answers nothing, exactly like the
	 * real indexed lookup — so the pre-fix listener turns this red.
	 */
	private function listener(): FlowTriggerListener {
		$triggerMapper = $this->createMock(FlowTriggerMapper::class);
		$triggerMapper->method('flowUuidsFor')->willReturnCallback(
			function (string $event, string $register, string $schema): array {
				$this->asked[] = ['event' => $event, 'register' => $register, 'schema' => $schema];
				if ($event === 'object.created' && $register === 'dossiq' && $schema === 'case') {
					return ['flow-imported'];
				}

				return [];
			}
		);
		$triggerMapper->method('representedFlowUuids')->willReturn(['flow-imported']);

		$flow = new Flow();
		$flow->setUuid('flow-imported');
		$flow->setEnabled(true);
		$flow->setOwner('alice');
		$flow->setExecutionMode('async');
		$flow->setNodes([]);
		$flow->setEdges([]);

		$flowMapper = $this->createMock(FlowMapper::class);
		$flowMapper->method('findByTrigger')->willReturn([]);
		$flowMapper->method('findByUuid')->willReturn($flow);

		$publishedVersion = new FlowVersion();
		$publishedVersion->setStatus(FlowVersion::STATUS_PUBLISHED);
		$versionMapper = $this->createMock(FlowVersionMapper::class);
		$versionMapper->method('findPublished')->willReturn($publishedVersion);

		$locator = new FlowLocator(
			$flowMapper,
			$triggerMapper,
			$this->createMock(ObjectService::class),
			new NullLogger(),
			$versionMapper
		);

		$runner = $this->createMock(FlowRunService::class);
		$runner->method('queue')->willReturnCallback(
			function (string $flowId): FlowRun {
				$this->queued[] = $flowId;
				$run = new FlowRun();
				$run->setUuid('run-1');

				return $run;
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ((string)$id !== '16') {
					throw new DoesNotExistException('no such register');
				}

				$register = new Register();
				$register->setSlug('dossiq');

				return $register;
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				if ((string)$id !== '26') {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug('case');

				return $schema;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new FlowTriggerListener(
			new FlowTriggerService($locator, $runner, new NullLogger()),
			$session,
			new FlowTriggerSlugs($registers, $schemas, new NullLogger())
		);
	}

	public function testAnObjectSavedByIdQueuesARunForASlugDeclaredFlow(): void {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('16');
		$object->setSchema('26');

		$this->listener()->handle(new ObjectCreatedEvent($object));

		$this->assertSame(
			['flow-imported'],
			$this->queued,
			'three case creations on the live instance queued nothing; an id-keyed event must reach a slug-declared flow'
		);
		$this->assertSame(
			[['event' => 'object.created', 'register' => 'dossiq', 'schema' => 'case']],
			$this->asked,
			'the indexed lookup must be asked in the slug vocabulary the index stores'
		);
	}

	public function testAnObjectAlreadyCarryingSlugsStillMatches(): void {
		$object = new ObjectEntity();
		$object->setUuid('case-2');
		$object->setRegister('dossiq');
		$object->setSchema('case');

		$this->listener()->handle(new ObjectCreatedEvent($object));

		$this->assertSame(['flow-imported'], $this->queued, 'slug-keyed events must keep matching: resolution is idempotent');
	}
}
