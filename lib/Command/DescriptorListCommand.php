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
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\RegisterDescriptorService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists every app-declared register descriptor and whether it landed.
 */
class DescriptorListCommand extends Command {
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
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:descriptors:list')
			->setDescription(
				description: 'List every register descriptor an installed app ships, and whether it landed on this instance.'
			)
			->addOption(
				name: 'problems-only',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Show only registers that are absent or behind the descriptor their app ships'
			)
			->addOption(
				name: 'import',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Re-import one register by slug (forced), instead of listing. Requires --app.'
			)
			->addOption(
				name: 'app',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'The app that ships the descriptor named by --import.'
			);
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
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$slug = $input->getOption('import');
		if ($slug !== null) {
			return $this->runImport(input: $input, output: $output, slug: (string)$slug);
		}

		$rows = $this->descriptors->inventory();
		if ($rows === []) {
			$output->writeln('<info>No installed app ships a register descriptor.</info>');
			return 0;
		}

		$problemsOnly = ($input->getOption('problems-only') === true);
		$counts       = [
			RegisterDescriptorService::STATE_CURRENT => 0,
			RegisterDescriptorService::STATE_BEHIND  => 0,
			RegisterDescriptorService::STATE_ABSENT  => 0,
		];

		foreach ($rows as $row) {
			$counts[$row['state']]++;
			if ($problemsOnly === true && $row['state'] === RegisterDescriptorService::STATE_CURRENT) {
				continue;
			}

			$output->writeln($this->formatRow(row: $row));
		}

		$output->writeln('');
		$output->writeln(
			sprintf(
				'%d declared · %d current · %d behind · %d ABSENT',
				count($rows),
				$counts[RegisterDescriptorService::STATE_CURRENT],
				$counts[RegisterDescriptorService::STATE_BEHIND],
				$counts[RegisterDescriptorService::STATE_ABSENT]
			)
		);

		return 0;
	}//end execute()

	/**
	 * Force a re-import of one register and report what happened.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 * @param string          $slug   The register slug to re-import.
	 *
	 * @return int 0 when imported, 1 when it failed.
	 */
	private function runImport(InputInterface $input, OutputInterface $output, string $slug): int {
		$appId = $input->getOption('app');
		if ($appId === null) {
			$output->writeln('<error>--import requires --app naming the app that ships the descriptor.</error>');
			return 1;
		}

		$result = $this->descriptors->reimport(appId: (string)$appId, slug: $slug);
		if ($result['outcome'] === 'failed') {
			$output->writeln('<error>' . $result['reason'] . '</error>');
			return 1;
		}

		$output->writeln(sprintf('<info>%s: register "%s" %s.</info>', $appId, $slug, $result['outcome']));

		return 0;
	}//end runImport()

	/**
	 * Format one inventory row.
	 *
	 * `absent` is rendered as an error and `behind` as a comment, because the two
	 * call for different actions and carry different risk: absent means a code
	 * path is dead, behind means it runs against an older contract. Collapsing
	 * them into one "needs attention" would send the reader back to the diagnosis
	 * this command replaces.
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

		if ($row['state'] === RegisterDescriptorService::STATE_ABSENT) {
			return '<error>' . $line . '</error>';
		}

		if ($row['state'] === RegisterDescriptorService::STATE_BEHIND) {
			return '<comment>' . $line . '</comment>';
		}

		return $line;
	}//end formatRow()
}//end class
