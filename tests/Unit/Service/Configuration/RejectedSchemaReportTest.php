<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\Configuration\ImportComponent;
use OCA\OpenRegister\Service\Configuration\ImportRun;
use OCA\OpenRegister\Service\Configuration\RejectedSchemaReport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A rejected schema is counted, named and warned about.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\RejectedSchemaReport
 * @uses \OCA\OpenRegister\Db\Configuration
 * @uses \OCA\OpenRegister\Service\Configuration\ImportComponent
 * @uses \OCA\OpenRegister\Service\Configuration\ImportRun
 */
class RejectedSchemaReportTest extends TestCase {

	/**
	 * An import of two schemas, none rejected so far.
	 *
	 * @return ImportRun
	 */
	private function importRun(): ImportRun {
		$run = new ImportRun(data: ['components' => ['schemas' => ['a' => [], 'b' => []]]], configuration: new Configuration());
		$run->appId = 'dossiq';
		$run->result = ['skipped' => ['schemas' => 0], 'failed' => ['schemas' => []]];

		return $run;
	}//end importRun()

	/**
	 * A rejected component with its error.
	 *
	 * @param int|string           $key  The component key.
	 * @param array<string, mixed> $data The component data.
	 *
	 * @return ImportComponent
	 */
	private function rejected(int|string $key, array $data): ImportComponent {
		$component = new ImportComponent(key: $key, data: $data);
		$component->error = new RuntimeException('no good');

		return $component;
	}//end rejected()

	/**
	 * A rejected schema is counted, named by its slug and logged with its error.
	 *
	 * @return void
	 */
	public function testARejectedSchemaIsCountedNamedAndLogged(): void {
		$run = $this->importRun();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[ImportHandler] Failed to create schema (Pass 1)',
			$this->callback(static fn (array $context): bool => $context['schemaKey'] === 'a'
				&& $context['error'] === 'no good'
				&& is_string($context['trace']) === true)
		);

		(new RejectedSchemaReport(logger: $logger))->record(run: $run, rejection: $this->rejected(key: 'a', data: ['slug' => 'person']));

		$this->assertSame(1, $run->result['skipped']['schemas']);
		$this->assertSame([['key' => 'a', 'slug' => 'person', 'error' => 'no good']], $run->result['failed']['schemas']);
	}//end testARejectedSchemaIsCountedNamedAndLogged()

	/**
	 * A schema without a slug is named by its key, as text.
	 *
	 * @return void
	 */
	public function testASchemaWithoutASlugIsNamedByItsKey(): void {
		$run = $this->importRun();

		(new RejectedSchemaReport(logger: $this->createMock(LoggerInterface::class)))->record(run: $run, rejection: $this->rejected(key: 3, data: []));

		$this->assertSame([['key' => '3', 'slug' => '3', 'error' => 'no good']], $run->result['failed']['schemas']);
	}//end testASchemaWithoutASlugIsNamedByItsKey()

	/**
	 * With nothing rejected there is no warning.
	 *
	 * @return void
	 */
	public function testNothingRejectedWarnsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new RejectedSchemaReport(logger: $logger))->warn(run: $this->importRun());
	}//end testNothingRejectedWarnsNothing()

	/**
	 * The warning names every rejected schema, out of how many.
	 *
	 * @return void
	 */
	public function testThePartialImportWarningNamesEveryRejectedSchema(): void {
		$run = $this->importRun();
		$failed = [['key' => 'a', 'slug' => 'person', 'error' => 'x'], ['key' => 'b', 'slug' => 'case', 'error' => 'y']];
		$run->result['failed']['schemas'] = $failed;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[ImportHandler] PARTIAL IMPORT: 2 of 2 schema(s) were rejected and are NOT linked to any register in this import: person, case. '
			.'Registers declaring them were created without those schema references.',
			$this->callback(static fn (array $context): bool => $context['appId'] === 'dossiq' && $context['failedSchemas'] === $failed)
		);

		(new RejectedSchemaReport(logger: $logger))->warn(run: $run);
	}//end testThePartialImportWarningNamesEveryRejectedSchema()
}//end class
