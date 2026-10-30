<?php

/**
 * Occ command to reconcile magic-table columns against schema definitions.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Detect and repair magic-table column drift.
 *
 * A magic table gains a physical column per schema property. Before #2086,
 * adding a property to an EXISTING schema in an EXISTING register (via a
 * register.d fragment, with no seed data and no schema version bump) never
 * created the column — the change reached the schema definition but not the
 * table. Reads/writes of that property then silently dropped it, and a
 * property referenced by an authorization match rule made every read a 500.
 *
 * This command reconciles every register+schema pair: dry-run (default)
 * reports the drift, `--apply` calls the same `ensureTableForRegisterSchema()`
 * sync the import path uses, creating the missing columns. It is the
 * remediation for instances that already drifted before #2086/#2075 shipped.
 *
 * It also reconciles the METADATA columns (`_archived`, `_frozen`, `_status`,
 * ...) of every physical magic table, including tables whose schema no
 * register lists any more. Those were invisible to this command: measured on
 * 14 October 2026, it answered "No drift" while 38 tables lacked `_archived`
 * and every list of them failed, because 37 of the 38 were not linked from a
 * register. The metadata pass uses the same MagicMetadataColumnHealer the
 * request path and the AddObjectStateColumns repair step use. No maintenance
 * mode is needed.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
 */
class ReconcileMagicTablesCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Register lookups.
	 * @param MagicMapper $magicMapper Magic-table sync + introspection.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly MagicMapper $magicMapper,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:tables:reconcile')
			->setDescription(
				'Detect and repair magic-table column drift: physical columns missing for schema '
				. 'properties that were added to an existing schema without a subsequent write, and '
				. 'metadata columns (_archived, _frozen, _status, ...) missing from any magic table.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Create the missing columns. Without this flag the command runs in dry-run mode and '
				. 'only reports the drift it would repair.'
			)
			->addOption(
				'register',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit to a single register id (default: every register).'
			);

	}//end configure()

	/**
	 * Execute the reconciliation.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success (including "nothing to do"); 1 when a repair failed.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$registerFilter = $input->getOption('register');

		$metadata = $this->reconcileMetadataColumns(
			output: $output,
			apply: $apply,
			registerFilter: $registerFilter
		);
		$metadataNames = array_keys($this->magicMapper->getMetadataColumnDefinitions());

		$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

		$driftTables = 0;
		$driftColumns = 0;
		$repaired = 0;
		$failed = 0;

		foreach ($registers as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			if ($registerFilter !== null && (int)$register->getId() !== (int)$registerFilter) {
				continue;
			}

			$schemas = $this->registerMapper->getSchemasByRegisterId(
				registerId: (int)$register->getId(),
				_rbac: false,
				_multitenancy: false
			);

			foreach ($schemas as $schema) {
				if ($schema instanceof Schema === false) {
					continue;
				}

				try {
					$tableName = $this->magicMapper->getTableNameForRegisterSchema(
						register: $register,
						schema: $schema
					);

					// Table not materialised yet — ensureTable will create it on apply.
					$currentColumns = $this->magicMapper->getExistingTableColumns(tableName: $tableName);
					$requiredColumns = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);
					$missing = $this->magicMapper->findMissingColumns(
						currentColumns: $currentColumns,
						requiredColumns: $requiredColumns
					);
					// Metadata columns were reported (and on --apply added) by
					// the metadata pass above; reporting them again here would
					// count the same drift twice.
					$missing = array_diff_key($missing, array_flip($metadataNames));
					$narrow = $this->magicMapper->findDecimalColumnsNeedingWiden(
						currentColumns: $currentColumns,
						requiredColumns: $requiredColumns
					);
				} catch (\Throwable $e) {
					$output->writeln(
						sprintf(
							'<comment>skip r%d/%s (%s): %s</comment>',
							$register->getId(),
							$schema->getSlug(),
							$schema->getId(),
							$e->getMessage()
						)
					);
					continue;
				}//end try

				if (empty($missing) === true && empty($narrow) === true) {
					continue;
				}

				$driftTables++;
				$driftColumns += count($missing) + count($narrow);

				if (empty($missing) === false) {
					$output->writeln(
						sprintf(
							'r%d schema=%s(#%d) missing %d: %s',
							$register->getId(),
							$schema->getSlug(),
							$schema->getId(),
							count($missing),
							implode(', ', array_keys($missing))
						)
					);
				}

				if (empty($narrow) === false) {
					$output->writeln(
						sprintf(
							'r%d schema=%s(#%d) number columns to widen %d: %s',
							$register->getId(),
							$schema->getSlug(),
							$schema->getId(),
							count($narrow),
							implode(', ', $narrow)
						)
					);
				}

				if ($apply === false) {
					continue;
				}

				try {
					$this->magicMapper->ensureTableForRegisterSchema(
						register: $register,
						schema: $schema
					);
					$this->magicMapper->widenDecimalColumns(
						tableName: $tableName,
						currentColumns: $currentColumns,
						requiredColumns: $requiredColumns
					);
					$repaired++;
				} catch (\Throwable $e) {
					$failed++;
					$output->writeln(
						sprintf('  <error>repair failed: %s</error>', $e->getMessage())
					);
				}
			}//end foreach
		}//end foreach

		$driftTables += $metadata['tables'];
		$driftColumns += $metadata['columns'];
		$repaired += $metadata['repaired'];
		$failed += $metadata['failed'];

		if ($driftTables === 0) {
			$output->writeln('<info>No magic-table column drift found.</info>');
			return 0;
		}

		if ($apply === false) {
			$output->writeln(
				sprintf(
					'<comment>%d table(s), %d column(s) would be repaired. Re-run with --apply.</comment>',
					$driftTables,
					$driftColumns
				)
			);
			return 0;
		}

		$output->writeln(
			sprintf(
				'<info>Repaired %d/%d table(s); %d failed.</info>',
				$repaired,
				$driftTables,
				$failed
			)
		);

		if ($failed === 0) {
			return 0;
		}

		return 1;
	}//end execute()

	/**
	 * Report, and with --apply add, the metadata columns every magic table must carry.
	 *
	 * Walks the physical tables rather than the register to schema links, so a
	 * table no register lists any more is still reconciled.
	 *
	 * @param OutputInterface $output         Console output.
	 * @param bool            $apply          Whether to add the missing columns.
	 * @param mixed           $registerFilter The --register option, null for every register.
	 *
	 * @return array{tables: int, columns: int, repaired: int, failed: int} The counts for the summary.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	private function reconcileMetadataColumns(OutputInterface $output, bool $apply, mixed $registerFilter): array {
		$counts = ['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0];

		foreach ($this->magicMapper->listMagicTableNames() as $tableName) {
			if ($this->tableBelongsToRegister(tableName: $tableName, registerFilter: $registerFilter) === false) {
				continue;
			}

			try {
				$missing = $this->magicMapper->inspectMetadataColumns(tableName: $tableName);
			} catch (\Throwable $e) {
				$output->writeln(sprintf('<comment>skip %s: %s</comment>', $tableName, $e->getMessage()));
				continue;
			}

			if ($missing === []) {
				continue;
			}

			$counts['tables']++;
			$counts['columns'] += count($missing);
			$output->writeln(
				sprintf(
					'%s metadata missing %d: %s',
					$tableName,
					count($missing),
					implode(', ', array_keys($missing))
				)
			);

			if ($apply === false) {
				continue;
			}

			$result = $this->magicMapper->healMetadataColumns(tableName: $tableName);
			if ($result['failed'] === []) {
				$counts['repaired']++;
				continue;
			}

			$counts['failed']++;
			foreach ($result['failed'] as $column => $reason) {
				$output->writeln(sprintf('  <error>%s: %s</error>', $column, $reason));
			}
		}//end foreach

		return $counts;
	}//end reconcileMetadataColumns()

	/**
	 * Whether a table belongs to the register the --register option names.
	 *
	 * @param string $tableName      Bare magic table name (`openregister_table_<register>_<schema>`).
	 * @param mixed  $registerFilter The --register option, null for every register.
	 *
	 * @return bool True when no filter is set or the table's register id matches it.
	 */
	private function tableBelongsToRegister(string $tableName, mixed $registerFilter): bool {
		if ($registerFilter === null) {
			return true;
		}

		if (preg_match('/^'.preg_quote(MagicMapper::TABLE_PREFIX, '/').'(\d+)_\d+$/', $tableName, $matches) !== 1) {
			return false;
		}

		return (int)$matches[1] === (int)$registerFilter;
	}//end tableBelongsToRegister()
}//end class
