<?php

/**
 * One-off repair: re-chain the whole audit trail from genesis.
 *
 * Sealing predates the seal lock. Before SEAL_LOCK_KEY existed, concurrent seal
 * passes could each read the same predecessor and then write, leaving many rows
 * chained onto ONE predecessor — a fan-out rather than a chain, which
 * verifyChain() correctly reports as broken. The sweeper cannot repair that: it
 * seals rows with NO hash, not rows carrying a WRONG one.
 *
 * This command is the repair, and it is a command rather than a job on purpose.
 * Rewriting stored audit hashes is exactly the event the chain exists to make
 * suspicious, so it must be asked for by a person, announced in the log at
 * warning level, and confirmed unless explicitly forced.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\AuditHashService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Rebuilds the audit hash chain end to end.
 */
class RechainAuditTrailCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param AuditHashService $hashes Performs the re-chain.
	 */
	public function __construct(
		private readonly AuditHashService $hashes,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:rechain-audit-trail')
			->setDescription(
				'One-off repair: recompute every audit-trail hash from genesis. '
				. 'Rewrites stored hashes — run only to repair a chain broken by historical concurrent sealing.'
			)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
			->addOption('force', null, InputOption::VALUE_NONE, 'Skip the confirmation prompt');

	}//end configure()

	/**
	 * Verify, confirm, re-chain, verify again.
	 *
	 * The verification either side is the point: a repair that cannot show the
	 * chain was broken before and whole after is indistinguishable from one that
	 * quietly rewrote a healthy chain.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Exit code.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$before = $this->hashes->verifyChain();
		$output->writeln('<info>Before:</info>');
		$output->writeln($this->summarise(report: $before));

		if ($input->getOption('dry-run') === true) {
			$output->writeln('<comment>Dry run — nothing written.</comment>');
			return Command::SUCCESS;
		}

		if ($input->getOption('force') !== true) {
			$helper = $this->getHelper(name: 'question');

			// If we cannot prompt, we must not proceed. The alternative — assume
			// consent because the helper is missing — would rewrite every stored
			// audit hash on the strength of an environment quirk. --force is the
			// supported way to say yes without a prompt.
			if (($helper instanceof QuestionHelper) === false) {
				$output->writeln(
					'<error>No question helper available to confirm with. '
					. 'Re-run with --force if you really mean to rewrite every audit hash.</error>'
				);

				return Command::FAILURE;
			}

			$question = new ConfirmationQuestion(
				'<comment>This REWRITES every stored audit hash. Continue? [y/N] </comment>',
				false
			);

			if ($helper->ask($input, $output, $question) !== true) {
				$output->writeln('Aborted.');
				return Command::SUCCESS;
			}
		}//end if

		$result = $this->hashes->rechainAll();
		$output->writeln(
			sprintf(
				'<info>Re-chained %d row(s); left %d retention tombstone(s) untouched.</info>',
				$result['rechained'],
				$result['tombstonesPreserved']
			)
		);

		$after = $this->hashes->verifyChain();
		$output->writeln('<info>After:</info>');
		$output->writeln($this->summarise(report: $after));

		if ($after['valid'] !== true) {
			$output->writeln('<error>Chain still reports invalid — do not treat this repair as complete.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * One line describing a verifyChain() report.
	 *
	 * Both ends of the run print the same shape from one place, so a reader
	 * comparing before with after is comparing like with like — a repair whose
	 * two halves reported different fields would be unreadable as evidence.
	 *
	 * @param array<string, mixed> $report A verifyChain() result.
	 *
	 * @return string The formatted summary line.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function summarise(array $report): string {
		$valid = 'false';
		if (($report['valid'] ?? false) === true) {
			$valid = 'true';
		}

		$brokenAt = 'none';
		if (($report['brokenAt'] ?? null) !== null) {
			$brokenAt = (string)$report['brokenAt'];
		}

		return sprintf(
			'  valid=%s  verified=%d  brokenAt=%s  tombstones=%d  unsealed=%d',
			$valid,
			(int)($report['entriesVerified'] ?? 0),
			$brokenAt,
			(int)($report['purgedTombstones'] ?? 0),
			$this->hashes->countUnsealed()
		);

	}//end summarise()
}//end class
