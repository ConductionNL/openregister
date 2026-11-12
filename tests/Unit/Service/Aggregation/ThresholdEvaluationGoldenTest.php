<?php

/**
 * Golden test pinning one threshold evaluation end to end: runner call,
 * comparison, dispatch payload and state write.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Aggregation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCA\OpenRegister\Search\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every early return, the comparison table, a missing or failing state
 * cache, and the exact calls made.
 *
 * @covers \OCA\OpenRegister\Search\Service\Aggregation\ThresholdEvaluationService
 */
final class ThresholdEvaluationGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run one evaluation.
	 *
	 * @param array<string, mixed> $trigger  Trigger block
	 * @param mixed                $value    Aggregation result value
	 * @param string|null          $oldState Cached state
	 * @param string               $cache    'ok', 'none' (factory throws) or 'failset'
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function evaluateOnce(array $trigger, mixed $value, ?string $oldState, string $cache = 'ok'): array {
		$runner = $this->createMock(AggregationRunnerInterface::class);
		$runner->method('run')->willReturnCallback(
			function (NamedAggregationRef $ref, ?NamedAggregationOptions $options = null) use ($value): array {
				$this->calls[] = ['run', [$ref->register, $ref->schema, $ref->name, $options?->bypassRbac ?? false, $options?->parentRow ?? [], $options?->extraFilter ?? []]];
				return ['value' => $value];
			}
		);
		$dispatcher = $this->createMock(NotificationRuleDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (ObjectEntity $object, string $kind, array $payload): void {
				$this->calls[] = ['dispatch', $object->getUuid(), $kind, $payload];
			}
		);
		$state = $this->createMock(ICache::class);
		$state->method('get')->willReturnCallback(
			function (string $key) use ($oldState) {
				$this->calls[] = ['get', $key];
				return $oldState;
			}
		);
		$state->method('set')->willReturnCallback(
			function (string $key, $val, int $ttl) use ($cache): bool {
				$this->calls[] = ['set', $key, $val, $ttl];
				if ($cache === 'failset') {
					throw new RuntimeException('cache down');
				}

				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		if ($cache === 'none') {
			$factory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));
		} else {
			$factory->method('createDistributed')->willReturn($state);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['warning', $message];
			}
		);

		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('s');
		$schema->setConfiguration(['x-openregister-notifications' => ['n1' => ['trigger' => ['type' => 'threshold'] + $trigger]]]);
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setRegister('r');

		(new ThresholdEvaluationService($runner, $dispatcher, $logger, $factory))->evaluateSchema($schema, $object);
		return $this->calls;
	}//end evaluateOnce()

	/**
	 * Triggers missing a field never reach the runner.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function incomplete(): array {
		return [
			'no aggregation' => [['op' => 'gt', 'value' => 1]],
			'empty op' => [['aggregation' => 'a', 'op' => '', 'value' => 1]],
			'null value' => [['aggregation' => 'a', 'op' => 'gt', 'value' => null]],
		];
	}//end incomplete()

	/**
	 * Incomplete trigger: nothing happens.
	 *
	 * @param array<string, mixed> $trigger Trigger block
	 *
	 * @return void
	 */
	#[DataProvider('incomplete')]
	public function testIncompleteTrigger(array $trigger): void {
		$this->assertSame([], $this->evaluateOnce($trigger, 10, null));
	}//end testIncompleteTrigger()

	/**
	 * A non-numeric aggregation value stops after the runner.
	 *
	 * @return void
	 */
	public function testNonNumericValue(): void {
		$calls = $this->evaluateOnce(['aggregation' => 'a', 'op' => 'gt', 'value' => 1], '10', null);
		$this->assertSame([['run', ['r', 's', 'a', true, [], []]]], $calls);
	}//end testNonNumericValue()

	/**
	 * Comparison cases: [op, actual, threshold, above].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function comparisons(): array {
		return [
			'gt true' => ['gt', 6, 5, true],
			'gt equal' => ['gt', 5, 5, false],
			'gte equal' => ['gte', 5, '5', true],
			'lt' => ['lt', 4.5, 5, true],
			'lte' => ['lte', 6, 5, false],
			'eq int/float' => ['eq', 5, 5.0, true],
			'ne' => ['ne', 5, 5, false],
			'unknown op' => ['between', 5, 1, false],
			'non-numeric threshold' => ['gt', 9, 'many', false],
		];
	}//end comparisons()

	/**
	 * Rising edge dispatches with the full payload; the state is always written.
	 *
	 * @param string $op        Operator
	 * @param mixed  $actual    Aggregation value
	 * @param mixed  $threshold Threshold
	 * @param bool   $above     Expected comparison outcome
	 *
	 * @return void
	 */
	#[DataProvider('comparisons')]
	public function testComparisons(string $op, mixed $actual, mixed $threshold, bool $above): void {
		$calls = $this->evaluateOnce(['aggregation' => 'a', 'op' => $op, 'value' => $threshold], $actual, 'below');

		$expected = [['run', ['r', 's', 'a', true, [], []]], ['get', 'threshold:42:n1']];
		if ($above === true) {
			$expected[] = ['dispatch', 'o-1', 'threshold', ['notificationName' => 'n1', 'aggregation' => 'a', 'value' => $actual, 'threshold' => $threshold, 'op' => $op]];
		}

		$expected[] = ['set', 'threshold:42:n1', $above ? 'above' : 'below', 2592000];
		$this->assertSame($expected, $calls);
	}//end testComparisons()

	/**
	 * Without a state cache every above evaluation dispatches.
	 *
	 * @return void
	 */
	public function testNoCacheAlwaysDispatches(): void {
		$calls = $this->evaluateOnce(['aggregation' => 'a', 'op' => 'gt', 'value' => 1], 2, 'above', 'none');
		$this->assertSame(['run', 'dispatch'], array_column($calls, 0));
	}//end testNoCacheAlwaysDispatches()

	/**
	 * A failing state write is swallowed, nothing is logged.
	 *
	 * @return void
	 */
	public function testFailingStateWriteIsSwallowed(): void {
		$calls = $this->evaluateOnce(['aggregation' => 'a', 'op' => 'gt', 'value' => 1], 2, 'above', 'failset');
		$this->assertSame(['run', 'get', 'set', 'warning'], array_column($calls, 0));
	}//end testFailingStateWriteIsSwallowed()

	/**
	 * Which declaration blocks count as having a threshold notification.
	 *
	 * @return void
	 */
	public function testHasThresholdNotifications(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$service = new ThresholdEvaluationService(
			$this->createMock(AggregationRunnerInterface::class),
			$this->createMock(NotificationRuleDispatcherInterface::class),
			$this->createMock(LoggerInterface::class),
			$factory
		);
		$blocks = [
			null,
			'not-a-list',
			[],
			['a' => 'x', 'b' => ['trigger' => 'x'], 'c' => ['trigger' => ['type' => 'event']]],
			['a' => ['trigger' => ['type' => 'event']], 'b' => ['trigger' => ['type' => 'threshold']]],
			[['trigger' => ['type' => 'threshold']]],
		];
		$answers = [];
		foreach ($blocks as $block) {
			$schema = new Schema();
			$config = [];
			if ($block !== null) {
				$config['x-openregister-notifications'] = $block;
			}

			$schema->setConfiguration($config);
			$answers[] = $service->hasThresholdNotifications($schema);
		}

		$this->assertSame([false, false, false, false, true, true], $answers);
	}//end testHasThresholdNotifications()
}//end class
