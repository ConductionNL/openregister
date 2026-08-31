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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SchemaDeletionService;
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
	 * @param SchemaMapper $schemaMapper Schema lookup mapper (app-scoped resolution).
	 * @param RegisterMapper $registerMapper Register mapper, to unlink the retired id.
	 * @param MagicMapper $magicMapper Magic table resolver, for the object-count guard.
	 * @param SchemaDeletionService $deletionService Cascade teardown (objects, tables, row).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $magicMapper,
		private readonly SchemaDeletionService $deletionService,
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
				'Also delete a schema that still owns objects (DANGEROUS — drops those objects). '
				. 'Has no effect without --apply.'
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = (string)$input->getOption('app');
		$slugs = (array)$input->getOption('slug');
		$apply = (bool)$input->getOption('apply');
		$force = (bool)$input->getOption('force');
		$dryRun = ($apply === false);

		if ($appId === '') {
			$output->writeln('<error>--app is required.</error>');
			return Command::FAILURE;
		}

		if ($slugs === []) {
			$output->writeln('<error>At least one --slug is required.</error>');
			return Command::FAILURE;
		}

		if ($dryRun === true) {
			$output->writeln(
				'<comment>Running in DRY-RUN mode — nothing will be deleted. '
				. 'Re-run with --apply to perform deletions.</comment>'
			);
		}

		$pruned = 0;
		$skipped = 0;
		$missing = 0;

		foreach ($slugs as $slug) {
			$slug = (string)$slug;
			$schema = $this->schemaMapper->findByApplicationAndSlug(slug: $slug, application: $appId);

			if ($schema === null) {
				$missing++;
				$output->writeln(
					sprintf(
						'<comment>SKIP "%s": app "%s" owns no schema with that slug (already pruned, or never imported).</comment>',
						$slug,
						$appId
					)
				);
				continue;
			}

			$schemaId = (int)$schema->getId();
			$objectCount = $this->countObjectsForSchema(schema: $schema);
			$linkedRegisters = $this->registersReferencing(schemaId: $schemaId);

			$output->writeln(
				sprintf(
					'<info>%s (id=%d, app=%s): %d object(s), referenced by %d register(s)</info>',
					$slug,
					$schemaId,
					$appId,
					$objectCount,
					count($linkedRegisters)
				)
			);

			if ($objectCount > 0 && $force === false) {
				$skipped++;
				$output->writeln(
					'  <comment>SKIP — still owns objects. Re-run with --force to drop them, '
					. 'or migrate them first.</comment>'
				);
				continue;
			}

			if ($dryRun === true) {
				$output->writeln(sprintf('  <comment>WOULD DELETE (objects=%d)</comment>', $objectCount));
				continue;
			}

			// Unlink first. cascadeDeleteSchema() removes the row, and a register
			// left pointing at a missing id makes every later slug resolution in
			// that register scan a dangling reference.
			foreach ($linkedRegisters as $register) {
				$remaining = self::unlinkSchemaId(schemaRefs: $register->getSchemas(), schemaId: $schemaId);
				$register->setSchemas($remaining);
				$this->registerMapper->update($register);
				$output->writeln(
					sprintf('  <info>unlinked from register id=%d (%s)</info>', (int)$register->getId(), (string)$register->getSlug())
				);
			}

			try {
				$result = $this->deletionService->cascadeDeleteSchema(schema: $schema);
			} catch (\Throwable $e) {
				$skipped++;
				$output->writeln(sprintf('  <error>FAILED: %s</error>', $e->getMessage()));
				continue;
			}

			$pruned++;
			$tableDropped = 'no';
			if ($result['tableDropped'] === true) {
				$tableDropped = 'yes';
			}

			$output->writeln(
				sprintf(
					'  <info>DELETED (objects removed=%d, table dropped=%s)</info>',
					(int)$result['deletedCount'],
					$tableDropped
				)
			);
		}//end foreach

		$suffix = '';
		if ($dryRun === true) {
			$suffix = ' (dry run — no writes performed)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Pruned=%d, skipped=%d, not-found=%d%s</info>',
				$pruned,
				$skipped,
				$missing,
				$suffix
			)
		);

		return Command::SUCCESS;
	}//end execute()

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
	 * Count objects a schema owns across every register that references it.
	 *
	 * @param Schema $schema The schema to count objects for.
	 *
	 * @return int Total object count across the schema's magic tables.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	private function countObjectsForSchema(Schema $schema): int {
		$total = 0;

		foreach ($this->registersReferencing(schemaId: (int)$schema->getId()) as $register) {
			if ($this->magicMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
				continue;
			}

			try {
				$total += $this->magicMapper->countObjectsInRegisterSchemaTable(
					query: [],
					register: $register,
					schema: $schema
				);
			} catch (\Throwable $e) {
				// An unreadable magic table cannot be counted. Treat it as
				// non-empty so the guard errs towards keeping data.
				$total++;
			}
		}

		return $total;
	}//end countObjectsForSchema()

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
