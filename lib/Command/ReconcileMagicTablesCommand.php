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

use OCA\OpenRegister\Command\Support\SchemaColumnDriftPass;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
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
	 * @param RegisterMapperInterface $registerMapper Register lookups.
	 * @param MagicMapperInterface $magicMapper Magic-table sync + introspection.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly MagicMapperInterface $magicMapper,
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

		$counts = $this->reconcileMetadataColumns(run: new MetadataReconcileRun(output: $output, apply: $apply), registerFilter: $registerFilter);
		$pass = new SchemaColumnDriftPass(
			output: $output,
			magicMapper: $this->magicMapper,
			options: ['apply' => $apply, 'metadata' => array_keys($this->magicMapper->getMetadataColumnDefinitions())]
		);
		foreach ($this->registersToReconcile(registerFilter: $registerFilter) as $register) {
			foreach ($this->schemasOf(register: $register) as $schema) {
				$counts = $this->addCounts(counts: $counts, delta: $pass->reconcile(register: $register, schema: $schema));
			}
		}

		return $pass->summarise(counts: $counts);
	}//end execute()

	/**
	 * The registers the schema pass walks: every register, or the filtered one.
	 *
	 * @param mixed $registerFilter The --register option value, or null.
	 *
	 * @return list<Register> The registers.
	 */
	private function registersToReconcile(mixed $registerFilter): array {
		$registers = [];
		foreach ($this->registerMapper->findAll(_rbac: false, _multitenancy: false) as $register) {
			if ($register instanceof Register === false) {
				continue;
			}

			if ($registerFilter !== null && (int)$register->getId() !== (int)$registerFilter) {
				continue;
			}

			$registers[] = $register;
		}

		return $registers;
	}//end registersToReconcile()

	/**
	 * The schemas of one register, skipping anything that is not a schema.
	 *
	 * @param Register $register The register.
	 *
	 * @return list<Schema> The schemas.
	 */
	private function schemasOf(Register $register): array {
		$schemas = $this->registerMapper->getSchemasByRegisterId(
			registerId: (int)$register->getId(),
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($schemas, static fn (mixed $schema): bool => $schema instanceof Schema));
	}//end schemasOf()

	/**
	 * Sum two sets of counts.
	 *
	 * @param array<string, int> $counts The running counts.
	 * @param array<string, int> $delta  The counts to add.
	 *
	 * @return array<string, int> The sums.
	 */
	private function addCounts(array $counts, array $delta): array {
		foreach ($delta as $key => $value) {
			$counts[$key] += $value;
		}

		return $counts;
	}//end addCounts()

	/**
	 * Report, and with --apply add, the metadata columns every magic table must carry.
	 *
	 * Walks the physical tables rather than the register to schema links, so a
	 * table no register lists any more is still reconciled.
	 *
	 * @param MetadataReconcileRun $run            The pass.
	 * @param mixed                $registerFilter The --register option, null for every register.
	 *
	 * @return array{tables: int, columns: int, repaired: int, failed: int} The counts for the summary.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
	 */
	private function reconcileMetadataColumns(MetadataReconcileRun $run, mixed $registerFilter): array {
		foreach ($this->magicMapper->listMagicTableNames() as $tableName) {
			if ($this->tableBelongsToRegister(tableName: $tableName, registerFilter: $registerFilter) === true) {
				$this->reconcileTableMetadata(run: $run, tableName: $tableName);
			}
		}

		return $run->counts;
	}//end reconcileMetadataColumns()

	/**
	 * Report one table's missing metadata columns and, with --apply, add them.
	 *
	 * @param MetadataReconcileRun $run       The pass.
	 * @param string               $tableName Bare magic table name.
	 *
	 * @return void
	 */
	private function reconcileTableMetadata(MetadataReconcileRun $run, string $tableName): void {
		try {
			$missing = $this->magicMapper->inspectMetadataColumns(tableName: $tableName);
		} catch (\Throwable $e) {
			$run->output->writeln(sprintf('<comment>skip %s: %s</comment>', $tableName, $e->getMessage()));
			return;
		}

		if ($missing === []) {
			return;
		}

		$run->counts['tables']++;
		$run->counts['columns'] += count($missing);
		$run->output->writeln(sprintf('%s metadata missing %d: %s', $tableName, count($missing), implode(', ', array_keys($missing))));

		if ($run->apply === true) {
			$this->healTableMetadata(run: $run, tableName: $tableName);
		}
	}//end reconcileTableMetadata()

	/**
	 * Add one table's missing metadata columns and print what could not be added.
	 *
	 * @param MetadataReconcileRun $run       The pass.
	 * @param string               $tableName Bare magic table name.
	 *
	 * @return void
	 */
	private function healTableMetadata(MetadataReconcileRun $run, string $tableName): void {
		$result = $this->magicMapper->healMetadataColumns(tableName: $tableName);
		if ($result['failed'] === []) {
			$run->counts['repaired']++;
			return;
		}

		$run->counts['failed']++;
		foreach ($result['failed'] as $column => $reason) {
			$run->output->writeln(sprintf('  <error>%s: %s</error>', $column, $reason));
		}
	}//end healTableMetadata()

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

		if (preg_match('/^'.preg_quote(MagicMapperInterface::TABLE_PREFIX, '/').'(\d+)_\d+$/', $tableName, $matches) !== 1) {
			return false;
		}

		return (int)$matches[1] === (int)$registerFilter;
	}//end tableBelongsToRegister()
}//end class
