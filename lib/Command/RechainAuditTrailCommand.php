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

use OCA\OpenRegister\Service\AuditRechainInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Rebuilds the audit hash chain end to end.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
class RechainAuditTrailCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param AuditRechainInterface $hashes Performs the re-chain.
	 */
	public function __construct(
		private readonly AuditRechainInterface $hashes,
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

		$refusal = $this->refusalToProceed(input: $input, output: $output);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->rechainAndVerify(output: $output);
	}//end execute()

	/**
	 * Ask before rewriting, unless --force says yes.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int|null Null to proceed, or the exit code to stop with.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function refusalToProceed(InputInterface $input, OutputInterface $output): ?int {
		if ($input->getOption('force') === true) {
			return null;
		}

		$helper = $this->getHelper(name: 'question');

		// If we cannot prompt, we must not proceed. The alternative — assume
		// consent because the helper is missing — would rewrite every stored
		// audit hash on the strength of an environment quirk. --force is the
		// supported way to say yes without a prompt.
		if (($helper instanceof QuestionHelper) === false) {
			return $this->refuseWithoutPrompt(output: $output);
		}

		if ($helper->ask($input, $output, self::confirmation()) !== true) {
			$output->writeln('Aborted.');
			return Command::SUCCESS;
		}

		return null;
	}//end refusalToProceed()

	/**
	 * Refuse to rewrite when there is no way to ask.
	 *
	 * @param OutputInterface $output Console output.
	 *
	 * @return int The failure exit code.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function refuseWithoutPrompt(OutputInterface $output): int {
		$output->writeln(
			'<error>No question helper available to confirm with. '
			. 'Re-run with --force if you really mean to rewrite every audit hash.</error>'
		);

		return Command::FAILURE;
	}//end refuseWithoutPrompt()

	/**
	 * The confirmation question; the default answer is no.
	 *
	 * @return ConfirmationQuestion The question.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private static function confirmation(): ConfirmationQuestion {
		return new ConfirmationQuestion(
			'<comment>This REWRITES every stored audit hash. Continue? [y/N] </comment>',
			false
		);
	}//end confirmation()

	/**
	 * Re-chain, then verify again and fail when the chain is still broken.
	 *
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Exit code.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function rechainAndVerify(OutputInterface $output): int {
		$this->writeRechained(output: $output, result: $this->hashes->rechainAll());

		$after = $this->hashes->verifyChain();
		$output->writeln('<info>After:</info>');
		$output->writeln($this->summarise(report: $after));

		if ($after['valid'] !== true) {
			$output->writeln('<error>Chain still reports invalid — do not treat this repair as complete.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end rechainAndVerify()

	/**
	 * Print what the re-chain rewrote and what it left alone.
	 *
	 * @param OutputInterface      $output Console output.
	 * @param array<string, mixed> $result The rechainAll() result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function writeRechained(OutputInterface $output, array $result): void {
		$output->writeln(
			sprintf(
				'<info>Re-chained %d row(s); left %d retention tombstone(s) untouched.</info>',
				$result['rechained'],
				$result['tombstonesPreserved']
			)
		);
	}//end writeRechained()

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
		return sprintf(
			'  valid=%s  verified=%d  brokenAt=%s  tombstones=%d  unsealed=%d',
			$this->validText(report: $report),
			(int)($report['entriesVerified'] ?? 0),
			$this->brokenAtText(report: $report),
			(int)($report['purgedTombstones'] ?? 0),
			$this->hashes->countUnsealed()
		);

	}//end summarise()

	/**
	 * The report's validity as printed: `true` only for a literal true.
	 *
	 * @param array<string, mixed> $report A verifyChain() result.
	 *
	 * @return string `true` or `false`.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function validText(array $report): string {
		if (($report['valid'] ?? false) === true) {
			return 'true';
		}

		return 'false';
	}//end validText()

	/**
	 * The report's first broken entry as printed, or `none`.
	 *
	 * @param array<string, mixed> $report A verifyChain() result.
	 *
	 * @return string The broken entry id, or `none`.
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	private function brokenAtText(array $report): string {
		if (($report['brokenAt'] ?? null) !== null) {
			return (string)$report['brokenAt'];
		}

		return 'none';
	}//end brokenAtText()
}//end class
