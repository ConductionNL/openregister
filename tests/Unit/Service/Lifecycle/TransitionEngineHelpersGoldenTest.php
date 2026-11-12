<?php

/**
 * Golden behaviour of TransitionEngine's private normalisers.
 *
 * Pins the slug fallback and its debug records when both lookups fail, the
 * provider entries that are dropped, the sibling read (incomplete graph,
 * non-entity rows, order with uuid tiebreak) and the option normalisation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWriteBoundary;
use OCA\OpenRegister\Service\Lifecycle\TransitionDispatch;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Lifecycle\TransitionPlatform;
use OCA\OpenRegister\Service\Lifecycle\TransitionSubjects;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * TransitionEngine private helpers, golden.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\TransitionEngine
 */
final class TransitionEngineHelpersGoldenTest extends TestCase {

	/**
	 * Every log record, as [level, message, context].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: array}>
	 */
	private array $records = [];

	/**
	 * Every findAll config the engine asked for.
	 *
	 * @var array<int, array>
	 */
	private array $reads = [];

	/**
	 * An engine with the slug contract on, failing lookups and a sibling read that answers $rows.
	 *
	 * @param array $rows What findAll answers.
	 *
	 * @return TransitionEngine The engine.
	 */
	private function engine(array $rows = []): TransitionEngine {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('yes');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('no register'));
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willThrowException(new RuntimeException('no schema'));
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->reads[] = $config;

				return $rows;
			}
		);
		$logger = new class ($this->records) extends AbstractLogger {
			/**
			 * @param array $records The test's record list.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [$level, (string) $message, $context];
			}
		};

		return new TransitionEngine(
			new TransitionSubjects($objects, new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class))),
			new TransitionDispatch($this->createMock(IEventDispatcher::class), new LifecycleWriteBoundary(new LifecycleActionContext()), $this->createMock(LifecycleActionProviderRegistry::class)),
			new TransitionPlatform($this->createMock(IUserSession::class), $appConfig, $logger),
			$this->createMock(PermissionHandler::class)
		);
	}//end engine()

	/**
	 * Call a private method.
	 *
	 * @param TransitionEngine $engine The engine.
	 * @param string           $method The method.
	 * @param array            $args   Its arguments.
	 *
	 * @return mixed What it answers.
	 */
	private static function call(TransitionEngine $engine, string $method, array $args): mixed {
		return (new ReflectionMethod(TransitionEngine::class, $method))->invoke($engine, ...$args);
	}//end call()

	/**
	 * An object with a uuid and an order.
	 *
	 * @param string    $uuid  The uuid.
	 * @param int|float $order The order value.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function sibling(string $uuid, int|float $order): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['pos' => $order]);

		return $object;
	}//end sibling()

	/**
	 * Both slug lookups failing keep both ids and leave one debug record each, register first.
	 *
	 * @return void
	 */
	public function testFailingSlugLookupsKeepTheIdsAndLogEach(): void {
		$object = new ObjectEntity();
		$object->setRegister('17');
		$object->setSchema('116');

		$scope = self::call($this->engine(), 'transitionEventScope', [$object]);

		$this->assertSame(['register' => '17', 'schema' => '116'], $scope);
		$this->assertSame(
			[
				['debug', '[TransitionEngine] Register slug not resolved, keeping the id', ['register' => '17', 'exception' => 'no register']],
				['debug', '[TransitionEngine] Schema slug not resolved, keeping the id', ['schema' => '116', 'exception' => 'no schema']],
			],
			$this->records
		);
	}//end testFailingSlugLookupsKeepTheIdsAndLogEach()

	/**
	 * A provider entry that is no array, or names no action, is dropped.
	 *
	 * @return void
	 */
	public function testProviderEntriesThatAreNoActionAreDropped(): void {
		$published = self::call($this->engine(), 'publishProviderActions', [['junk', ['to' => 'x'], ['action' => 'go', 'to' => 'done']]]);

		$this->assertSame([['action' => 'go', 'to' => 'done', 'requires' => null, 'description' => null, 'inputs' => []]], $published);
	}//end testProviderEntriesThatAreNoActionAreDropped()

	/**
	 * An incomplete graph reads nothing; a complete one reads by schema, parent and order.
	 *
	 * @return void
	 */
	public function testTheSiblingReadNeedsSchemaAndParentField(): void {
		$engine = $this->engine(rows: [self::sibling('b', 1)]);

		$this->assertSame([], self::call($engine, 'fetchOrderedSiblings', [['schema' => 'step', 'orderField' => 'pos'], 'p-1']));
		$this->assertSame([], self::call($engine, 'fetchOrderedSiblings', [['parentField' => 'case', 'orderField' => 'pos'], 'p-1']));
		$this->assertSame([], $this->reads);

		self::call($engine, 'fetchOrderedSiblings', [['schema' => 'step', 'parentField' => 'case', 'orderField' => 'pos'], 'p-1']);
		$this->assertSame([['filters' => ['schema' => 'step', 'case' => 'p-1'], 'sort' => ['pos' => 'ASC']]], $this->reads);
	}//end testTheSiblingReadNeedsSchemaAndParentField()

	/**
	 * Rows that are no entity are dropped; equal orders fall back to the uuid.
	 *
	 * @return void
	 */
	public function testSiblingsAreEntitiesOrderedWithAUuidTiebreak(): void {
		$engine = $this->engine(rows: [self::sibling('c', 2), ['uuid' => 'array-row'], self::sibling('b', 1), self::sibling('a', 2), 'text-row']);

		$siblings = self::call($engine, 'fetchOrderedSiblings', [['schema' => 'step', 'parentField' => 'case', 'orderField' => 'pos'], 'p-1']);

		$this->assertSame(['b', 'a', 'c'], array_map(static fn (ObjectEntity $o): ?string => $o->getUuid(), $siblings));
		$this->assertSame([0, 1, 2], array_keys($siblings));
	}//end testSiblingsAreEntitiesOrderedWithAUuidTiebreak()

	/**
	 * Options: non-array declarations publish nothing; blank or non-scalar values drop;
	 * ints are stringified; a blank label falls back to the value.
	 *
	 * @return void
	 */
	public function testOptionsAreNormalised(): void {
		$engine = $this->engine();

		$this->assertSame([], self::call($engine, 'publishedOptions', ['a,b']));
		$this->assertSame(
			[
				['value' => '1', 'label' => 'One'],
				['value' => 'x ', 'label' => 'x'],
				['value' => 'y', 'label' => 'y'],
			],
			self::call($engine, 'publishedOptions', [[['value' => 1, 'label' => ' One '], ['value' => 'x ', 'label' => '  '], ['value' => '  '], ['value' => 1.5], 'loose', ['value' => 'y']]])
		);
	}//end testOptionsAreNormalised()
}//end class
