<?php

/**
 * Lists how many drafts the draft cleanup would remove, per schema (decision 228).
 *
 * Read-only. A draft that nobody changed for its schema's lifetime is removed
 * by DraftExpiryJob, and the lifetime is 30 days unless the schema says
 * otherwise. On the first run after an upgrade that applies to drafts that
 * were kept until then. This command lets an administrator look first: it
 * counts what a run at this moment would remove, and removes nothing.
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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ openregister:drafts:expired`: the count per schema, and nothing removed.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
 */
class DraftsExpiredCommand extends Command {

	/**
	 * The name an administrator types.
	 *
	 * @var string
	 */
	public const NAME = 'openregister:drafts:expired';

	/**
	 * Constructor.
	 *
	 * The sweep is resolved when the command runs, not when occ lists its
	 * commands: it depends on the object service, and that graph is too
	 * heavy to build for every occ call.
	 *
	 * @param ContainerInterface $container Resolves the sweep on execute.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(description: 'List how many drafts the daily draft cleanup would remove per schema. Removes nothing')
			->addOption(
				name: 'json',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Print the counts as JSON'
			);
	}//end configure()

	/**
	 * Count and print.
	 *
	 * @param InputInterface  $input  Input.
	 * @param OutputInterface $output Output.
	 *
	 * @return int Always 0: drafts past their lifetime are a state to look at, not a failure.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$counts = $this->container->get(DraftExpiry::class)->preview(now: new DateTimeImmutable());

		if ($input->getOption('json') === true) {
			$rows = [];
			foreach ($counts['perSchema'] as $row) {
				$rows[] = ['register' => $row['register'], 'schema' => $row['schema'], 'lifetimeDays' => $row['lifetimeDays'], 'expired' => $row['expired']];
			}

			$output->writeln((string)json_encode(['schemas' => $counts['schemas'], 'expired' => $counts['expired'], 'perSchema' => $rows]));

			return 0;
		}

		$output->writeln(sprintf('Schemas whose drafts expire: %d. Nothing was removed.', $counts['schemas']));
		if ($counts['expired'] === 0) {
			$output->writeln('<info>No draft is past its lifetime.</info>');

			return 0;
		}

		foreach ($counts['perSchema'] as $row) {
			$output->writeln(
				sprintf('  %s / %s: %d drafts unchanged for more than %d days', $row['register'], $row['schema'], $row['expired'], $row['lifetimeDays'])
			);
		}

		$output->writeln(
			sprintf(
				'<comment>The next run of the draft cleanup removes %d drafts, at most %d per run.</comment>',
				min($counts['expired'], DraftExpiry::MAX_REMOVALS),
				DraftExpiry::MAX_REMOVALS
			)
		);
		$output->writeln('To keep the drafts of a schema, set "draftLifetimeDays": false in its configuration. A number sets another lifetime in days.');

		return 0;
	}//end execute()
}//end class
