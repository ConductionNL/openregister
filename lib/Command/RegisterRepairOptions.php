<?php

/**
 * OpenRegister register repair options
 *
 * The `--write` / `--register` pair and the dry-run notice shared by the
 * register repair commands that only change anything when told to.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The dry-run-by-default options of a register repair command.
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
final class RegisterRepairOptions {

	/**
	 * Declare `--write` and then `--register` on a command.
	 *
	 * @param Command $command             The command being configured.
	 * @param string  $registerDescription What `--register` limits the run to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public static function declareOn(Command $command, string $registerDescription): void {
		$command->addOption(
			'write',
			null,
			InputOption::VALUE_NONE,
			'Apply the changes. Without this flag nothing is modified.'
		)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				$registerDescription
			);
	}//end declareOn()

	/**
	 * The `--register` option as an id, or null when it was not given.
	 *
	 * @param InputInterface $input The console input.
	 *
	 * @return int|null The register id.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public static function registerId(InputInterface $input): ?int {
		$raw = $input->getOption('register');
		if ($raw === null) {
			return null;
		}

		return (int)$raw;
	}//end registerId()

	/**
	 * Print the closing line of a dry run.
	 *
	 * @param OutputInterface $output The console output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public static function writeDryRunNotice(OutputInterface $output): void {
		$output->writeln('<comment>DRY RUN — nothing was changed. Re-run with --write to apply.</comment>');
	}//end writeDryRunNotice()
}//end class
