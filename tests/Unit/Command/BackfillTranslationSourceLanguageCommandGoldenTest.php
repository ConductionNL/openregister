<?php

/**
 * Characterisation (golden) test for `occ openregister:translations:backfill-source-language`.
 *
 * Pins the exact console output, exit code and mapper calls for: nothing
 * pending, dry run (with the batch-size floor), a full pass, and a pass that
 * leaves rows pending; registers without an id or that are not Register
 * entities are left out of the default-language map. Written against the code
 * before execute() was split; it must keep passing unchanged afterwards.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\BackfillTranslationSourceLanguageCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\TranslationMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden outcomes of the translation source-language back-fill.
 */
class BackfillTranslationSourceLanguageCommandGoldenTest extends TestCase {

	/**
	 * Ordered log of mapper calls.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A register with an optional id and languages.
	 *
	 * @param int|null                $id        The id.
	 * @param array<int, string>|null $languages The configured languages.
	 *
	 * @return Register The register.
	 */
	private function register(?int $id, ?array $languages): Register {
		$register = new Register();
		$prop     = (new ReflectionClass($register))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($register, $id);
		$register->setLanguages($languages);

		return $register;
	}//end register()

	/**
	 * Run the command.
	 *
	 * @param array<string, mixed> $input     The input.
	 * @param array<int, int>      $pending   Successive countMissingSourceLanguage() results.
	 * @param array<int, mixed>    $registers What findAll() returns.
	 *
	 * @return array{0: int, 1: string, 2: array<int, string>} Exit code, display, call log.
	 */
	private function runBackfill(array $input, array $pending, array $registers=[]): array {
		$this->calls = [];

		$translations = $this->createMock(TranslationMapper::class);
		$translations->method('countMissingSourceLanguage')->willReturnCallback(
			function () use (&$pending): int {
				$this->calls[] = 'count';
				return array_shift($pending);
			}
		);
		$translations->method('backfillSourceLanguage')->willReturnCallback(
			function (array $defaults, int $batchSize): int {
				$this->calls[] = 'backfill '.json_encode($defaults).' batch='.$batchSize;
				return 7;
			}
		);

		$registerMapper = $this->createMock(RegisterMapperInterface::class);
		$registerMapper->method('findAll')->willReturnCallback(
			function () use ($registers): array {
				$this->calls[] = 'findAll';
				return $registers;
			}
		);

		$tester = new CommandTester(new BackfillTranslationSourceLanguageCommand($registerMapper, $translations));
		$code   = $tester->execute($input);

		return [$code, $tester->getDisplay(), $this->calls];
	}//end runBackfill()

	/**
	 * Nothing pending, dry run, and the batch-size floor.
	 *
	 * @return void
	 */
	public function testNothingPendingAndDryRun(): void {
		[$code, $display, $calls] = $this->runBackfill([], [0]);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("Translation rows pending back-fill: 0\n0 rows updated — column is fully back-filled.\n", $display);
		self::assertSame(['count'], $calls);

		[$code, $display, $calls] = $this->runBackfill(['--dry-run' => true, '--batch-size' => '0'], [12]);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("Translation rows pending back-fill: 12\nDry-run: would update up to 12 rows (batch size 1000).\n", $display);
		self::assertSame(['count'], $calls);
	}//end testNothingPendingAndDryRun()

	/**
	 * A pass that finishes and one that leaves rows pending.
	 *
	 * @return void
	 */
	public function testBackfillPasses(): void {
		$registers = [
			$this->register(3, ['de', 'en']),
			$this->register(null, ['fr']),
			$this->register(5, null),
			new \stdClass(),
		];

		[$code, $display, $calls] = $this->runBackfill(['--batch-size' => '50'], [9, 0], $registers);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame("Translation rows pending back-fill: 9\n7 rows updated.\n", $display);
		self::assertSame(['count', 'findAll', 'backfill {"3":"de","5":"nl"} batch=50', 'count'], $calls);

		[$code, $display] = $this->runBackfill([], [9, 2], $registers);
		self::assertSame(Command::SUCCESS, $code);
		self::assertSame(
			"Translation rows pending back-fill: 9\n7 rows updated.\n2 rows still pending — re-run the command to continue back-filling.\n",
			$display
		);
	}//end testBackfillPasses()
}//end class
