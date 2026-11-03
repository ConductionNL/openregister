<?php

/**
 * OpenRegister rbac:unknown-metadata-conditions command
 *
 * Lists the stored schemas and registers whose authorization rules carry a
 * condition on record metadata that no record carries.
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
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\MetadataConditionFindings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report stored rules conditioned on metadata no record carries. Reports only, never rewrites.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
class UnknownMetadataConditionsCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper   $schemaMapper   Reads every schema.
	 * @param RegisterMapper $registerMapper Reads every register.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:rbac:unknown-metadata-conditions')
			->setDescription(
				description: 'List schemas and registers whose authorization rules have a condition on record metadata'
					.' that no record carries (such as _published). Reports only; exits 1 when any is found.'
					.' Rules in an object\'s own _authorization are not scanned.'
			);
	}//end configure()

	/**
	 * Print one line per finding.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when no stored rule names unknown metadata, 1 otherwise.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$conditions = new MetadataConditionFindings();
		$found = 0;

		foreach ($this->schemaMapper->findAll(_rbac: false, _multitenancy: false) as $schema) {
			foreach ($conditions->schemaFindings(schema: $schema) as $finding) {
				$output->writeln(sprintf('schema %s: %s', (string)$schema->getId(), $finding));
				$found++;
			}
		}

		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			$subject = sprintf('register "%s"', (string)($register->getSlug() ?? $register->getTitle() ?? $register->getId()));
			foreach ($conditions->findings(authorization: $register->getAuthorization(), context: $subject) as $finding) {
				$output->writeln(sprintf('register %s: %s', (string)$register->getId(), $finding));
				$found++;
			}
		}

		$output->writeln('Rules in an object\'s own _authorization are not scanned.');

		if ($found === 0) {
			$output->writeln('<info>No stored rule has a condition on unknown record metadata.</info>');
			return Command::SUCCESS;
		}

		$output->writeln(sprintf('<comment>%d rule condition(s) can never match. Nothing was changed.</comment>', $found));
		return Command::FAILURE;
	}//end execute()
}//end class
