<?php

/**
 * Drain the backlog of unsealed audit rows on demand, with progress output.
 *
 * The background job drains the same backlog a few thousand rows at a time.
 * An operator with a large backlog runs this instead, to drain it now and watch
 * it go, or with --limit to take a measured bite. Both run the one driver,
 * AuditSealBacklogService, so they cannot seal differently.
 *
 * Unlike openregister:rechain-audit-trail this never rewrites a stored hash: it
 * only gives rows that have none a hash, which is why it needs no confirmation.
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
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Audit\AuditSealBacklogInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seals the unsealed audit rows after the newest chain link, window by window.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */
class SealAuditBacklogCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param AuditSealBacklogInterface $backlog Drains the backlog.
	 */
	public function __construct(
		private readonly AuditSealBacklogInterface $backlog,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:audit:seal-backlog')
			->setDescription(
				'Seal audit-trail rows that have no hash yet, oldest first, window by window. '
				. 'Never rewrites a stored hash; safe to interrupt and run again.'
			)
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Most rows to seal in this run (default: all)');

	}//end configure()

	/**
	 * Drain, printing one line per window and a summary.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Exit code: failure when the limit is not a positive number or a window was abandoned on the seal lock.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = $this->limitOf(value: $input->getOption('limit'));
		if ($limit === null) {
			$output->writeln('<error>--limit must be a positive whole number.</error>');
			return Command::FAILURE;
		}

		$result = $this->backlog->drain(
			maxRows: $limit,
			maxSeconds: 0,
			onWindow: static function (array $counters) use ($output): void {
				$output->writeln(
					sprintf(
						'  window %d: %d row(s) sealed so far',
						$counters['windowsProcessed'],
						$counters['rowsSealed']
					)
				);
			}
		);

		return $this->report(output: $output, result: $result);
	}//end execute()

	/**
	 * The row limit asked for: all rows without the option, null when it is not a positive number.
	 *
	 * @param mixed $value The raw option value.
	 *
	 * @return int|null The limit.
	 */
	private function limitOf(mixed $value): ?int {
		if ($value === null) {
			return PHP_INT_MAX;
		}

		if (is_string($value) === false || ctype_digit($value) === false || (int)$value < 1) {
			return null;
		}

		return (int)$value;
	}//end limitOf()

	/**
	 * Print the summary, the remedy for rows that cannot be sealed in place, and pick the exit code.
	 *
	 * @param OutputInterface                                                                                                $output Console output.
	 * @param array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string, unsealedBehindChain: int} $result The drain result.
	 *
	 * @return int Exit code.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	private function report(OutputInterface $output, array $result): int {
		$output->writeln(
			sprintf(
				'<info>Sealed %d row(s) in %d window(s); stopped: %s.</info>',
				$result['rowsSealed'],
				$result['windowsProcessed'],
				$result['stoppedBy']
			)
		);

		if ($result['unsealedBehindChain'] > 0) {
			$output->writeln(
				sprintf(
					'<comment>%d unsealed row(s) lie before the newest sealed row and cannot be sealed in place '
					. 'without breaking the chain. Rows from before the hash-chain cutover are legacy and do not '
					. 'fail verification; to seal them anyway run occ openregister:rechain-audit-trail.</comment>',
					$result['unsealedBehindChain']
				)
			);
		}

		if ($result['stoppedBy'] === 'lock') {
			$output->writeln('<error>The seal lock was busy; a window was left unsealed. Run the command again.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end report()
}//end class
