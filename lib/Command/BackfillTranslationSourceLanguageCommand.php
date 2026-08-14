<?php

/**
 * OpenRegister `openregister:translations:backfill-source-language` command.
 *
 * Idempotent one-shot back-fill for the `source_language` column added by
 * migration `Version1Date20260520120000`. Reads every register's
 * default language and updates every translation row whose
 * `source_language` is still the empty NOT-NULL default. Subsequent runs
 * report "0 rows updated".
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Back-fills `openregister_translations.source_language` from each row's
 * parent register default language.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
 */
class BackfillTranslationSourceLanguageCommand extends Command {
	/**
	 * Wire the mappers used by the command.
	 *
	 * @param RegisterMapper $registerMapper Register lookup mapper.
	 * @param TranslationMapper $translationMapper Translation sidecar mapper.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly TranslationMapper $translationMapper,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure command name + options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:translations:backfill-source-language')
			->setDescription(
				'Back-fill openregister_translations.source_language from each register default. Idempotent.'
			)
			->addOption(
				'batch-size',
				null,
				InputOption::VALUE_REQUIRED,
				'Maximum rows updated per register pass.',
				'1000'
			)
			->addOption(
				'dry-run',
				null,
				InputOption::VALUE_NONE,
				'Report rows that would be updated without writing.'
			);
	}//end configure()

	/**
	 * Execute the back-fill.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$batchSize = (int)$input->getOption('batch-size');
		if ($batchSize < 1) {
			$batchSize = 1000;
		}

		$pending = $this->translationMapper->countMissingSourceLanguage();
		$output->writeln(sprintf('<info>Translation rows pending back-fill: %d</info>', $pending));

		if ($pending === 0) {
			$output->writeln('<info>0 rows updated — column is fully back-filled.</info>');
			return Command::SUCCESS;
		}

		if ((bool)$input->getOption('dry-run') === true) {
			$output->writeln(
				sprintf(
					'<comment>Dry-run: would update up to %d rows (batch size %d).</comment>',
					$pending,
					$batchSize
				)
			);
			return Command::SUCCESS;
		}

		// Build register-id -> default-language map.
		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		$defaults = [];
		foreach ($registers as $register) {
			if (($register instanceof Register) === false) {
				continue;
			}

			$id = $register->getId();
			if ($id === null) {
				continue;
			}

			$defaults[(string)$id] = $register->getDefaultLanguage();
		}

		$updated = $this->translationMapper->backfillSourceLanguage($defaults, $batchSize);
		$output->writeln(sprintf('<info>%d rows updated.</info>', $updated));

		$remaining = $this->translationMapper->countMissingSourceLanguage();
		if ($remaining > 0) {
			$output->writeln(
				sprintf(
					'<comment>%d rows still pending — re-run the command to continue back-filling.</comment>',
					$remaining
				)
			);
		}

		return Command::SUCCESS;
	}//end execute()
}//end class
