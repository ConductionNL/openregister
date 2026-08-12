<?php

/**
 * OpenRegister backfill-system-owner command
 *
 * One-shot data backfill that updates magic-table rows with an empty
 * `_owner` (legacy rows imported / created before #1645) to the
 * `__system__` sentinel so admin RBAC filtering surfaces them. Idempotent
 * by design: re-running on already-backfilled tables updates 0 rows.
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
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill the `__system__` sentinel on magic-table rows with empty `_owner`.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BackfillSystemOwnerCommand extends Command {
	/**
	 * Wire the mappers and database connection used by the command.
	 *
	 * @param RegisterMapper $registerMapper Register lookup mapper.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param MagicMapper $magicMapper Magic table resolver.
	 * @param IDBConnection $db Database connection for native UPDATE.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $magicMapper,
		private readonly IDBConnection $db,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:backfill-system-owner')
			->setDescription(
				'Backfill _owner=\'__system__\' on magic-table rows with empty _owner (legacy rows from before #1645).'
			)
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without writing to the database')
			->addOption('register', null, InputOption::VALUE_REQUIRED, 'Limit to a single register (slug, uuid or id)')
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Limit to a single schema (slug, uuid or id)');
	}//end configure()

	/**
	 * Iterate every magic table and backfill the system owner sentinel.
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
	 * @spec openspec/specs/auth-system/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');
		$registerRef = $input->getOption('register');
		$schemaRef = $input->getOption('schema');

		try {
			$registers = $this->resolveRegisters(registerRef: $registerRef);
			$schemas = $this->resolveSchemas(schemaRef: $schemaRef);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if (count($registers) === 0 || count($schemas) === 0) {
			$output->writeln('<comment>No registers or schemas resolved — nothing to do.</comment>');
			return Command::SUCCESS;
		}

		$modeLabel = '';
		if ($dryRun === true) {
			$modeLabel = ' (dry run)';
		}

		$output->writeln(
			sprintf(
				'<info>Backfilling _owner=\'%s\' on rows with _owner=\'\'%s</info>',
				OrganisationService::SYSTEM_USER_ID_DEFAULT,
				$modeLabel
			)
		);

		$grandScanned = 0;
		$grandUpdated = 0;
		$tablesTouched = 0;

		$schemasById = [];
		foreach ($schemas as $schema) {
			$schemasById[(int)$schema->getId()] = $schema;
		}

		foreach ($registers as $register) {
			$allowedSchemaIds = $register->getSchemas();
			foreach ($allowedSchemaIds as $allowedSchemaId) {
				$allowedId = (int)$allowedSchemaId;
				if (isset($schemasById[$allowedId]) === false) {
					continue;
				}

				$schema = $schemasById[$allowedId];

				if ($this->magicMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
					continue;
				}

				$tableName = $this->magicMapper->getTableNameForRegisterSchema(
					register: $register,
					schema: $schema
				);

				[$scanned, $updated] = $this->backfillTable(
					tableName: $tableName,
					dryRun: $dryRun
				);

				$grandScanned += $scanned;
				$grandUpdated += $updated;
				$tablesTouched += 1;

				$output->writeln(
					sprintf(
						'  %s/%s (%s): scanned=%d updated=%d',
						$register->getSlug() ?? $register->getId(),
						$schema->getSlug() ?? $schema->getId(),
						$tableName,
						$scanned,
						$updated
					)
				);
			}//end foreach
		}//end foreach

		$summarySuffix = '';
		if ($dryRun === true) {
			$summarySuffix = ' (dry run — no writes performed)';
		}

		$output->writeln(
			sprintf(
				'<info>Done. Tables=%d scanned=%d updated=%d%s</info>',
				$tablesTouched,
				$grandScanned,
				$grandUpdated,
				$summarySuffix
			)
		);

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Resolve the list of registers to operate on.
	 *
	 * @param string|null $registerRef Optional register slug, uuid or id.
	 *
	 * @return Register[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function resolveRegisters(?string $registerRef): array {
		if ($registerRef !== null && $registerRef !== '') {
			return [$this->registerMapper->find($registerRef, _multitenancy: false)];
		}

		return $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
	}//end resolveRegisters()

	/**
	 * Resolve the list of schemas to operate on.
	 *
	 * @param string|null $schemaRef Optional schema slug, uuid or id.
	 *
	 * @return Schema[]
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function resolveSchemas(?string $schemaRef): array {
		if ($schemaRef !== null && $schemaRef !== '') {
			return [$this->schemaMapper->find($schemaRef, _multitenancy: false)];
		}

		return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
	}//end resolveSchemas()

	/**
	 * Count and (unless dry-run) update rows with empty `_owner` in a magic table.
	 *
	 * @param string $tableName Fully qualified magic table name (without `oc_` prefix).
	 * @param bool $dryRun When true, only count rows.
	 *
	 * @return array{0:int,1:int} Tuple of [scanned, updated].
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	private function backfillTable(string $tableName, bool $dryRun): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($tableName)
			->where($qb->expr()->eq('_owner', $qb->createNamedParameter('')));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$scanned = (int)($row['cnt'] ?? 0);

		if ($scanned === 0 || $dryRun === true) {
			return [$scanned, 0];
		}

		$update = $this->db->getQueryBuilder();
		$update->update($tableName)
			->set('_owner', $update->createNamedParameter(OrganisationService::SYSTEM_USER_ID_DEFAULT))
			->where($update->expr()->eq('_owner', $update->createNamedParameter('')));

		$updated = (int)$update->executeStatement();

		return [$scanned, $updated];
	}//end backfillTable()
}//end class
