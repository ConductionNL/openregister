<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins bulkLoadRelationshipsBatched(): the 200-id cap, the batches of 50, the
 * index by uuid and id, a failing batch, and the log calls (timings removed),
 * against fixtures/relation-handler-bulk-load-golden.json. UPDATE_GOLDEN=1 rewrites it.
 *
 * @covers \OCA\OpenRegister\Service\Object\RelationHandler
 * @uses   \OCA\OpenRegister\Service\Object\RelationLoadRun
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class RelationHandlerBulkLoadGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/relation-handler-bulk-load-golden.json';

	public function testBulkLoadMatchesGolden(): void {
		$ids = static fn (int $n, string $p) => array_map(fn ($i) => $p . $i, range(1, $n));
		$actual = [];
		foreach (['none' => [], 'three' => $ids(3, 'u'), 'capped' => $ids(230, 'u'), 'failing' => array_merge($ids(60, 'u'), ['boom'])] as $name => $request) {
			$calls = [];
			$loaded = $this->handler(calls: $calls)->bulkLoadRelationshipsBatched($request);
			$actual[$name] = ['keys' => array_keys($loaded), 'calls' => $calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testBulkLoadMatchesGolden()

	private function handler(array &$calls): RelationHandler {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('findAll')->willReturnCallback(function (...$args) use (&$calls) {
			$position = 0;
			foreach ((new ReflectionMethod(MagicMapperInterface::class, 'findAll'))->getParameters() as $i => $param) {
				$position = $param->getName() === 'ids' ? $i : $position;
			}

			$ids = $args['ids'] ?? $args[$position] ?? [];
			$calls[] = ['findAll', count($ids), $ids[0] ?? null];
			if (in_array('boom', $ids, true) === true) {
				throw new RuntimeException('db');
			}

			return array_map(function ($id) {
				$o = new ObjectEntity();
				$o->setUuid($id);
				$o->setId((int)substr($id, 1) + 1000);
				return $o;
			}, $ids);
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, $context = []) use (&$calls, $level) {
				unset($context['file'], $context['line'], $context['batchTime'], $context['totalTime'], $context['avgPerBatch']);
				$calls[] = [$level, $message, $context];
			});
		}

		$args = [];
		foreach ((new ReflectionMethod(RelationHandler::class, '__construct'))->getParameters() as $param) {
			$type = $param->getType()->getName();
			$args[] = match ($type) {
				MagicMapperInterface::class => $mapper,
				LoggerInterface::class => $logger,
				RelationHandlerStores::class => new RelationHandlerStores(
					objects: $mapper,
					schemas: $this->createMock(SchemaMapperInterface::class),
					registers: $this->createMock(RegisterMapperInterface::class)
				),
				default => $this->createMock($type),
			};
		}

		return new RelationHandler(...$args);
	}//end handler()
}//end class
