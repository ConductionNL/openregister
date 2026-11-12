<?php

/**
 * Golden test pinning how PlaceholderResolver reads date placeholders and offsets.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each placeholder is compared with the same date computed independently at
 * test time, so no date is pinned; strings that are not date placeholders
 * come back unchanged.
 *
 * @covers \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 */
final class PlaceholderResolverDateGoldenTest extends TestCase {

	/**
	 * Placeholder => [base modifier(s), offset modifier or null].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function dates(): array {
		$month = ['first day of this month', 'today'];
		return [
			'now' => ['$now', [], null],
			'now minus days' => ['$now-7d', [], '-7 days'],
			'now plus weeks' => ['$now+2w', [], '+2 weeks'],
			'now minus months' => ['$now-1m', [], '-1 months'],
			'now plus years' => ['$now+1y', [], '+1 years'],
			'now default unit' => ['$now+3', [], '+3 days'],
			'now zero offset' => ['$now+0', [], null],
			'start of day' => ['$startOfDay', ['today'], null],
			'start of day default unit' => ['$startOfDay-1', ['today'], '-1 days'],
			'start of week default unit' => ['$startOfWeek-2', ['monday this week'], '-2 weeks'],
			'start of month default unit' => ['$startOfMonth+1', $month, '+1 months'],
			'start of month explicit unit' => ['$startOfMonth-2d', $month, '-2 days'],
			'start of year default unit' => ['$startOfYear-1', ['first day of January {Y}', 'today'], '-1 years'],
			'unknown base with offset' => ['$foo-3d', [], '-3 days'],
		];
	}//end dates()

	/**
	 * Date placeholders resolve to the independently computed instant.
	 *
	 * @param string      $expr   Placeholder
	 * @param string[]    $base   Modifiers producing the base instant
	 * @param string|null $offset Offset modifier
	 *
	 * @return void
	 */
	#[DataProvider('dates')]
	public function testDates(string $expr, array $base, ?string $offset): void {
		$actual = (new PlaceholderResolver($this->createMock(IUserSession::class)))->resolve($expr);

		$expected = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
		foreach ($base as $modifier) {
			$expected = $expected->modify(str_replace('{Y}', $expected->format('Y'), $modifier));
		}

		if ($offset !== null) {
			$expected = $expected->modify($offset);
		}

		$this->assertInstanceOf(DateTimeImmutable::class, $actual);
		$this->assertSame($expected->getTimezone()->getName(), $actual->getTimezone()->getName());
		$this->assertLessThanOrEqual(2, abs($expected->getTimestamp() - $actual->getTimestamp()), $expr);
	}//end testDates()

	/**
	 * Strings that are not date placeholders come back unchanged.
	 *
	 * @return void
	 */
	public function testUnknownPlaceholdersPassThrough(): void {
		$resolver = new PlaceholderResolver($this->createMock(IUserSession::class));
		foreach (['$foo', '$now-3x', '$NOW', '$now-', '$now - 1', '$', '$startOfDay+1h'] as $expr) {
			$this->assertSame($expr, $resolver->resolve($expr), $expr);
		}
	}//end testUnknownPlaceholdersPassThrough()
}//end class
