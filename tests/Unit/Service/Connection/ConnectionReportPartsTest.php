<?php

/**
 * Unit tests for the parts around the connection report: the kind's options
 * and run, the `@admins` recipient expansion, and the registry reader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-report-can-go-to-all-administrators
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Connection;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\AdminRecipientExpander;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReport;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReportBuilder;
use OCA\OpenRegister\Service\Connection\ConnectionRegistryMissingException;
use OCA\OpenRegister\Service\Connection\ConnectionRegistryReader;
use OCA\OpenRegister\Service\Connection\ConnectionReportKind;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the connection report's options, recipients and reads.
 *
 * @covers \OCA\OpenRegister\Service\Connection\ConnectionReportKind
 * @covers \OCA\OpenRegister\Service\AdminRecipientExpander
 * @covers \OCA\OpenRegister\Service\Connection\ConnectionRegistryReader
 * @uses   \OCA\OpenRegister\Service\Connection\ConnectionHealthReport
 * @uses   \OCA\OpenRegister\Service\Connection\ConnectionRegistryMissingException
 */
final class ConnectionReportPartsTest extends TestCase {

	/**
	 * Options default to every day and 24 hours, and are refused when malformed.
	 *
	 * @return void
	 */
	public function testOptionsDefaultAndRefuse(): void {
		$this->assertSame(['onlyWhenAttention' => false, 'lookbackHours' => 24], ConnectionReportKind::options(options: null));
		$this->assertSame(['onlyWhenAttention' => true, 'lookbackHours' => 168], ConnectionReportKind::options(options: ['onlyWhenAttention' => true, 'lookbackHours' => 168]));

		foreach ([['lookbackHours' => 0], ['lookbackHours' => 169], ['lookbackHours' => '24'], ['onlyWhenAttention' => 'yes'], 'nope'] as $bad) {
			try {
				ConnectionReportKind::options(options: $bad);
				$this->fail('Accepted ' . json_encode($bad));
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}//end testOptionsDefaultAndRefuse()

	/**
	 * A run is quiet only when asked and nothing needs attention.
	 *
	 * @return void
	 */
	public function testARunIsQuietOnlyWhenAskedAndAllClear(): void {
		$builder = $this->createMock(ConnectionHealthReportBuilder::class);
		$builder->method('build')->willReturnOnConsecutiveCalls(
			new ConnectionHealthReport(attention: 0, subject: 'clear', lines: ['a'], csv: 'x', rowCount: 1),
			new ConnectionHealthReport(attention: 2, subject: '2 need attention', lines: ['b'], csv: 'y', rowCount: 3),
			new ConnectionHealthReport(attention: 0, subject: 'clear', lines: ['a'], csv: 'x', rowCount: 1)
		);
		$kind = new ConnectionReportKind(builder: $builder);
		$now = new DateTimeImmutable('2026-11-13T07:00:00+00:00');

		$quiet = $kind->run(options: ['onlyWhenAttention' => true], now: $now);
		$busy = $kind->run(options: ['onlyWhenAttention' => true], now: $now);
		$everyDay = $kind->run(options: [], now: $now);

		$this->assertTrue($quiet['quiet']);
		$this->assertSame(['bytes' => 'y', 'rowCount' => 3, 'attention' => 2, 'subject' => '2 need attention', 'lines' => ['b'], 'quiet' => false], $busy);
		$this->assertFalse($everyDay['quiet']);
	}//end testARunIsQuietOnlyWhenAskedAndAllClear()

	/**
	 * `@admins` expands to the administrators with an address; the others and the overflow are named.
	 *
	 * @return void
	 */
	public function testAdminsExpandWithNotes(): void {
		$users = [];
		foreach (['carol' => 'carol@example.nl', 'alice' => 'alice@example.nl', 'bob' => ''] as $uid => $address) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getEMailAddress')->willReturn($address);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);
		$expander = new AdminRecipientExpander(groups: $groups);

		$all = $expander->expand(configured: ['ops@example.nl', '@admins'], cap: 20);
		$capped = $expander->expand(configured: ['ops@example.nl', '@admins'], cap: 2);

		$this->assertSame(['ops@example.nl' => 'ops@example.nl', 'alice@example.nl' => 'Alice', 'carol@example.nl' => 'Carol'], $all['recipients']);
		$this->assertSame(['Administrators without an e-mail address were skipped: bob.'], $all['notes']);
		$this->assertSame(['ops@example.nl', 'alice@example.nl'], array_keys($capped['recipients']));
		$this->assertSame('1 recipients were left out, over the cap of 2.', $capped['notes'][1]);
	}//end testAdminsExpandWithNotes()

	/**
	 * Without register `integriq` the reader says integriq is not installed.
	 *
	 * @return void
	 */
	public function testTheReaderNamesAMissingRegistry(): void {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willThrowException(new DoesNotExistException('none'));
		$reader = new ConnectionRegistryReader(registers: $registers, schemas: $this->createMock(SchemaMapperInterface::class), objects: $this->createMock(ObjectService::class));

		$this->expectException(ConnectionRegistryMissingException::class);
		$reader->rows(schemaSlug: 'app_connection', limit: 10);
	}//end testTheReaderNamesAMissingRegistry()

	/**
	 * A schema not in the register reads as no rows; one in it reads bounded and sorted.
	 *
	 * @return void
	 */
	public function testTheReaderReadsBoundedRows(): void {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([4, 5]);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->with('integriq', false, false)->willReturn($register);
		$schema = $this->createMock(Schema::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findBySlugInIds')->willReturnCallback(
			static fn (string $slug, array $schemaIds): ?Schema => ($slug === 'synchronization_run' && $schemaIds === [4, 5] ? $schema : null)
		);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->expects($this->once())->method('findAll')
			->with(['limit' => 5000, 'sort' => ['startedAt' => 'DESC']])
			->willReturn([['status' => 'success'], 'not-a-row']);
		$reader = new ConnectionRegistryReader(registers: $registers, schemas: $schemas, objects: $objects);

		$this->assertSame([], $reader->rows(schemaSlug: 'app_connection', limit: 10));
		$this->assertSame([['status' => 'success']], $reader->rows(schemaSlug: 'synchronization_run', limit: 5000, sort: ['startedAt' => 'DESC']));
	}//end testTheReaderReadsBoundedRows()
}//end class
