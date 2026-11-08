<?php

/**
 * Golden test for the calendar-provider save check moved behind CalendarDateDeclarationCheck.
 *
 * ConfigurationValueRules called ObjectDateDeclaration::allFromConfig() to
 * refuse unusable date declarations on save; it now calls
 * CalendarDateDeclarationCheck::assertDeclarable(), which runs the same read.
 * The outcomes below were recorded from the original code (accepted, or the
 * exception class and message), for valid, refused and odd blocks.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema\ConfigurationValueRules;
use OCA\OpenRegister\Exception\CalendarDateKindException;
use OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck
 */
final class CalendarDateDeclarationCheckGoldenTest extends TestCase {

	/**
	 * Refusal of an unusable `dates` block.
	 *
	 * @var string
	 */
	private const NOT_AN_OBJECT = 'calendarProvider.dates must be an object keyed by property name.';

	/**
	 * Refusal of an unknown kind on `due`.
	 *
	 * @var string
	 */
	private const UNKNOWN_KIND = "calendarProvider.dates.due declares kind 'nonsense', which is not a date kind: use deadline, appointment, period.";

	/**
	 * Refusal of an unusable alarm offset on `due`.
	 *
	 * @var string
	 */
	private const BAD_ALARM = 'calendarProvider.dates.due declares alarmOffsetDays that is not a whole number of days at or above zero.';

	/**
	 * A config, and what the original validateCalendarProviderConfig() did with it (null = accepted).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array{0: class-string, 1: string}|null}>
	 */
	public static function cases(): array {
		$due = static fn (array $config): array => ['dates' => ['due' => $config]];

		return [
			'empty'                  => [[], null],
			'disabled, no dates'     => [['enabled' => false], null],
			'enabled, missing'       => [
				['enabled' => true],
				[InvalidArgumentException::class, 'calendarProvider.dtstart is required when calendar provider is enabled'],
			],
			'enabled, complete'      => [['enabled' => true, 'dtstart' => 'start', 'titleTemplate' => '{{ title }}'], null],
			'dates null'             => [['dates' => null], null],
			'dates scalar'           => [['dates' => 'beslistermijn'], [CalendarDateKindException::class, self::NOT_AN_OBJECT]],
			'dates int'              => [['dates' => 7], [CalendarDateKindException::class, self::NOT_AN_OBJECT]],
			'dates empty'            => [['dates' => []], null],
			'blank property'         => [
				['dates' => ['  ' => ['kind' => 'deadline']]],
				[CalendarDateKindException::class, 'calendarProvider.dates carries an entry with no property name.'],
			],
			'numeric property'       => [['dates' => [0 => ['kind' => 'deadline']]], null],
			'unknown kind'           => [$due(['kind' => 'nonsense']), [CalendarDateKindException::class, self::UNKNOWN_KIND]],
			'deadline'               => [$due(['kind' => 'deadline']), null],
			'config not array'       => [
				['dates' => ['due' => 'deadline']],
				[CalendarDateKindException::class, 'calendarProvider.dates.due must be an object declaring a kind, string given.'],
			],
			'negative alarm'         => [$due(['kind' => 'deadline', 'alarmOffsetDays' => -1]), [CalendarDateKindException::class, self::BAD_ALARM]],
			'string alarm'           => [$due(['kind' => 'deadline', 'alarmOffsetDays' => '3']), [CalendarDateKindException::class, self::BAD_ALARM]],
			'good alarm'             => [$due(['kind' => 'deadline', 'alarmOffsetDays' => 3]), null],
			'disabled with bad date' => [['enabled' => false] + $due(['kind' => 'nonsense']), [CalendarDateKindException::class, self::UNKNOWN_KIND]],
			'enabled good, bad date' => [
				['enabled' => true, 'dtstart' => 's', 'titleTemplate' => 't', 'dates' => 'x'],
				[CalendarDateKindException::class, self::NOT_AN_OBJECT],
			],
		];
	}//end cases()

	/**
	 * The save rule accepts and refuses exactly what it did.
	 *
	 * @param array<string, mixed>                    $config   The calendarProvider block.
	 * @param array{0: class-string, 1: string}|null $expected The recorded outcome.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheSaveRuleMatchesTheRecordedOutcome(array $config, ?array $expected): void {
		$rules = (new ReflectionClass(objectOrClass: ConfigurationValueRules::class))->newInstanceWithoutConstructor();
		$this->assertSame(expected: $expected, actual: $this->outcome(call: fn () => $rules->validateCalendarProviderConfig(config: $config)));
	}//end testTheSaveRuleMatchesTheRecordedOutcome()

	/**
	 * The check refuses exactly what the declaration reader refuses.
	 *
	 * @param array<string, mixed> $config The calendarProvider block.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheCheckRefusesWhatTheReaderRefuses(array $config): void {
		$this->assertSame(
			expected: $this->outcome(call: fn () => ObjectDateDeclaration::allFromConfig(calendarConfig: $config)),
			actual: $this->outcome(call: fn () => CalendarDateDeclarationCheck::assertDeclarable(calendarConfig: $config))
		);
	}//end testTheCheckRefusesWhatTheReaderRefuses()

	/**
	 * Null when the call returns, else the exception class and message.
	 *
	 * @param callable $call The call.
	 *
	 * @return array{0: class-string, 1: string}|null
	 */
	private function outcome(callable $call): ?array {
		try {
			$call();
		} catch (Throwable $e) {
			return [get_class($e), $e->getMessage()];
		}

		return null;
	}//end outcome()
}//end class
