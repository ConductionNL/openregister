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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\OpenRegister\Command\Support\PurgeSwitches;

/**
 * Permanently destroy object rows by UUID.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
class PurgeObjectCommand extends Command {

	/**
	 * Magic-table object lookup and delete.
	 *
	 * @var MagicMapperInterface
	 */
	private readonly MagicMapperInterface $objectMapper;

	/**
	 * Schema lookup, to read the archival annotation.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Wire the mappers.
	 *
	 * @param SchemaCatalogInterface    $catalog          Magic-table object lookup and delete, and the schema lookup that reads the archival annotation.
	 * @param AuditTrailMapperInterface $auditTrailMapper Resolves the objects an import job created.
	 *
	 * @return void
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
		$this->objectMapper = $catalog->tables();
		$this->schemaMapper = $catalog->schemas();
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:objects:purge')
			->setDescription(
				description: 'Permanently destroy object rows by UUID, including archival records with --force'
			)
			->addArgument(
				name: 'uuid',
				mode: InputArgument::IS_ARRAY,
				description: 'One or more object UUIDs to purge (optional with --import-job)'
			)
			->addOption(
				name: 'import-job',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Also purge every object this import job created, such as an app\'s example set'
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
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uuids = array_map('strval', (array)$input->getArgument('uuid'));
		$switches = new PurgeSwitches(force: (bool)$input->getOption('force'), apply: (bool)$input->getOption('apply'));
		$fromJob = $this->importJobUuids(importJobId: (string)($input->getOption('import-job') ?? ''), output: $output);

		if ($uuids === [] && $fromJob === null) {
			$output->writeln('<error>Name at least one object UUID, or an import job with --import-job.</error>');
			return 1;
		}

		$run = new PurgeRun(switches: $switches, output: $output);
		$failures = 0;
		foreach ($uuids as $uuid) {
			$failures += $this->purgeNamed(run: $run, uuid: $uuid);
		}

		// In job mode a missing object was removed already, so a re-run after
		// a partial purge reports it rather than failing on it.
		foreach (array_diff(($fromJob ?? []), $uuids) as $uuid) {
			$failures += $this->purgeFromJob(run: $run, uuid: $uuid);
		}

		if ($switches->apply === false) {
			$output->writeln('');
			$output->writeln('<comment>Dry run. Re-run with --apply to destroy these rows.</comment>');
		}

		if ($failures > 0) {
			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * The objects an import job created, or null when no job was named.
	 *
	 * @param string          $importJobId The --import-job value, or ''.
	 * @param OutputInterface $output      Console output.
	 *
	 * @return array<int, string>|null
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-the-cli-purge-must-accept-an-import-job-instead-of-a-list-of-uuids
	 */
	private function importJobUuids(string $importJobId, OutputInterface $output): ?array {
		$importJobId = trim($importJobId);
		if ($importJobId === '') {
			return null;
		}

		$uuids = $this->auditTrailMapper->objectUuidsByImportJobId(importJobId: $importJobId);
		$output->writeln(sprintf('import job %s created %d object(s)', $importJobId, count($uuids)));

		return $uuids;
	}//end importJobUuids()

	/**
	 * Handle a UUID the operator named; a missing object is a failure.
	 *
	 * @param PurgeRun $run  The operator's switches and the output.
	 * @param string   $uuid The object UUID.
	 *
	 * @return int 1 when the object was refused or could not be handled, 0 otherwise.
	 */
	private function purgeNamed(PurgeRun $run, string $uuid): int {
		try {
			$object = $this->findIncludingDeleted(uuid: $uuid);
		} catch (\Throwable $e) {
			$run->output->writeln(sprintf('<error>%s: not found (%s)</error>', $uuid, $e->getMessage()));
			return 1;
		}

		return $this->purgeCandidate(run: $run, candidate: $this->candidateFor(uuid: $uuid, object: $object));
	}//end purgeNamed()

	/**
	 * Handle a UUID an import job created; a missing object was removed already.
	 *
	 * @param PurgeRun $run  The operator's switches and the output.
	 * @param string   $uuid The object UUID.
	 *
	 * @return int 1 when the object was refused or could not be handled, 0 otherwise.
	 */
	private function purgeFromJob(PurgeRun $run, string $uuid): int {
		try {
			$object = $this->findIncludingDeleted(uuid: $uuid);
		} catch (\Throwable $e) {
			$run->output->writeln(sprintf('%s: already gone', $uuid));
			return 0;
		}

		return $this->purgeCandidate(run: $run, candidate: $this->candidateFor(uuid: $uuid, object: $object));
	}//end purgeFromJob()

	/**
	 * Find an object by UUID, trashed ones included, outside RBAC and tenancy.
	 *
	 * @param string $uuid The object UUID.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws \Throwable When no object matches.
	 */
	private function findIncludingDeleted(string $uuid): ObjectEntity {
		return $this->objectMapper->find(
			identifier: $uuid,
			register: null,
			schema: null,
			includeDeleted: true,
			_rbac: false,
			_multitenancy: false
		);
	}//end findIncludingDeleted()

	/**
	 * The candidate a found object makes, with its schema resolved.
	 *
	 * @param string       $uuid   The UUID as named.
	 * @param ObjectEntity $object The object it resolved to.
	 *
	 * @return PurgeCandidate The candidate.
	 */
	private function candidateFor(string $uuid, ObjectEntity $object): PurgeCandidate {
		return new PurgeCandidate(uuid: $uuid, object: $object, schema: $this->resolveSchema(object: $object));
	}//end candidateFor()

	/**
	 * Refuse, report or destroy one found object.
	 *
	 * @param PurgeRun       $run       The operator's switches and the output.
	 * @param PurgeCandidate $candidate The object and its schema.
	 *
	 * @return int 1 when the object was refused or could not be destroyed, 0 otherwise.
	 */
	private function purgeCandidate(PurgeRun $run, PurgeCandidate $candidate): int {
		$refusal = self::refusalReason(candidate: $candidate, switches: $run->switches);
		if ($refusal !== null) {
			$run->output->writeln(sprintf('<error>%s: refused — %s</error>', $candidate->uuid, $refusal));
			return 1;
		}

		$note = '';
		if ($candidate->isArchival() === true) {
			$note = ' <comment>(ARCHIVAL RECORD)</comment>';
		}

		if ($run->switches->apply === false) {
			$run->output->writeln(sprintf('would purge %s from "%s"%s', $candidate->uuid, $candidate->label(), $note));
			return 0;
		}

		try {
			$this->objectMapper->delete($candidate->object);
		} catch (\Throwable $e) {
			$run->output->writeln(sprintf('<error>%s: purge failed (%s)</error>', $candidate->uuid, $e->getMessage()));
			return 1;
		}

		$run->output->writeln(sprintf('<info>purged</info> %s from "%s"%s', $candidate->uuid, $candidate->label(), $note));
		return 0;
	}//end purgeCandidate()

	/**
	 * Why this object may not be purged, or null when it may.
	 *
	 * Both refusals are lifted by --force, and both exist for the same reason:
	 * the routine paths that create and remove rows have already declined, so
	 * the operator has to say out loud that they are overriding them.
	 *
	 * @param PurgeCandidate $candidate The object being purged and its schema.
	 * @param PurgeSwitches  $switches  The operator's --force and --apply switches.
	 *
	 * @return string|null The refusal reason, or null when the purge may proceed.
	 */
	private static function refusalReason(PurgeCandidate $candidate, PurgeSwitches $switches): ?string {
		if ($switches->force === true) {
			return null;
		}

		if ($candidate->isArchival() === true) {
			return sprintf(
				'schema "%s" declares x-openregister-archival. '
				. 'Pass --force to purge a legally retained record.',
				$candidate->label()
			);
		}

		if ($candidate->object->isSoftDeleted() === false) {
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
