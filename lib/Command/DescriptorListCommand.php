<?php

/**
 * DescriptorListCommand — the register inventory, without a browser.
 *
 * The condition this reports — an app whose register descriptor never landed —
 * is most often met on an instance being set up or repaired, where reaching the
 * admin UI may itself depend on the thing that is broken. A diagnosis available
 * only through the surface under repair is not reliably available.
 *
 * It formats {@see RegisterDescriptorService::inventory()} and nothing more, so
 * the command and the panel cannot drift into disagreeing about the same
 * instance.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\AppRegisterImportRunnerInterface;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists every app-declared register descriptor and whether it landed.
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */
class DescriptorListCommand extends Command {

	/**
	 * The command's options in registration order: name, mode, description.
	 *
	 * @var array<int, array{0: string, 1: int, 2: string}>
	 */
	private const OPTIONS = [
		[
			'problems-only',
			InputOption::VALUE_NONE,
			'Show only registers that are absent or behind the descriptor their app ships',
		],
		[
			'import',
			InputOption::VALUE_REQUIRED,
			'Re-import the app\'s REAL register configuration (lib/Settings/<app>_register.json plus register.d), forced, '
				.'the same import an install or upgrade runs. The slug must be a register that configuration declares. '
				.'Each schema\'s authorization becomes the file\'s, also where it was changed on this instance; '
				.'every such replacement is counted and recorded on the audit trail. Requires --app.',
		],
		[
			'mock',
			InputOption::VALUE_NONE,
			'With --import: import the app\'s SAMPLE descriptor (x-openregister.type: mock, demo objects) instead of its real configuration. '
				.'A mock declares the real register\'s slug, so the register takes the mock\'s version.',
		],
		[
			'app',
			InputOption::VALUE_REQUIRED,
			'The app whose configuration --import re-imports.',
		],
	];

	/**
	 * The console tag each non-current state is wrapped in.
	 *
	 * `absent` is rendered as an error and `behind` as a comment, because the two
	 * call for different actions and carry different risk: absent means a code
	 * path is dead, behind means it runs against an older contract. Collapsing
	 * them into one "needs attention" would send the reader back to the diagnosis
	 * this command replaces.
	 *
	 * @var array<string, string>
	 */
	private const STATE_TAGS = [
		RegisterDescriptorService::STATE_ABSENT => 'error',
		RegisterDescriptorService::STATE_BEHIND => 'comment',
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterDescriptorService $descriptors The descriptor inventory.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterDescriptorService $descriptors,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:descriptors:list')
			->setDescription(
				description: 'List every register descriptor an installed app ships, and whether it landed on this instance.'
			);
		foreach (self::OPTIONS as [$name, $mode, $description]) {
			$this->addOption(name: $name, shortcut: null, mode: $mode, description: $description);
		}
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * Listing exits 0 even when registers are absent: an absent register is a
	 * state for an administrator to decide about, not a failure of this command.
	 * A re-import exits non-zero when it fails, because there the caller asked
	 * for something to happen and it did not.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success, 1 on a failed re-import.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('import') !== null) {
			return $this->runImport(input: $input, output: $output);
		}

		return $this->runListing(input: $input, output: $output);
	}//end execute()

	/**
	 * Print the inventory and its tally.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Always 0: an absent register is not a failure of the listing.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	private function runListing(InputInterface $input, OutputInterface $output): int {
		$rows = $this->descriptors->inventory();
		if ($rows === []) {
			$output->writeln('<info>No installed app ships a register descriptor.</info>');
			return 0;
		}

		$problemsOnly = ($input->getOption('problems-only') === true);
		$output->writeln(array_map($this->formatRow(...), self::visibleRows(rows: $rows, problemsOnly: $problemsOnly)));
		$output->writeln(['', self::summary(rows: $rows)]);

		return 0;
	}//end runListing()

	/**
	 * The rows the listing prints: all of them, or only the problems.
	 *
	 * @param array<int, array<string, string|null>> $rows         The inventory.
	 * @param bool                                   $problemsOnly Whether --problems-only was given.
	 *
	 * @return array<int, array<string, string|null>> The rows to print, in inventory order.
	 */
	private static function visibleRows(array $rows, bool $problemsOnly): array {
		if ($problemsOnly === false) {
			return $rows;
		}

		return array_values(
			array_filter($rows, static fn (array $row): bool => $row['state'] !== RegisterDescriptorService::STATE_CURRENT)
		);
	}//end visibleRows()

	/**
	 * The tally line: every row counted, whatever the filter showed.
	 *
	 * @param array<int, array<string, string|null>> $rows The inventory.
	 *
	 * @return string The summary line.
	 */
	private static function summary(array $rows): string {
		$counts = [
			RegisterDescriptorService::STATE_CURRENT => 0,
			RegisterDescriptorService::STATE_BEHIND  => 0,
			RegisterDescriptorService::STATE_ABSENT  => 0,
		];
		foreach ($rows as $row) {
			$counts[$row['state']]++;
		}

		return sprintf('%d declared · %d current · %d behind · %d ABSENT', count($rows), ...array_values($counts));
	}//end summary()

	/**
	 * Force a re-import and report what happened.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when imported, 1 when it failed.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	private function runImport(InputInterface $input, OutputInterface $output): int {
		$appId = $input->getOption('app');
		if ($appId === null) {
			$output->writeln('<error>--import requires --app naming the app whose configuration to re-import.</error>');
			return 1;
		}

		$target = new DescriptorImportTarget(appId: (string)$appId, slug: (string)$input->getOption('import'));
		if ($input->getOption('mock') === true) {
			return $this->runMockImport(output: $output, target: $target);
		}

		return $this->runRealImport(output: $output, target: $target);
	}//end runImport()

	/**
	 * Re-import the app's real register configuration and report the counts.
	 *
	 * @param OutputInterface        $output Console output.
	 * @param DescriptorImportTarget $target The app and register.
	 *
	 * @return int 0 when imported, 1 when it failed.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	private function runRealImport(OutputInterface $output, DescriptorImportTarget $target): int {
		$result = $this->descriptors->reimport(appId: $target->appId, slug: $target->slug);
		if (isset($result['counts']) === true) {
			$output->writeln(self::realCountLines(appId: $target->appId, result: $result));
		}

		return self::exitCode(output: $output, result: $result);
	}//end runRealImport()

	/**
	 * Print the reason of a failed import and answer the exit code.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $result What the import returned.
	 *
	 * @return int 1 when the import failed, 0 otherwise.
	 */
	private static function exitCode(OutputInterface $output, array $result): int {
		if ($result['outcome'] !== 'failed') {
			return 0;
		}

		$output->writeln('<error>' . $result['reason'] . '</error>');
		return 1;
	}//end exitCode()

	/**
	 * What the real re-import changed.
	 *
	 * 🔴 THE COUNTS, ALWAYS, AND OF WHAT CHANGED. The first version of this
	 * command printed "164 of 186 demo object(s) imported" for what an
	 * operator had asked to be a register re-import. A register re-import is
	 * reported in registers and schemas, created, updated and unchanged.
	 *
	 * @param string               $appId  The app.
	 * @param array<string, mixed> $result What reimport() returned.
	 *
	 * @return array<int, string> The report lines.
	 */
	private static function realCountLines(string $appId, array $result): array {
		$lines = [
			sprintf('<info>%s: register configuration v%s re-imported through %s.</info>', $appId, $result['version'], $result['via']),
			...self::kindLines(counts: $result['counts']),
			...self::replacedLines(result: $result),
		];
		if ($result['path'] === AppRegisterImportRunnerInterface::PATH_OPENREGISTER) {
			$lines[] = self::selfImportNote(appId: $appId, fragments: (int)$result['fragments']);
		}

		return $lines;
	}//end realCountLines()

	/**
	 * One count line per kind: registers, then schemas.
	 *
	 * @param array<string, mixed> $counts The counts reimport() returned.
	 *
	 * @return array<int, string> The two lines.
	 */
	private static function kindLines(array $counts): array {
		$lines = [];
		foreach (['registers', 'schemas'] as $kind) {
			$lines[] = sprintf(
				'  %s: %d updated, %d created, %d unchanged',
				$kind,
				$counts[$kind]['updated'],
				$counts[$kind]['created'],
				$counts[$kind]['unchanged']
			);
		}

		return $lines;
	}//end kindLines()

	/**
	 * The authorization blocks taken from the file over an instance change.
	 *
	 * @param array<string, mixed> $result What reimport() returned.
	 *
	 * @return array<int, string> The tally line and one line per replaced block.
	 */
	private static function replacedLines(array $result): array {
		$lines = [
			sprintf(
				'  authorization taken from the file over a change made on this instance: %d block(s)',
				$result['counts']['authorizationReplaced']
			),
		];
		foreach ($result['replaced'] as $replaced) {
			$lines[] = sprintf('    %s: %s', $replaced['schema'], $replaced['path']);
		}

		return $lines;
	}//end replacedLines()

	/**
	 * The note printed when OpenRegister imported the file itself.
	 *
	 * @param string $appId     The app.
	 * @param int    $fragments How many register.d fragments were imported.
	 *
	 * @return string The note.
	 */
	private static function selfImportNote(string $appId, int $fragments): string {
		return sprintf(
			'<comment>  %s has no forced import entry point in its SettingsService, '
			.'so OpenRegister imported the file and %d register.d fragment(s) itself. '
			.'The app\'s own post-import steps did not run.</comment>',
			$appId,
			$fragments
		);
	}//end selfImportNote()

	/**
	 * Import an app's sample (mock) descriptor and report the demo objects.
	 *
	 * @param OutputInterface        $output Console output.
	 * @param DescriptorImportTarget $target The app and the register slug the mock declares.
	 *
	 * @return int 0 when imported, 1 when it failed.
	 */
	private function runMockImport(OutputInterface $output, DescriptorImportTarget $target): int {
		$result = $this->descriptors->reimportMock(appId: $target->appId, slug: $target->slug);
		$exit   = self::exitCode(output: $output, result: $result);
		if ($exit === 0) {
			$output->writeln(self::mockLine(target: $target, result: $result));
		}

		return $exit;
	}//end runMockImport()

	/**
	 * The line reporting a sample import.
	 *
	 * 🔴 THE COUNTS, ALWAYS — the same rule the repair steps follow.
	 * `register "larpinq" imported.` was returned by an import that created
	 * the register's schemas and none of its 30 demo objects, and the
	 * sentence could not be told apart from a run that seeded everything.
	 *
	 * @param DescriptorImportTarget $target The app and register.
	 * @param array<string, mixed>   $result What reimportMock() returned.
	 *
	 * @return string The line.
	 */
	private static function mockLine(DescriptorImportTarget $target, array $result): string {
		$counts = ($result['counts'] ?? null);
		if (is_array($counts) === true && $counts['declared'] > 0) {
			return sprintf(
				'<info>%s: sample data for register "%s" imported: %d of %d demo object(s), %d skipped.</info>',
				$target->appId,
				$target->slug,
				$counts['imported'],
				$counts['declared'],
				$counts['skipped']
			);
		}

		return sprintf('<info>%s: sample descriptor for register "%s" %s.</info>', $target->appId, $target->slug, $result['outcome']);
	}//end mockLine()

	/**
	 * Format one inventory row, tagged by its state (see STATE_TAGS).
	 *
	 * @param array<string, string|null> $row One inventory row.
	 *
	 * @return string The formatted line.
	 */
	private function formatRow(array $row): string {
		$versions = sprintf('shipped v%s', $row['shippedVersion']);
		if ($row['installedVersion'] !== null) {
			$versions = sprintf('installed v%s · shipped v%s', $row['installedVersion'], $row['shippedVersion']);
		}

		$line = sprintf('  %-22s %-24s %-9s %s', $row['appId'], $row['slug'], $row['state'], $versions);
		$tag  = (self::STATE_TAGS[$row['state']] ?? null);
		if ($tag === null) {
			return $line;
		}

		return sprintf('<%1$s>%2$s</%1$s>', $tag, $line);
	}//end formatRow()
}//end class
