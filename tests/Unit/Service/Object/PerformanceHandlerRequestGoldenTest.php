<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\PerformanceHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins PerformanceHandler's request classification and rewriting: isSimpleRequest,
 * optimizeExtendQueries and optimizeRequestForPerformance (query after, timing key,
 * log calls), for a table of queries.
 *
 * @covers \OCA\OpenRegister\Service\Object\PerformanceHandler
 */
class PerformanceHandlerRequestGoldenTest extends TestCase {

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function queries(): array {
		return [
			'empty'               => [[]],
			'two extends array'   => [['_extend' => ['a', 'b']]],
			'three extends'       => [['_extend' => ['a', 'b', 'c']]],
			'string extends'      => [['_extend' => 'a, b, ,c']],
			'string two'          => [['_extend' => 'a,b']],
			'blank string'        => [['_extend' => '  ']],
			'facets'              => [['_facets' => ['x']]],
			'facetable'           => [['_facetable' => true]],
			'facetable false'     => [['_facetable' => false]],
			'limit 50'            => [['_limit' => 50]],
			'limit 51'            => [['_limit' => 51]],
			'three filters'       => [['a' => 1, 'b' => 2, 'c' => 3, '_x' => 1, '@self' => 1]],
			'four filters'        => [['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]],
			'string reduced'      => [['_extend' => 'a,,b']],
		];
	}//end queries()

	private const FIXTURE = __DIR__ . '/fixtures/performance-handler-request-golden.json';

	public function testRequestHandlingMatchesGolden(): void {
		$actual = [];
		foreach (self::queries() as $name => [$query]) {
			$calls = [];
			$handler = $this->handler(calls: $calls);
			$timings = ['other' => 1];
			$rewritten = $query;
			$error = null;
			try {
				$handler->optimizeRequestForPerformance($rewritten, $timings);
			} catch (\Throwable $e) {
				$error = get_class($e) . ': ' . $e->getMessage();
			}

			$actual[$name] = [
				'error' => $error,
				'simple' => $handler->isSimpleRequest($query),
				'rewritten' => $rewritten,
				'timingKeys' => array_keys($timings),
				'timingIsFloat' => is_float($timings['request_optimization'] ?? null),
				'logs' => $calls,
			];
		}

		$calls = [];
		$handler = $this->handler(calls: $calls);
		$actual['optimizeExtendQueries'] = [
			$handler->optimizeExtendQueries('   '),
			$handler->optimizeExtendQueries('a, b, ,c'),
			$handler->optimizeExtendQueries(['x', 'y']),
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testRequestHandlingMatchesGolden()

	private function handler(array &$calls): PerformanceHandler {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, $context = []) use (&$calls, $level) {
				unset($context['line'], $context['file']);
				$calls[] = [$level, $message, $context];
			});
		}

		$args = [];
		foreach ((new \ReflectionMethod(PerformanceHandler::class, '__construct'))->getParameters() as $param) {
			$type = $param->getType()->getName();
			$args[] = $type === LoggerInterface::class ? $logger : $this->createMock($type);
		}

		return new PerformanceHandler(...$args);
	}//end handler()
}//end class
