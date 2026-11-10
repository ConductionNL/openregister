<?php

/**
 * What the read side of RenderObject answers: write-only redaction, the system context, watcher markers and the object cache.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RenderContext;
use OCA\OpenRegister\Service\Object\RenderPath;
use OCA\OpenRegister\Service\Object\RenderPreloads;
use OCA\OpenRegister\Service\Object\RenderRun;
use OCA\OpenRegister\Service\Object\RenderSelection;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the read side of RenderObject.
 *
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class RenderObjectReadMarkersGoldenTest extends TestCase {

	/**
	 * A handler with only the given properties set.
	 *
	 * @param array<string, mixed> $values Property values by name.
	 *
	 * @return RenderObject
	 */
	private function handlerWith(array $values): RenderObject {
		$handler = (new ReflectionClass(RenderObject::class))->newInstanceWithoutConstructor();
		\Closure::bind(function (array $values): void {
			foreach ($values as $name => $value) {
				$this->$name = $value;
			}
		}, $handler, RenderObject::class)($values);

		return $handler;
	}

	/**
	 * Call a private method of a handler.
	 *
	 * @param RenderObject $handler The handler.
	 * @param string       $method  The method name.
	 * @param array        $args    The arguments.
	 *
	 * @return mixed
	 */
	private function call(RenderObject $handler, string $method, array $args): mixed {
		$reflected = new ReflectionMethod(RenderObject::class, $method);
		$reflected->setAccessible(true);

		return $reflected->invokeArgs($handler, $args);
	}

	/**
	 * An access-controlled read also takes write-only properties out of the `relations` copy.
	 *
	 * @return void
	 */
	public function testWriteOnlyRedactionReachesTheRelationsCopy(): void {
		$schema = new Schema();
		$schema->setProperties(['secret' => ['type' => 'string', 'writeOnly' => true], 'name' => ['type' => 'string']]);
		$handler = $this->handlerWith(['schemasCache' => [7 => $schema]]);
		$entity = new ObjectEntity();
		$entity->setSchema('7');
		$entity->setRelations(['secret' => 's', 'name' => 'n']);

		$result = $this->call($handler, 'redactWriteOnlyForRbac', [$this->renderRun($entity, null, ['secret' => 's', 'name' => 'n'])]);

		$this->assertSame(['name' => 'n'], $result);
		$this->assertSame(['name' => 'n'], $entity->getRelations());
	}

	/**
	 * An access-controlled render of one entity, at the given data.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param Schema|null  $schema The schema the phase reads.
	 * @param array        $data   The data so far.
	 *
	 * @return RenderRun
	 */
	private function renderRun(ObjectEntity $entity, ?Schema $schema, array $data): RenderRun {
		$context = new RenderContext(new RenderSelection([], [], [], []), new RenderPreloads(null, null, null), new RenderPath(0, []), true);
		$run = new RenderRun($entity, $context);
		$run->schema = $schema;
		$run->data = $data;

		return $run;
	}

	/**
	 * A schema with a property rule, and a rule handler that says whether it was asked.
	 *
	 * @param int $calls How often the property rule may be applied.
	 *
	 * @return RenderObject
	 */
	private function handlerExpectingRuleCalls(int $calls): RenderObject {
		$rules = $this->createMock(PropertyRbacHandler::class);
		$rules->expects($this->exactly($calls))->method('filterReadableProperties')->willReturnArgument(1);

		return $this->handlerWith(['propertyRbacHandler' => $rules, 'runtime' => new \OCA\OpenRegister\Service\Object\RenderRuntime(logger: new NullLogger(), extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions())]);
	}

	/**
	 * A schema whose property declares a read rule.
	 *
	 * @return Schema
	 */
	private function ruledSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string', 'authorization' => ['read' => ['group' => 'admin']]]]);

		return $schema;
	}

	/**
	 * A read strips by property rule outside a system operation, and not inside one.
	 *
	 * @return void
	 */
	public function testPropertyRulesApplyOutsideASystemOperationOnly(): void {
		$entity = new ObjectEntity();
		$schema = $this->ruledSchema();

		$this->call($this->handlerExpectingRuleCalls(1), 'stripUnreadableProperties', [$this->renderRun($entity, $schema, ['name' => 'n'])]);

		$handler = $this->handlerExpectingRuleCalls(0);
		SystemOperationContext::run(
			fn () => $this->call($handler, 'stripUnreadableProperties', [$this->renderRun($entity, $schema, ['name' => 'n'])])
		);
	}

	/**
	 * A handler whose container answers a watcher service.
	 *
	 * @param string|null $caller  The caller's uid.
	 * @param bool        $mayView Whether the caller may see the watchers.
	 *
	 * @return RenderObject
	 */
	private function watcherHandler(?string $caller, bool $mayView): RenderObject {
		$watchers = $this->createMock(WatcherService::class);
		$watchers->method('callerUid')->willReturn($caller);
		$watchers->method('isWatchedByCaller')->willReturn(true);
		$watchers->method('notifyForCaller')->willReturn(false);
		$watchers->method('maySeeWatchers')->willReturn($mayView);
		$watchers->method('watcherCount')->willReturn(3);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($watchers);

		return $this->handlerWith(['runtime' => new \OCA\OpenRegister\Service\Object\RenderRuntime(logger: new NullLogger(), extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(), container: $container)]);
	}

	/**
	 * Watcher markers per reader: nothing for an anonymous reader, the count only for an editor.
	 *
	 * @return void
	 */
	public function testWatcherMarkers(): void {
		$anonymous = new ObjectEntity();
		$anonymous->setUuid('u-1');
		$this->call($this->watcherHandler(null, true), 'applyWatcherMarkers', [$anonymous]);
		$this->assertNull($anonymous->getWatching());
		$this->assertNull($anonymous->getWatcherCount());

		$reader = new ObjectEntity();
		$reader->setUuid('u-2');
		$this->call($this->watcherHandler('alice', false), 'applyWatcherMarkers', [$reader]);
		$this->assertTrue($reader->getWatching());
		$this->assertFalse($reader->getWatchNotify());
		$this->assertNull($reader->getWatcherCount());

		$editor = new ObjectEntity();
		$editor->setUuid('u-3');
		$this->call($this->watcherHandler('alice', true), 'applyWatcherMarkers', [$editor]);
		$this->assertSame(3, $editor->getWatcherCount());
	}

	/**
	 * The object cache answers arrays under uuid keys and leaves out everything else.
	 *
	 * @return void
	 */
	public function testObjectsCacheKeepsArraysUnderUuids(): void {
		$uuid = '0192f0c1-1234-7abc-8def-0123456789ab';
		$other = '0192f0c1-1234-7abc-8def-0123456789ac';
		$empty = '0192f0c1-1234-7abc-8def-0123456789ad';
		$handler = $this->handlerWith(
			['objectsCache' => [$uuid => ['id' => $uuid], $other => 'not a row', $empty => [], 12 => ['id' => 12], 'slug' => ['id' => 'slug']]]
		);

		$this->assertSame([$uuid => ['id' => $uuid], $empty => []], $handler->getObjectsCache());
	}
}
