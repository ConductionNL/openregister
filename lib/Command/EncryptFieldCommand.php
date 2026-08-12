<?php

/**
 * OpenRegister encrypt-field command
 *
 * Migration/rollout path for field-level-object-encryption: encrypts existing
 * plaintext values of a property that has just been flagged
 * `x-openregister-encrypted: true` on its schema. Idempotent by design —
 * re-running skips values that are already an OpenRegister encryption
 * envelope, so it is safe to run repeatedly (e.g. after adding more data, or
 * to verify nothing was missed).
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
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-existing-plaintext-values-can-be-migrated-to-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Encrypt existing plaintext values of a newly-flagged property.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class EncryptFieldCommand extends Command {
	/**
	 * Wire the mappers, encryption handler and database connection used by the command.
	 *
	 * @param RegisterMapper $registerMapper Register lookup mapper.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param MagicMapper $magicMapper Magic table resolver.
	 * @param FieldEncryptionHandler $fieldEncryptionHandler Envelope encrypt/decrypt logic.
	 * @param IDBConnection $db Database connection for native SELECT/UPDATE.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $magicMapper,
		private readonly FieldEncryptionHandler $fieldEncryptionHandler,
		private readonly IDBConnection $db,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name, description, and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:encrypt-field')
			->setDescription(
				'Encrypt existing plaintext values of a property flagged x-openregister-encrypted. '
				. 'Idempotent: values already encrypted are skipped.'
			)
			->addOption('property', null, InputOption::VALUE_REQUIRED, 'The property name to encrypt (required)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without writing to the database')
			->addOption('register', null, InputOption::VALUE_REQUIRED, 'Limit to a single register (slug, uuid or id)')
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Limit to a single schema (slug, uuid or id)');
	}//end configure()

	/**
	 * Iterate every magic table whose schema flags the given property encrypted,
	 * and encrypt any plaintext value found.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output stream.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$property = (string)($input->getOption('property') ?? '');
		if ($property === '') {
			$output->writeln('<error>--property is required.</error>');
			return Command::FAILURE;
		}

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
			sprintf('<info>Encrypting property \'%s\'%s</info>', $property, $modeLabel)
		);

		$grandScanned = 0;
		$grandEncrypted = 0;
		$grandFailed = 0;
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

				if (in_array($property, $schema->getEncryptedProperties(), true) === false) {
					// This schema does not flag the property as encrypted — skip it
					// rather than encrypting a field nobody asked to protect.
					continue;
				}

				if ($this->magicMapper->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
					continue;
				}

				$tableName = $this->magicMapper->getTableNameForRegisterSchema(
					register: $register,
					schema: $schema
				);

				[$scanned, $encrypted, $failed] = $this->encryptTable(
					tableName: $tableName,
					property: $property,
					dryRun: $dryRun,
					output: $output
				);

				$grandScanned += $scanned;
				$grandEncrypted += $encrypted;
				$grandFailed += $failed;
				$tablesTouched += 1;

				$output->writeln(
					sprintf(
						'  %s/%s (%s): scanned=%d encrypted=%d failed=%d',
						$register->getSlug() ?? $register->getId(),
						$schema->getSlug() ?? $schema->getId(),
						$tableName,
						$scanned,
						$encrypted,
						$failed
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
				'<info>Done. Tables=%d scanned=%d encrypted=%d failed=%d%s</info>',
				$tablesTouched,
				$grandScanned,
				$grandEncrypted,
				$grandFailed,
				$summarySuffix
			)
		);

		// Fail loud: a run with per-row failures must not exit 0 — an operator
		// scripting this command needs to know some rows still hold plaintext.
		if ($grandFailed > 0) {
			$output->writeln('<error>One or more rows failed to encrypt — see log for details.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Resolve the list of registers to operate on.
	 *
	 * @param string|null $registerRef Optional register slug, uuid or id.
	 *
	 * @return Register[]
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
	 */
	private function resolveSchemas(?string $schemaRef): array {
		if ($schemaRef !== null && $schemaRef !== '') {
			return [$this->schemaMapper->find($schemaRef, _multitenancy: false)];
		}

		return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
	}//end resolveSchemas()

	/**
	 * Encrypt the plaintext values of one property across one magic table.
	 *
	 * Row-by-row (ICrypto::encrypt() is not batchable in SQL): SELECT id + the
	 * JSON `object` blob, decode, check the property value, encrypt if it is a
	 * non-empty string that is not already an envelope, re-encode, UPDATE.
	 * Also NULLs out any legacy dedicated column matching the property name
	 * (pre-existing from before the property was flagged encrypted) so no
	 * plaintext mirror survives outside the blob — best-effort: the column may
	 * not exist, which is expected and not an error.
	 *
	 * @param string $tableName Fully qualified magic table name.
	 * @param string $property The property name to encrypt.
	 * @param bool $dryRun When true, only count; never write.
	 * @param OutputInterface $output Console output for per-row failure logging.
	 *
	 * @return array{0:int,1:int,2:int} Tuple of [scanned, encrypted, failed].
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function encryptTable(string $tableName, string $property, bool $dryRun, OutputInterface $output): array {
		$columnName = preg_replace('/[^a-zA-Z0-9_]/', '', $property);

		$select = $this->db->getQueryBuilder();
		$select->select('id', 'object')->from($tableName);
		$result = $select->executeQuery();

		$scanned = 0;
		$encrypted = 0;
		$failed = 0;

		while (($row = $result->fetch()) !== false) {
			$objectJson = $row['object'] ?? null;
			if (is_string($objectJson) === false || $objectJson === '') {
				continue;
			}

			$objectData = json_decode($objectJson, true);
			if (is_array($objectData) === false || array_key_exists($property, $objectData) === false) {
				continue;
			}

			$value = $objectData[$property];
			if ($value === null || $value === '' || is_string($value) === false) {
				continue;
			}

			$scanned++;

			if ($this->fieldEncryptionHandler->isEnvelope($value) === true) {
				// Already encrypted — idempotent no-op.
				continue;
			}

			if ($dryRun === true) {
				$encrypted++;
				continue;
			}

			try {
				$objectData[$property] = $this->fieldEncryptionHandler->encryptValue($value);
			} catch (\Throwable $e) {
				$failed++;
				$output->writeln(
					sprintf(
						'  <error>row id=%s: failed to encrypt \'%s\': %s</error>',
						(string)($row['id'] ?? '?'),
						$property,
						$e->getMessage()
					)
				);
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update($tableName)
				->set('object', $update->createNamedParameter(json_encode($objectData)))
				->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();

			$encrypted++;
		}//end while

		$result->closeCursor();

		// Best-effort: null out a legacy dedicated column for this property, if
		// one still exists from before it was flagged encrypted. The column may
		// not exist (the common case going forward — buildTableColumnsFromSchema()
		// no longer creates one for an encrypted property) which is expected, not
		// an error, so an "unknown column" failure here is swallowed deliberately
		// (unlike decryption failures, which are never swallowed) — it is not
		// itself a source of data loss, only a no-op when there was nothing to null.
		if ($dryRun === false && $encrypted > 0) {
			try {
				$nullify = $this->db->getQueryBuilder();
				$nullify->update($tableName)
					->set($columnName, $nullify->createNamedParameter(null))
					->where($nullify->expr()->isNotNull($columnName));
				$nullify->executeStatement();
			} catch (DbException $e) {
				// Column does not exist (or is not nullable) — nothing to clean up.
				unset($e);
			}
		}

		return [$scanned, $encrypted, $failed];
	}//end encryptTable()
}//end class
