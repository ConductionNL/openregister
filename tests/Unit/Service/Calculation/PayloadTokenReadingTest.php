<?php

/**
 * How both reference resolvers read a criterion token from the saving object's payload.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Token reading of ReferenceResolver and AggregateReferenceResolver.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\ReferenceResolver
 * @covers \OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver
 * @uses   \OCA\OpenRegister\Service\Calculation\CalculationPayload
 * @uses   \OCA\OpenRegister\Service\Calculation\PayloadPathWalking
 */
class PayloadTokenReadingTest extends TestCase {

	/**
	 * The saving object's payload.
	 *
	 * @var array<string, mixed>
	 */
	private const PAYLOAD = [
		'journeyDate' => '2024-03-05',
		'id' => 'data-id',
		'nested' => ['deep' => ['x' => 7]],
		'@self' => ['id' => 'sys-id', 'created' => '2021-12-31T23:00:00+00:00'],
		'@ref' => ['caseType' => ['term' => 'P8W']],
	];

	/**
	 * Token and the value it resolves to.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function tokens(): array {
		return [
			'plain string' => ['open', 'open'],
			'self system field' => ['@self.id', 'sys-id'],
			'self data field' => ['@self.journeyDate', '2024-03-05'],
			'self missing field' => ['@self.nope', null],
			'ref path' => ['@ref.caseType.term', 'P8W'],
			'ref missing' => ['@ref.caseType.none', null],
			'self deep path' => ['@self.created.x', null],
			'year of a date' => [['year' => '@self.journeyDate'], 2024],
			'year of a system date' => [['year' => '@self.created'], 2021],
			'year of a literal' => [['year' => '1999-01-01'], 1999],
			'year of nothing' => [['year' => '@self.nope'], null],
			'year of garbage' => [['year' => 'not a date'], null],
			'year of a number' => [['year' => 5], null],
			'other operator' => [['month' => '@self.journeyDate'], ['month' => '@self.journeyDate']],
			'two keys' => [['year' => 'x', 'b' => 1], ['year' => 'x', 'b' => 1]],
			'number' => [42, 42],
			'null' => [null, null],
		];
	}//end tokens()

	/**
	 * Both resolvers, built without their collaborators.
	 *
	 * @return list<object>
	 */
	private function resolvers(): array {
		return [
			(new ReflectionClass(ReferenceResolver::class))->newInstanceWithoutConstructor(),
			new AggregateReferenceResolver($this->createMock(AggregationRunner::class), $this->createMock(LoggerInterface::class)),
		];
	}//end resolvers()

	/**
	 * Each resolver reads each token the same way.
	 *
	 * @param mixed $token    The token.
	 * @param mixed $expected The value.
	 *
	 * @return void
	 */
	#[DataProvider('tokens')]
	public function testTokenResolves(mixed $token, mixed $expected): void {
		foreach ($this->resolvers() as $resolver) {
			$method = new ReflectionMethod($resolver, 'resolveToken');
			$this->assertSame($expected, $method->invoke($resolver, self::PAYLOAD, $token), get_class($resolver));
		}
	}//end testTokenResolves()

	/**
	 * A nested path outside `@self` walks the payload.
	 *
	 * @return void
	 */
	public function testReadPathWalksNestedKeys(): void {
		foreach ($this->resolvers() as $resolver) {
			$method = new ReflectionMethod($resolver, 'readPath');
			$this->assertSame(7, $method->invoke($resolver, self::PAYLOAD, 'nested.deep.x'));
			$this->assertNull($method->invoke($resolver, self::PAYLOAD, 'nested.deep.y'));
			$this->assertNull($method->invoke($resolver, ['@self' => 'flat', 'id' => 'top'], '@self.id.more'));
			$this->assertSame('top', $method->invoke($resolver, ['@self' => 'flat', 'id' => 'top'], '@self.id'));
		}
	}//end testReadPathWalksNestedKeys()
}//end class
