<?php

/**
 * Behaviour tests for `occ openregister:vocabulary:import-csv`: the required
 * scheme URI, which options reach the importer, the three exit codes and the
 * printed counts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use InvalidArgumentException;
use OCA\OpenRegister\Command\ImportSkosCsvCommand;
use OCA\OpenRegister\Kernel\Contracts\VocabularyImporterInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command over a mocked importer.
 */
class ImportSkosCsvCommandBehaviourTest extends TestCase {

	private const URI = 'https://example.org/scheme/colours';

	public function testMissingSchemeUriIsInvalidAndNeverCallsTheImporter(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->never())->method('importCsvValueList');
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(2, $tester->execute(['file' => '/data/colours.csv']));
		$this->assertStringContainsString('--scheme-uri is required', $tester->getDisplay());
	}

	public function testAnEmptySchemeUriIsInvalidToo(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->never())->method('importCsvValueList');
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(2, $tester->execute(['file' => '/data/colours.csv', '--scheme-uri' => '']));
		$this->assertStringContainsString('--scheme-uri is required', $tester->getDisplay());
	}

	public function testOnlyTheUriIsPassedWhenNoOtherOptionIsGiven(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->once())
			->method('importCsvValueList')
			->with('/data/colours.csv', ['uri' => self::URI])
			->willReturn(['scheme' => 'colours', 'created' => 3, 'updated' => 1, 'unchanged' => 7, 'deprecated' => 2]);
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(0, $tester->execute(['file' => '/data/colours.csv', '--scheme-uri' => self::URI, '--scheme-title' => '']));
		$this->assertSame(
			"Imported scheme colours — created 3, updated 1, unchanged 7, deprecated 2.\n",
			$tester->getDisplay()
		);
	}

	public function testEveryGivenOptionIsMappedOntoItsMetaKey(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->once())
			->method('importCsvValueList')
			->with(
				'/data/colours.csv',
				['uri' => self::URI, 'title' => 'Colours', 'register' => 'vocab', 'schema' => 'concept']
			)
			->willReturn(['scheme' => self::URI, 'created' => 0, 'updated' => 0, 'unchanged' => 5, 'deprecated' => 0]);
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$exit = $tester->execute([
			'file'           => '/data/colours.csv',
			'--scheme-uri'   => self::URI,
			'--scheme-title' => 'Colours',
			'--register'     => 'vocab',
			'--schema'       => 'concept',
		]);

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('created 0, updated 0, unchanged 5, deprecated 0', $tester->getDisplay());
	}

	public function testAMissingResultKeyFallsBackToTheUriAndZero(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->once())
			->method('importCsvValueList')
			->with('/data/colours.csv', ['uri' => self::URI, 'schema' => 'concept'])
			->willReturn([]);
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(0, $tester->execute(['file' => '/data/colours.csv', '--scheme-uri' => self::URI, '--schema' => 'concept']));
		$this->assertSame(
			'Imported scheme '.self::URI." — created 0, updated 0, unchanged 0, deprecated 0.\n",
			$tester->getDisplay()
		);
	}

	public function testAnInvalidArgumentFromTheImporterIsInvalidInput(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->once())
			->method('importCsvValueList')
			->willThrowException(new InvalidArgumentException('CSV file not found: /nope.csv'));
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(2, $tester->execute(['file' => '/nope.csv', '--scheme-uri' => self::URI]));
		$this->assertSame("CSV file not found: /nope.csv\n", $tester->getDisplay());
	}

	public function testAnyOtherThrowableIsAFailure(): void {
		$importer = $this->createMock(VocabularyImporterInterface::class);
		$importer->expects($this->once())
			->method('importCsvValueList')
			->willThrowException(new RuntimeException('database gone'));
		$tester = new CommandTester(new ImportSkosCsvCommand(vocabulary: $importer));

		$this->assertSame(1, $tester->execute(['file' => '/data/colours.csv', '--scheme-uri' => self::URI]));
		$this->assertSame("Import failed: database gone\n", $tester->getDisplay());
	}
}
