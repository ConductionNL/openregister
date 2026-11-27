<?php

/**
 * OpenRegister ImportFilinqPolicyCommand: occ openregister:redaction-policy:import-filinq.
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
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\RedactionPolicy\FilinqPolicyImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Copies filinq's prohibitions and standing consents into the redaction-policy register's term rules.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
 */
class ImportFilinqPolicyCommand extends Command {

	/**
	 * The command name, which filinq calls.
	 *
	 * @var string
	 */
	public const NAME = 'openregister:redaction-policy:import-filinq';

	/**
	 * Constructor.
	 *
	 * @param FilinqPolicyImporter $importer The import.
	 */
	public function __construct(private readonly FilinqPolicyImporter $importer) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(
				'Copy filinq\'s publicationProhibition objects to always term rules and its entity-scoped publicationConsent '
				. 'objects to never term rules (layer local, source = the filinq uuid). Running it again updates, never duplicates.'
			);
	}//end configure()

	/**
	 * Run the import and print what it did.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success, also without a filinq register; 1 when the import failed.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-filinqs-lists-are-imported-into-the-engine-req-rpd-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$result = $this->importer->import();
		} catch (Throwable $error) {
			$output->writeln('<error>The filinq import failed: ' . $error->getMessage() . '</error>');
			return self::FAILURE;
		}

		if ($result['filinq'] === false) {
			$output->writeln('There is no filinq register on this instance, so there is nothing to import.');
			return self::SUCCESS;
		}

		$output->writeln(
			sprintf(
				'Imported filinq\'s lists: %d created, %d updated, %d unchanged, %d skipped.',
				$result['created'],
				$result['updated'],
				$result['unchanged'],
				count($result['skipped'])
			)
		);
		foreach ($result['skipped'] as $uuid) {
			$output->writeln('Skipped ' . $uuid . ': it has no match rule and no name to match on.');
		}

		return self::SUCCESS;
	}//end execute()
}//end class
