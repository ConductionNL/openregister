<?php

/**
 * FillCalendarTimeZones gives each working calendar without a zone the
 * instance's default_timezone, UTC with a warning when there is none, and
 * never touches a calendar that names one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\RegisterSchemaSlugs;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Repair\FillCalendarTimeZones;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FillCalendarTimeZones::class)]
#[UsesClass(ObjectSaveOptions::class)]
#[UsesClass(ObjectFindTarget::class)]
#[UsesClass(RegisterSchemaSlugs::class)]
class FillCalendarTimeZonesTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private IOutput&MockObject $output;

	/**
	 * Every save: the data, the options and the two flags.
	 *
	 * @var list<array{0: array<string, mixed>, 1: ObjectSaveOptions, 2: bool, 3: bool}>
	 */
	private array $saved = [];

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->config  = $this->createMock(IConfig::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->output  = $this->createMock(IOutput::class);
	}//end setUp()

	private function step(): FillCalendarTimeZones {
		return new FillCalendarTimeZones($this->objects, $this->config, $this->logger);
	}//end step()

	/**
	 * A stored calendar double.
	 *
	 * @param string               $uuid The calendar's uuid.
	 * @param array<string, mixed> $data The calendar's data.
	 *
	 * @return ObjectEntity
	 */
	private function calendar(string $uuid, array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($data);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end calendar()

	/**
	 * Record every save instead of writing it.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options, bool $_rbac, bool $_multitenancy): ObjectEntity {
				$this->saved[] = [$object, $options, $_rbac, $_multitenancy];
				return $this->createMock(ObjectEntity::class);
			}
		);
	}//end recordSaves()

	public function testCalendarsWithoutAZoneGetTheInstanceDefaultAndOthersAreLeftAlone(): void {
		$this->config->method('getSystemValueString')->with('default_timezone', '')->willReturn('Europe/Amsterdam');
		$this->objects->expects($this->once())->method('searchObjectsBySlug')
			->with(new RegisterSchemaSlugs(register: 'flow-timers', schema: 'working-calendar'), [], false, false)
			->willReturn([
				$this->calendar('c-1', ['slug' => 'gemeente', 'workingDays' => [1, 2, 3, 4, 5]]),
				$this->calendar('c-2', ['slug' => 'nl-national', 'timezone' => 'Europe/Amsterdam']),
				$this->calendar('c-3', ['slug' => 'blank', 'timezone' => '  ']),
				$this->calendar('c-4', ['slug' => 'new-york', 'timezone' => 'America/New_York']),
			]);
		$this->recordSaves();
		$this->output->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('warning');
		$this->output->expects($this->once())->method('info')->with('2 of 2 working calendars without a time zone now use Europe/Amsterdam.');

		$this->step()->run($this->output);

		$this->assertCount(2, $this->saved);
		[$data, $options, $rbac, $multitenancy] = $this->saved[0];
		$this->assertSame(['slug' => 'gemeente', 'workingDays' => [1, 2, 3, 4, 5], 'timezone' => 'Europe/Amsterdam'], $data);
		$this->assertSame('c-1', $options->uuid);
		$this->assertSame(['flow-timers', 'working-calendar'], [$options->target->register, $options->target->schema]);
		$this->assertSame([false, false], [$rbac, $multitenancy]);
		$this->assertSame('c-3', $this->saved[1][1]->uuid);
		$this->assertSame('Europe/Amsterdam', $this->saved[1][0]['timezone']);
	}//end testCalendarsWithoutAZoneGetTheInstanceDefaultAndOthersAreLeftAlone()

	/**
	 * The instance zones that are not usable.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unusableInstanceZones(): array {
		return [
			'not set' => ['', 'The instance sets no default_timezone, so working calendars without a time zone get UTC'],
			'an offset' => ['+01:00', "The instance's default_timezone '+01:00' is not an IANA zone name, so working calendars without a time zone get UTC"],
			'an abbreviation' => ['CET', "The instance's default_timezone 'CET' is not an IANA zone name"],
		];
	}//end unusableInstanceZones()

	/**
	 * Without a usable default_timezone a calendar gets UTC, and the step warns.
	 *
	 * @param string $instance The instance's default_timezone.
	 * @param string $message  The start of the warning.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unusableInstanceZones')]
	public function testWithoutAUsableInstanceZoneACalendarGetsUtcWithAWarning(string $instance, string $message): void {
		$this->config->method('getSystemValueString')->willReturn($instance);
		$this->objects->method('searchObjectsBySlug')->willReturn([$this->calendar('c-1', ['slug' => 'gemeente'])]);
		$this->recordSaves();
		$this->output->expects($this->once())->method('warning')->with($this->stringStartsWith($message));
		$this->logger->expects($this->once())->method('warning')->with($this->stringStartsWith('[FillCalendarTimeZones] ' . $message));

		$this->step()->run($this->output);

		$this->assertSame('UTC', $this->saved[0][0]['timezone']);
	}//end testWithoutAUsableInstanceZoneACalendarGetsUtcWithAWarning()

	public function testWhenEveryCalendarNamesItsZoneNothingIsWrittenOrAsked(): void {
		$this->objects->method('searchObjectsBySlug')->willReturn([$this->calendar('c-1', ['timezone' => 'UTC'])]);
		$this->objects->expects($this->never())->method('saveObject');
		$this->config->expects($this->never())->method('getSystemValueString');
		$this->output->expects($this->once())->method('info')->with('Every working calendar names its time zone.');

		$this->step()->run($this->output);
	}//end testWhenEveryCalendarNamesItsZoneNothingIsWrittenOrAsked()

	public function testACalendarThatCannotBeWrittenIsLoggedAndTheOthersStillGetTheirZone(): void {
		$this->config->method('getSystemValueString')->willReturn('Europe/Amsterdam');
		$this->objects->method('searchObjectsBySlug')->willReturn([
			$this->calendar('c-1', ['slug' => 'broken']),
			$this->calendar('c-2', ['slug' => 'fine']),
		]);
		$this->objects->expects($this->exactly(2))->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object): ObjectEntity {
				if (($object['slug'] ?? '') === 'broken') {
					throw new RuntimeException('validation failed');
				}

				return $this->createMock(ObjectEntity::class);
			}
		);
		$this->logger->expects($this->once())->method('warning')->with(
			'[FillCalendarTimeZones] Working calendar not given a time zone: validation failed',
			['calendar' => 'c-1', 'slug' => 'broken']
		);
		$this->output->expects($this->once())->method('info')->with('1 of 2 working calendars without a time zone now use Europe/Amsterdam.');

		$this->step()->run($this->output);
	}//end testACalendarThatCannotBeWrittenIsLoggedAndTheOthersStillGetTheirZone()

	public function testAMissingRegisterIsReportedAndStopsNothing(): void {
		$this->objects->method('searchObjectsBySlug')->willThrowException(new RuntimeException("Register 'flow-timers' not found"));
		$this->objects->expects($this->never())->method('saveObject');
		$this->output->expects($this->once())->method('info')->with("Working calendar time zones not filled: Register 'flow-timers' not found");

		$this->step()->run($this->output);
	}//end testAMissingRegisterIsReportedAndStopsNothing()

	public function testTheStepIsNamed(): void {
		$this->assertSame('Give every working calendar without a time zone the instance default_timezone', $this->step()->getName());
	}//end testTheStepIsNamed()
}//end class
