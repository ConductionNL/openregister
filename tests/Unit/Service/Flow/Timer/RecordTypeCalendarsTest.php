<?php

/**
 * The working calendar a schema names in its configuration, read without the
 * caller's RBAC, and none when the schema names none or cannot be read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\Timer\RecordTypeCalendars;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\RecordTypeCalendars
 */
class RecordTypeCalendarsTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param array<string, mixed>|null $configuration
	 */
	private function schemaWith(?array $configuration): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn($configuration);

		return $schema;
	}//end schemaWith()

	private function reader(): RecordTypeCalendars {
		return new RecordTypeCalendars(schemas: $this->schemas, logger: $this->logger);
	}//end reader()

	public function testASchemaNamingACalendarIsReadWithoutTheCallersRbac(): void {
		$this->schemas->expects($this->once())->method('find')
			->with('burgerzaken', $this->anything(), false, false)
			->willReturn($this->schemaWith(['workingCalendar' => ' counter-hours ', 'unique' => ['bsn']]));

		$this->assertSame('counter-hours', $this->reader()->calendarOf(schema: ' burgerzaken '));
	}

	public function testASchemaNamingNoneNamesNone(): void {
		$this->schemas->method('find')->willReturnOnConsecutiveCalls(
			$this->schemaWith(null),
			$this->schemaWith(['workingCalendar' => '  ']),
			$this->schemaWith(['workingCalendar' => ['counter-hours']])
		);

		$this->assertNull($this->reader()->calendarOf(schema: 'a'));
		$this->assertNull($this->reader()->calendarOf(schema: 'b'));
		$this->assertNull($this->reader()->calendarOf(schema: 'c'), 'only a slug names a calendar');
	}

	public function testASchemaThatCannotBeReadNamesNone(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('info')->with($this->stringContains('"vergunningen"'));

		$this->assertNull($this->reader()->calendarOf(schema: 'vergunningen'));
	}

	public function testAnEmptySchemaReferenceIsNotLookedUp(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertNull($this->reader()->calendarOf(schema: ' '));
	}
}//end class
