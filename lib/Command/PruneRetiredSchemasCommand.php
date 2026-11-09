<?php

/**
 * OpenRegister prune-retired-schemas command
 *
 * Removes a schema an app has RETIRED from its register descriptor.
 *
 * Deleting a schema from an app's `*_register.json` does not remove it from the
 * instance. `ImportHandler` UNIONS the freshly-imported schema ids into the
 * register's existing list and only prunes ids it has just shadowed by slug, so
 * a retired schema keeps its row, keeps its magic table and keeps its place in
 * the register's `schemas` array forever. On a shared instance that is exactly
 * how a cross-app slug collision survives the descriptor change that was meant
 * to end it.
 *
 * This command is the missing other half. It is deliberately EXPLICIT rather
 * than descriptor-diffing: a destructive sweep driven by "what is absent" would
 * delete every schema an app had not yet imported, so the operator names the
 * app and the slugs, and the app scoping means the command can never reach a
 * schema another app owns.
 *
 * Dry-run by default. Pass --apply to write.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\SchemaTeardownInterface;
use OCA\OpenRegister\Service\SchemaTeardownOverride;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove schemas an app has retired from its descriptor.
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PruneRetiredSchemasCommand extends Command {
	/**
	 * Wire the mappers and the cascade service.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema lookup mapper (app-scoped resolution).
	 * @param RegisterMapperInterface $registerMapper Register mapper, to unlink the retired id.
	 * @param SchemaTeardownInterface $deletionService Cascade teardown (objects, tables, row).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaTeardownInterface $deletionService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:schemas:prune-retired')
			->setDescription(
				'Remove schemas an app has retired from its register descriptor '
				. '(the import unions ids, so it never removes them itself).'
			)
			->addOption(
				'app',
				null,
				InputOption::VALUE_REQUIRED,
				'The owning application id, exactly as it appears in the schema\'s `application` column '
				. '(for example `filinq`). Scoping is mandatory: it is what stops this command reaching '
				. 'a same-slug schema another app owns.'
			)
			->addOption(
				'slug',
				null,
				(InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
				'A retired schema slug. Repeat the option to prune several.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually delete. Without this flag the command reports what it WOULD delete.'
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Also delete a schema that still owns objects. DANGEROUS: it drops those objects. '
				. 'Has no effect without --apply.'
			)
			->addOption(
				'force-archival',
				null,
				InputOption::VALUE_NONE,
				'Also delete a schema declaring x-openregister-archival, destroying records the '
				. 'operator may be legally required to retain. Deliberately separate from --force: '
				. 'an operator pruning a retired schema that happens to hold a few leftover rows has '
				. 'said nothing about retained records, and this flag is where they say it. Requires '
				. '--force and --apply.'
			)
			->addOption(
				'force-kept-rows',
				null,
				InputOption::VALUE_NONE,
				'Also delete a schema holding frozen, archived or locked rows, or rows of a register '
				. 'that no longer exists. Deliberately separate from --force, and written to the audit '
				. 'trail as a forced removal. Requires --force and --apply.'
			);
	}//end configure()

	/**
	 * Resolve each named slug within the app, then cascade-delete it.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = (string)$input->getOption('app');
		$slugs = (array)$input->getOption('slug');
		$apply = (bool)$input->getOption('apply');
		$force = (bool)$input->getOption('force');
		$override = new SchemaTeardownOverride(
			archivalSchema: (bool)$input->getOption('force-archival'),
			keptRows: (bool)$input->getOption('force-kept-rows')
		);
		$run = new PruneRetiredSchemasRun(appId: $appId, dryRun: ($apply === false), force: $force, override: $override);

		$invalid = $this->invalidArguments(appId: $appId, slugs: $slugs);
		if ($invalid !== null) {
			$output->writeln($invalid);
			return Command::FAILURE;
		}

		if ($run->dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode. Nothing will be deleted. '
				. 'Re-run with --apply to perform deletions.</comment>'
			);
		}

		foreach ($slugs as $slug) {
			$this->pruneSlug(slug: (string)$slug, run: $run, output: $output);
		}

		$suffix = '';
		if ($run->dryRun === true) {
			$suffix = ' (dry run, no writes performed)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Pruned=%d, skipped=%d, not-found=%d%s</info>',
				$run->pruned,
				$run->skipped,
				$run->missing,
				$suffix
			)
		);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * The error line for missing required options, or null when both are given.
	 *
	 * @param string            $appId The --app value.
	 * @param array<int, mixed> $slugs The --slug values.
	 *
	 * @return string|null The line to print, or null when the arguments are complete.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function invalidArguments(string $appId, array $slugs): ?string {
		if ($appId === '') {
			return '<error>--app is required.</error>';
		}

		if ($slugs === []) {
			return '<error>At least one --slug is required.</error>';
		}

		return null;
	}//end invalidArguments()

	/**
	 * Prune every row the app owns under one slug.
	 *
	 * @param string                 $slug   The retired schema slug.
	 * @param PruneRetiredSchemasRun $run    The run's flags and tally.
	 * @param OutputInterface        $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function pruneSlug(string $slug, PruneRetiredSchemasRun $run, OutputInterface $output): void {
		$appId = $run->appId;

		// EVERY row under this (application, slug), not the first.
		//
		// `findByApplicationAndSlug()` caps at one, which is correct where
		// the pair is unique. It is not unique in practice, and this command
		// exists because it is not: the import unions schema ids and never
		// removes one, so a descriptor edit leaves the old row behind and a
		// later import can add a second under the same pair. Reading one row
		// meant removing one of two and reporting success. Measured on a live
		// instance: opencatalogi owned two `document` rows (39 and 40), this
		// pruned 40, printed `Pruned=1, skipped=0`, and left 39 answering
		// every lookup.
		$schemas = $this->schemaMapper->findAllByApplicationAndSlug(slug: $slug, application: $appId);

		if ($schemas === []) {
			$run->missing++;
			$output->writeln(
				sprintf(
					'<comment>SKIP "%s": app "%s" owns no schema with that slug (already pruned, or never imported).</comment>',
					$slug,
					$appId
				)
			);
			return;
		}

		if (count($schemas) > 1) {
			$output->writeln(
				sprintf(
					'<comment>"%s": app "%s" owns %d rows under this slug. All of them are handled below.</comment>',
					$slug,
					$appId,
					count($schemas)
				)
			);
		}

		foreach ($schemas as $schema) {
			$this->pruneSchema(schema: $schema, slug: $slug, run: $run, output: $output);
		}
	}//end pruneSlug()

	/**
	 * Report one schema, apply the guards, then tear it down unless this is a dry run.
	 *
	 * @param Schema                 $schema The schema to prune.
	 * @param string                 $slug   Its slug, as the operator named it.
	 * @param PruneRetiredSchemasRun $run    The run's flags and tally.
	 * @param OutputInterface        $output Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function pruneSchema(Schema $schema, string $slug, PruneRetiredSchemasRun $run, OutputInterface $output): void {
		$schemaId = (int)$schema->getId();
		// The count the DELETE would produce, from the deletion service that
		// enumerates the tables — not a second, narrower count of its own.
		// The guard and the delete disagreeing is the whole defect.
		$objectCount = $this->deletionService->countObjectsCascadeWouldDelete(schema: $schema);
		$linkedRegisters = $this->registersReferencing(schemaId: $schemaId);

		$output->writeln(
			sprintf(
				'<info>%s (id=%d, app=%s): %d object(s), referenced by %d register(s)</info>',
				$slug,
				$schemaId,
				$run->appId,
				$objectCount,
				count($linkedRegisters)
			)
		);

		$skip = $this->skipReason(schema: $schema, objectCount: $objectCount, run: $run);
		if ($skip !== null) {
			$run->skipped++;
			$output->writeln($skip);
			return;
		}

		if ($run->dryRun === true) {
			$output->writeln(
				sprintf('  <comment>WOULD DELETE (objects=%d)</comment>%s', $objectCount, $this->forcedNote(schema: $schema))
			);
			return;
		}

		$this->tearDown(schema: $schema, objectCount: $objectCount, run: $run, output: $output);
	}//end pruneSchema()

	/**
	 * Why this schema is skipped before any write: the object guard, then the refusals.
	 *
	 * @param Schema                 $schema      The schema to prune.
	 * @param int                    $objectCount The objects the cascade would delete.
	 * @param PruneRetiredSchemasRun $run         The run's flags.
	 *
	 * @return string|null The line to print, or null when the schema may go.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function skipReason(Schema $schema, int $objectCount, PruneRetiredSchemasRun $run): ?string {
		if ($objectCount > 0 && $run->force === false) {
			return '  <comment>SKIP: still owns objects. Re-run with --force to drop them, '
				. 'or migrate them first.</comment>';
		}

		return $this->refusalOf(schema: $schema, override: $run->override);
	}//end skipReason()

	/**
	 * Cascade-delete one schema, unlink it from its registers and report the result.
	 *
	 * The forced note is read before the teardown, as the dry run reads it.
	 *
	 * @param Schema                 $schema      The schema to delete.
	 * @param int                    $objectCount The objects the guard counted.
	 * @param PruneRetiredSchemasRun $run         The run's flags and tally.
	 * @param OutputInterface        $output      Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function tearDown(
		Schema $schema,
		int $objectCount,
		PruneRetiredSchemasRun $run,
		OutputInterface $output
	): void {
		$forcedNote = $this->forcedNote(schema: $schema);

		try {
			$result = $this->deletionService->cascadeDeleteSchema(
				schema: $schema,
				override: $run->override
			);
		} catch (\Throwable $e) {
			$run->skipped++;
			$output->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
			return;
		}

		$this->unlinkFromRegisters(schemaId: (int)$schema->getId(), output: $output);

		$run->pruned++;
		$tableDropped = 'no';
		if ($result['tableDropped'] === true) {
			$tableDropped = 'yes';
		}

		$output->writeln(
			sprintf(
				'  <info>DELETED (objects removed=%d, table dropped=%s)</info>%s',
				(int)$result['deletedCount'],
				$tableDropped,
				$forcedNote
			)
		);

		// The count that gated the deletion and the count the deletion
		// reports must agree. When they do not, the guard above passed on a
		// number that did not describe what was destroyed, and the operator
		// who read "0 object(s)" in the dry run needs to know that.
		if ((int)$result['deletedCount'] !== $objectCount) {
			$output->writeln(
				sprintf(
					'  <error>COUNT MISMATCH: the guard saw %d object(s), the delete removed %d. '
					. 'The guard was wrong about what it was protecting.</error>',
					$objectCount,
					(int)$result['deletedCount']
				)
			);
		}
	}//end tearDown()

	/**
	 * Why this schema is skipped, told here as well as in the service.
	 *
	 * The operator reads which flag lifts the refusal instead of a raw exception.
	 * The archival condition comes from the production predicate the other delete
	 * doors read, not from a second reading of the annotation.
	 *
	 * @param Schema                 $schema   The schema to prune.
	 * @param SchemaTeardownOverride $override What the operator lifted.
	 *
	 * @return string|null The line to print, or null when the schema may go.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	private function refusalOf(Schema $schema, SchemaTeardownOverride $override): ?string {
		if ($schema->hasArchivalAnnotation() === true && $override->archivalSchema === false) {
			return '  <comment>SKIP: declares x-openregister-archival, so its objects are '
				. 'legally retained records. Re-run with --force-archival to destroy them.</comment>';
		}

		return $this->keptRowsRefusal(schema: $schema, override: $override);
	}//end refusalOf()

	/**
	 * The kept-rows refusal, unless the operator lifted it.
	 *
	 * @param Schema                 $schema   The schema to prune.
	 * @param SchemaTeardownOverride $override What the operator lifted.
	 *
	 * @return string|null The line to print, or null when the schema may go.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	private function keptRowsRefusal(Schema $schema, SchemaTeardownOverride $override): ?string {
		if ($override->keptRows === true) {
			return null;
		}

		$keptRows = $this->deletionService->countKeptRows(schema: $schema);
		if ($keptRows === 0) {
			return null;
		}

		return sprintf(
			'  <comment>SKIP: holds %d frozen, archived, locked or unreadable row(s). '
			. 'Re-run with --force-kept-rows to destroy them.</comment>',
			$keptRows
		);
	}//end keptRowsRefusal()

	/**
	 * The note that marks protected records in the command's output.
	 *
	 * @param Schema $schema The schema being pruned.
	 *
	 * @return string The note, empty when nothing protected goes.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-schema-teardown-refuses-kept-rows-unless-forced-on-the-command-line
	 */
	private function forcedNote(Schema $schema): string {
		$note = '';
		if ($schema->hasArchivalAnnotation() === true) {
			$note = ' <comment>(ARCHIVAL RECORDS)</comment>';
		}

		$keptRows = $this->deletionService->countKeptRows(schema: $schema);
		if ($keptRows > 0) {
			$note .= sprintf(' <comment>(%d KEPT ROWS)</comment>', $keptRows);
		}

		return $note;
	}//end forcedNote()

	/**
	 * Drop one schema id from a register's stored schema list.
	 *
	 * The stored list holds ids as ints or as strings depending on which import
	 * era wrote it, so `"74"` and `74` are the same reference and both have to
	 * go. A strict comparison here would leave the string form behind and the
	 * register would keep pointing at a row that no longer exists.
	 *
	 * Entries that are not numeric at all are preserved untouched: they are not
	 * this id, and silently dropping them would corrupt the register.
	 *
	 * @param array<int, mixed> $schemaRefs The register's stored schema list.
	 * @param int $schemaId The schema id to remove.
	 *
	 * @return array<int, mixed> The list with every form of that id removed.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public static function unlinkSchemaId(array $schemaRefs, int $schemaId): array {
		return array_values(
			array_filter(
				$schemaRefs,
				static function ($ref) use ($schemaId) {
					if (is_int($ref) === false && is_string($ref) === false) {
						return true;
					}

					if (is_string($ref) === true && is_numeric($ref) === false) {
						return true;
					}

					return ((int)$ref !== $schemaId);
				}
			)
		);
	}//end unlinkSchemaId()


	/**
	 * Take a removed schema out of every register that lists it.
	 *
	 * Runs after the teardown, so a refused teardown leaves the registers as they were.
	 * The registers are read here, not before the teardown, so a slow teardown cannot
	 * write back a stale list. A failed update is reported per register: the schema is
	 * gone by then, and a rerun would no longer find it.
	 *
	 * @param int             $schemaId The removed schema's id.
	 * @param OutputInterface $output   Console output stream.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function unlinkFromRegisters(int $schemaId, OutputInterface $output): void {
		foreach ($this->registersReferencing(schemaId: $schemaId) as $register) {
			$label = sprintf('register id=%d (%s)', (int)$register->getId(), (string)$register->getSlug());
			$register->setSchemas(self::unlinkSchemaId(schemaRefs: $register->getSchemas(), schemaId: $schemaId));

			try {
				$this->registerMapper->update($register);
				$output->writeln(sprintf('  <info>unlinked from %s</info>', $label));
			} catch (\Throwable $e) {
				$output->writeln(
					sprintf('  <error>NOT UNLINKED: %s still lists removed schema %d: %s</error>', $label, $schemaId, $e->getMessage())
				);
			}
		}
	}//end unlinkFromRegisters()

	/**
	 * Every register whose schema list references this schema id.
	 *
	 * @param int $schemaId The schema id to look for.
	 *
	 * @return Register[] The referencing registers.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function registersReferencing(int $schemaId): array {
		$matches = [];

		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			foreach ($register->getSchemas() as $ref) {
				if ((int)$ref === $schemaId) {
					$matches[] = $register;
					break;
				}
			}
		}

		return $matches;
	}//end registersReferencing()
}//end class
