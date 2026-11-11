<?php

/**
 * occ openregister:schema:reset-to-shipped: reset one part of one schema to what its app shipped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\ShippedBaseline\ShippedBaselineResetServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Preview by default; `--apply --actor=<admin>` performs the reset as that administrator.
 *
 * The occ runner has no session, and the guard refuses a reset without an actor, so the
 * administrator is named explicitly and the trail records that name.
 *
 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
 */
class ResetSchemaToShippedCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param ShippedBaselineResetServiceInterface $reset The reset.
	 * @param IUserManager                $users   To find the actor.
	 * @param IGroupManager               $groups  To check the actor is an administrator.
	 * @param IUserSession                $session To act as the actor.
	 */
	public function __construct(
		private readonly ShippedBaselineResetServiceInterface $reset,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
		private readonly IUserSession $session,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:schema:reset-to-shipped')
			->setDescription(
				'Show, and with --apply perform, the reset of ONE part of ONE schema to what its app shipped.'
			)
			->addArgument('schema', InputArgument::REQUIRED, 'Schema id, uuid or slug')
			->addArgument('part', InputArgument::REQUIRED, 'The part, as a dotted path, for example authorization.read')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Perform the reset (without it, only show what would change)')
			->addOption('actor', null, InputOption::VALUE_REQUIRED, 'The administrator the reset is recorded under (required with --apply)');
	}//end configure()

	/**
	 * Run the command.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int The exit code.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$run = new ResetToShippedRun(input: $input, output: $output);

		try {
			if ((bool)$input->getOption('apply') === false) {
				return $this->showPreview(run: $run);
			}

			$outcome = $this->applyReset(run: $run);
		} catch (Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		return $this->reportReset(run: $run, outcome: $outcome);
	}//end execute()

	/**
	 * Show what the reset would change, without writing anything.
	 *
	 * @param ResetToShippedRun $run The run.
	 *
	 * @return int FAILURE when the part has nothing to reset, SUCCESS otherwise.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	private function showPreview(ResetToShippedRun $run): int {
		$preview = $this->reset->preview(schema: $run->schema(), path: $run->part());
		$this->describe(output: $run->output, outcome: $preview);
		if ($preview['applicable'] === false) {
			$run->output->writeln('<comment>Nothing to reset: ' . $preview['reason'] . '</comment>');
			return Command::FAILURE;
		}

		$run->output->writeln('<comment>Nothing written. Run again with --apply --actor=ADMIN_UID to reset.</comment>');
		return Command::SUCCESS;
	}//end showPreview()

	/**
	 * Reset the part as the named administrator, or refuse when --actor names none.
	 *
	 * @param ResetToShippedRun $run The run.
	 *
	 * @return array<string, mixed>|null The outcome, or null after printing the refusal.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	private function applyReset(ResetToShippedRun $run): ?array {
		$user = $this->administrator(actor: $run->actor());
		if ($user === null) {
			$run->output->writeln('<error>--apply needs --actor=UID of an administrator; the reset is recorded under that name.</error>');
			return null;
		}

		$this->actAs(user: $user);
		try {
			return $this->reset->reset(schema: $run->schema(), path: $run->part());
		} finally {
			$this->actAs(user: null);
		}
	}//end applyReset()

	/**
	 * The administrator --actor names, or null when it names nobody or a non-administrator.
	 *
	 * @param string $actor The trimmed --actor uid.
	 *
	 * @return IUser|null The administrator.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	private function administrator(string $actor): ?IUser {
		if ($actor === '') {
			return null;
		}

		$user = $this->users->get($actor);
		if ($user === null || $this->groups->isAdmin($user->getUID()) === false) {
			return null;
		}

		return $user;
	}//end administrator()

	/**
	 * Print the outcome of an applied reset and pick the exit code.
	 *
	 * @param ResetToShippedRun         $run     The run.
	 * @param array<string, mixed>|null $outcome The reset's outcome, or null when it was refused.
	 *
	 * @return int FAILURE when the reset was refused or the part was not reset, SUCCESS otherwise.
	 *
	 * @spec openspec/changes/shipped-baseline-reset-is-reachable/specs/schema-import/spec.md
	 */
	private function reportReset(ResetToShippedRun $run, ?array $outcome): int {
		if ($outcome === null) {
			return Command::FAILURE;
		}

		$this->describe(output: $run->output, outcome: $outcome);
		if ($outcome['applied'] === false) {
			$run->output->writeln('<error>Not reset: ' . $outcome['reason'] . '</error>');
			return Command::FAILURE;
		}

		$run->output->writeln(
			sprintf('<info>Reset %s of %s to the shipped value, recorded under %s.</info>', $run->part(), $outcome['schema'], $run->actor())
		);
		return Command::SUCCESS;
	}//end reportReset()

	/**
	 * Act as the named administrator for the reset, and as nobody after it.
	 *
	 * The session occ runs with lives in memory only, so this signs nobody in
	 * anywhere else; it is how the guard and the schema mapper see the actor.
	 *
	 * @param IUser|null $user The administrator, or null to stop acting.
	 *
	 * @return void
	 */
	private function actAs(?IUser $user): void {
		$this->session->setUser($user);
	}//end actAs()

	/**
	 * Print what the part is and what it would become.
	 *
	 * @param OutputInterface      $output  The output.
	 * @param array<string, mixed> $outcome The preview or the outcome.
	 *
	 * @return void
	 */
	private function describe(OutputInterface $output, array $outcome): void {
		$output->writeln(sprintf('Schema: %s (id %s)', $outcome['schema'], (string)($outcome['schemaId'] ?? '')));
		$output->writeln('Part:   ' . $outcome['path']);
		$output->writeln('Now:     ' . json_encode($outcome['from'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$output->writeln('Shipped: ' . json_encode($outcome['to'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end describe()
}//end class
