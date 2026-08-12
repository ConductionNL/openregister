<?php

/**
 * OpenRegister resolver:list command
 *
 * Prints every `<context>_register` / `<context>_schema` IAppConfig key
 * configured for a given Conduction app, paired with its raw value.
 * Drives admin diagnostics for the canonical resolver convention; the
 * same enumeration also powers admin UIs via
 * `RegisterResolverService::enumerateAppConfigs()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\RegisterResolverService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List every resolver-shaped app-config key for the given app.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
class ResolverListCommand extends Command {
	/**
	 * Wire the command against the resolver service.
	 *
	 * @param RegisterResolverService $resolverService Resolver used for enumeration.
	 */
	public function __construct(
		private readonly RegisterResolverService $resolverService,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Define command name + the required `app-id` argument.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Phase 3 — Convention check + diagnostics: console command surface)
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:resolver:list')
			->setDescription(
				'List every `<context>_register` / `<context>_schema` IAppConfig key for the given Conduction app.'
			)
			->addArgument(
				'app-id',
				InputArgument::REQUIRED,
				'The consumer app id (e.g. opencatalogi, pipelinq, docudesk).'
			);

	}//end configure()

	/**
	 * Print the resolver-key inventory.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 *   (Requirement: enumerateAppConfigs — driven via this CLI for admin diagnostics)
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = (string)$input->getArgument('app-id');
		$map = $this->resolverService->enumerateAppConfigs($appId);

		if ($map === []) {
			$output->writeln(
				sprintf('<comment>No resolver-shaped config keys set for app "%s".</comment>', $appId)
			);
			return Command::SUCCESS;
		}

		$output->writeln(sprintf('<info>Resolver keys for app "%s":</info>', $appId));
		foreach ($map as $key => $value) {
			$output->writeln(sprintf('  <info>%s</info> = %s', $key, $value));
		}

		return Command::SUCCESS;
	}//end execute()
}//end class
