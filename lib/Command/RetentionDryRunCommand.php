<?php

/**
 * OpenRegister RetentionDryRunCommand
 *
 * `occ openregister:retention:dry-run`: what the next destruction run would put
 * on a destruction list, and what it holds back because an ancestor is kept
 * permanently. Writes nothing.
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
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\Archival\DestructionDryRun;
use OCA\OpenRegister\Service\Archival\InheritedAppraisal;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the destruction dry run.
 *
 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
 */
class RetentionDryRunCommand extends Command {

	/**
	 * The command name.
	 *
	 * @var string
	 */
	public const NAME = 'openregister:retention:dry-run';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the dry run lazily, so occ boots without the retention graph.
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
	 */
	protected function configure(): void {
		$this->setName(name: self::NAME)
			->setDescription(
				description: 'List what the next destruction run would put on a destruction list, '
					.'and what it holds back because an ancestor is kept permanently. Writes nothing'
			)
			->addOption(
				name: 'json',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Print the result as JSON'
			);
	}//end configure()

	/**
	 * Run the dry run and print it.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0
	 *
	 * @spec openspec/changes/appraisal-inherited-from-a-parent/specs/archivering-vernietiging/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->container->get(DestructionDryRun::class)->run();

		if ($input->getOption('json') === true) {
			$output->writeln((string) json_encode($result));

			return 0;
		}

		$output->writeln(sprintf('Would go on a destruction list: %d. Nothing was written.', count($result['eligible'])));
		foreach ($result['eligible'] as $object) {
			$output->writeln(sprintf('  %s (%s)', self::label(name: $object['name']), $object['uuid']));
		}

		$output->writeln(sprintf('Held back by an inherited appraisal: %d.', count($result['heldBack'])));
		foreach ($result['heldBack'] as $object) {
			$output->writeln(sprintf('  %s (%s): %s', self::label(name: $object['name']), $object['uuid'], self::why(entry: $object)));
		}

		if ($result['truncated'] === true) {
			$output->writeln(sprintf('<comment>The scan stopped after %d objects; the next run continues where it left off.</comment>', $result['scanned']));
		}

		return 0;
	}//end execute()

	/**
	 * Why an object is held back, naming the ancestor and its appraisal.
	 *
	 * @param array<string, mixed> $entry A held-back entry.
	 *
	 * @return string
	 */
	private static function why(array $entry): string {
		$ancestor = $entry['ancestor'];
		if ($entry['reason'] === InheritedAppraisal::REASON_UNRESOLVED_ANCESTOR) {
			return sprintf('the ancestor %s in "%s" could not be read', $ancestor['uuid'], $ancestor['property']);
		}

		return sprintf(
			'held by its ancestor %s (%s), appraisal %s',
			self::label(name: $ancestor['name']),
			$ancestor['uuid'],
			($ancestor['appraisal'] ?? 'unknown')
		);
	}//end why()

	/**
	 * A name, or a placeholder for an unnamed object.
	 *
	 * @param string|null $name The name.
	 *
	 * @return string
	 */
	private static function label(?string $name): string {
		if ($name === null || $name === '') {
			return '(unnamed)';
		}

		return $name;
	}//end label()
}//end class
