<?php

/**
 * OpenRegister purge-object command
 *
 * The administrative escape hatch for permanently destroying an object row,
 * including one on an archival schema.
 *
 * Every HTTP delete route refuses an archival record. That refusal is the point
 * — an archival schema holds legally retained records, and the retention cron
 * is the only routine way a row leaves. But "no route at all" is not the same
 * as "no route": an instance still needs a way to remove a row created in
 * error, a test fixture, or a record whose retention obligation has ended by a
 * decision the platform cannot see.
 *
 * That way is this command, and it is a CLI command deliberately. `occ` requires
 * shell access to the server, which is a real authorization boundary rather than
 * a header an authenticated user can send, and it leaves the operator's own
 * shell history as the record of who did it. Purging an archival record
 * additionally requires --force, so it is never something an operator does by
 * reaching for the same flag they use for ordinary cleanup.
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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Permanently destroy object rows by UUID.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
class PurgeObjectCommand extends Command {

	/**
	 * Wire the mappers.
	 *
	 * @param MagicMapper  $objectMapper Magic-table object lookup and delete.
	 * @param SchemaMapper $schemaMapper Schema lookup, to read the archival annotation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:objects:purge')
			->setDescription(
				description: 'Permanently destroy object rows by UUID, including archival records with --force'
			)
			->addArgument(
				name: 'uuid',
				mode: (InputArgument::REQUIRED | InputArgument::IS_ARRAY),
				description: 'One or more object UUIDs to purge'
			)
			->addOption(
				name: 'force',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Also purge records on archival schemas, and records that are still live'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Actually destroy the rows. Without it the command reports what it would do'
			);
	}//end configure()

	/**
	 * Purge each named object.
	 *
	 * Dry-run by default: an operator sees exactly which rows would be
	 * destroyed, and which are archival, before anything is written.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 when every named object was handled, 1 when any was refused or failed.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uuids = $input->getArgument('uuid');
		$force = (bool)$input->getOption('force');
		$apply = (bool)$input->getOption('apply');

		$failures = 0;
		foreach ($uuids as $uuid) {
			$failures += $this->purgeOne(
				uuid: (string)$uuid,
				force: $force,
				apply: $apply,
				output: $output
			);
		}

		if ($apply === false) {
			$output->writeln('');
			$output->writeln('<comment>Dry run. Re-run with --apply to destroy these rows.</comment>');
		}

		if ($failures > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Handle a single UUID.
	 *
	 * @param string          $uuid   The object UUID.
	 * @param bool            $force  Whether archival and live rows may be purged.
	 * @param bool            $apply  Whether to actually write.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 1 when the object was refused or could not be handled, 0 otherwise.
	 */
	private function purgeOne(string $uuid, bool $force, bool $apply, OutputInterface $output): int {
		try {
			$object = $this->objectMapper->find(
				identifier: $uuid,
				register: null,
				schema: null,
				includeDeleted: true,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('<error>%s: not found (%s)</error>', $uuid, $e->getMessage()));
			return 1;
		}

		$schema = $this->resolveSchema(object: $object);
		$isArchival = ($schema !== null && $schema->hasArchivalAnnotation() === true);
		$label = ($schema?->getSlug() ?? (string)$object->getSchema());

		$refusal = $this->refusalReason(object: $object, isArchival: $isArchival, force: $force, label: $label);
		if ($refusal !== null) {
			$output->writeln(sprintf('<error>%s: refused — %s</error>', $uuid, $refusal));
			return 1;
		}

		$note = '';
		if ($isArchival === true) {
			$note = ' <comment>(ARCHIVAL RECORD)</comment>';
		}

		if ($apply === false) {
			$output->writeln(sprintf('would purge %s from "%s"%s', $uuid, $label, $note));
			return 0;
		}

		try {
			$this->objectMapper->delete($object);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('<error>%s: purge failed (%s)</error>', $uuid, $e->getMessage()));
			return 1;
		}

		$output->writeln(sprintf('<info>purged</info> %s from "%s"%s', $uuid, $label, $note));
		return 0;
	}//end purgeOne()

	/**
	 * Why this object may not be purged, or null when it may.
	 *
	 * Both refusals are lifted by --force, and both exist for the same reason:
	 * the routine paths that create and remove rows have already declined, so
	 * the operator has to say out loud that they are overriding them.
	 *
	 * @param ObjectEntity $object     The object being purged.
	 * @param bool         $isArchival Whether its schema declares the archival annotation.
	 * @param bool         $force      Whether the operator passed --force.
	 * @param string       $label      The schema slug or id, for the message.
	 *
	 * @return string|null The refusal reason, or null when the purge may proceed.
	 */
	private function refusalReason(ObjectEntity $object, bool $isArchival, bool $force, string $label): ?string {
		if ($force === true) {
			return null;
		}

		if ($isArchival === true) {
			return sprintf(
				'schema "%s" declares x-openregister-archival. '
				. 'Pass --force to purge a legally retained record.',
				$label
			);
		}

		if ($object->isSoftDeleted() === false) {
			return 'the object is live, not in the trash. Delete it first, or pass --force.';
		}

		return null;
	}//end refusalReason()

	/**
	 * Resolve an object's schema, or null when it cannot be found.
	 *
	 * @param ObjectEntity $object The object whose schema to resolve.
	 *
	 * @return Schema|null The schema, or null.
	 */
	private function resolveSchema(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveSchema()
}//end class
