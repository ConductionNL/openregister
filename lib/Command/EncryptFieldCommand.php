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

use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FieldEncryptionHandlerInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Encrypt existing plaintext values of a newly-flagged property.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class EncryptFieldCommand extends MagicTableCommand {
	use MagicColumnNameTrait;

	/**
	 * Wire the table selection, encryption handler and database connection used by the command.
	 *
	 * @param MagicTableSelection $selection Finds the tables to work on.
	 * @param FieldEncryptionHandlerInterface $fieldEncryptionHandler Envelope encrypt/decrypt logic.
	 * @param IDBConnection $db Database connection for native SELECT/UPDATE.
	 *
	 * @return void
	 */
	public function __construct(
		MagicTableSelection $selection,
		private readonly FieldEncryptionHandlerInterface $fieldEncryptionHandler,
		private readonly IDBConnection $db,
	) {
		parent::__construct(selection: $selection);
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
			->addOption('property', null, InputOption::VALUE_REQUIRED, 'The property name to encrypt (required)');
		$this->addSelectionOptions();
	}//end configure()

	/**
	 * A run needs the property to encrypt.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return bool Whether to go on
	 */
	protected function isReady(MagicTableRun $run): bool {
		if ($this->property(run: $run) !== '') {
			return true;
		}

		$run->output->writeln('<error>--property is required.</error>');
		return false;
	}//end isReady()

	/**
	 * A schema that does not flag the property is skipped, rather than encrypting
	 * a field nobody asked to protect.
	 *
	 * @param Schema $schema The schema
	 * @param MagicTableRun $run The run
	 *
	 * @return bool Whether to work on its tables
	 */
	protected function applies(Schema $schema, MagicTableRun $run): bool {
		return in_array($this->property(run: $run), $schema->getEncryptedProperties(), true);
	}//end applies()

	/**
	 * Say what this run is about to do.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 */
	protected function announce(MagicTableRun $run): void {
		$run->output->writeln(
			sprintf('<info>Encrypting property \'%s\'%s</info>', $this->property(run: $run), $run->dryRunNote(words: ' (dry run)'))
		);
	}//end announce()

	/**
	 * Encrypt the plaintext values of the property across one magic table.
	 *
	 * Row-by-row (ICrypto::encrypt() is not batchable in SQL): a property lives in
	 * its own column, which holds the envelope afterwards.
	 *
	 * @param MagicTableTarget $target The table
	 * @param MagicTableRun $run The run
	 *
	 * @return void
	 */
	protected function workOn(MagicTableTarget $target, MagicTableRun $run): void {
		$pass = new FieldEncryptionPass(target: $target, run: $run);
		$pass->column = $this->sanitizeColumnName(name: $this->property(run: $run));
		$result = $this->filledRows(pass: $pass);

		while (($row = $result->fetch()) !== false) {
			$this->encryptRow(row: $row, pass: $pass);
		}

		$result->closeCursor();

		$run->scanned += $pass->scanned;
		$run->changed += $pass->encrypted;
		$run->failed += $pass->failed;
		$run->output->writeln(sprintf('  %s: scanned=%d encrypted=%d failed=%d', $target->label(), $pass->scanned, $pass->encrypted, $pass->failed));
	}//end workOn()

	/**
	 * Report the totals. Fails loud: a run with per-row failures must not exit 0, because
	 * an operator scripting this command needs to know some rows still hold plaintext.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return int Symfony command exit code.
	 */
	protected function finish(MagicTableRun $run): int {
		$totals = [$run->tables, $run->scanned, $run->changed, $run->failed, $run->dryRunNote(words: ' (dry run — no writes performed)')];
		$run->output->writeln(sprintf('<info>Done. Tables=%d scanned=%d encrypted=%d failed=%d%s</info>', ...$totals));

		if ($run->failed > 0) {
			$run->output->writeln('<error>One or more rows failed to encrypt — see log for details.</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end finish()

	/**
	 * The property this run encrypts.
	 *
	 * @param MagicTableRun $run The run
	 *
	 * @return string The property name, empty when none was given
	 */
	private function property(MagicTableRun $run): string {
		return (string)($run->input->getOption('property') ?? '');
	}//end property()

	/**
	 * The rows of the table with a value in the property's column.
	 *
	 * @param FieldEncryptionPass $pass The table and column
	 *
	 * @return IResult The rows
	 */
	private function filledRows(FieldEncryptionPass $pass): IResult {
		$select = $this->db->getQueryBuilder();
		$select->select('_id', $pass->column)
			->from($pass->target->tableName)
			->where($select->expr()->isNotNull($pass->column));

		return $select->executeQuery();
	}//end filledRows()

	/**
	 * Count one row and encrypt it, unless it holds no plaintext string or this is a dry run.
	 *
	 * @param array<string, mixed> $row The row
	 * @param FieldEncryptionPass $pass The table and column
	 *
	 * @return void
	 */
	private function encryptRow(array $row, FieldEncryptionPass $pass): void {
		$value = $row[$pass->column] ?? null;
		if ($value === '' || is_string($value) === false) {
			return;
		}

		$pass->scanned++;

		if ($this->fieldEncryptionHandler->isEnvelope($value) === true) {
			return;
		}

		if ($pass->run->isDryRun() === true) {
			$pass->encrypted++;
			return;
		}

		$this->writeEnvelope(row: $row, pass: $pass);
	}//end encryptRow()

	/**
	 * Replace the value of one row by its envelope, or report why that failed.
	 *
	 * @param array<string, mixed> $row The row
	 * @param FieldEncryptionPass $pass The table and column
	 *
	 * @return void
	 */
	private function writeEnvelope(array $row, FieldEncryptionPass $pass): void {
		try {
			$this->updateValue(row: $row, pass: $pass);
			$pass->encrypted++;
		} catch (\Throwable $e) {
			$pass->failed++;
			$property = $this->property(run: $pass->run);
			$pass->run->output->writeln(
				sprintf('  <error>row id=%s: failed to encrypt \'%s\': %s</error>', (string)($row['_id'] ?? '?'), $property, $e->getMessage())
			);
		}
	}//end writeEnvelope()

	/**
	 * Write the envelope of one row's value.
	 *
	 * @param array<string, mixed> $row The row
	 * @param FieldEncryptionPass $pass The table and column
	 *
	 * @return void
	 */
	private function updateValue(array $row, FieldEncryptionPass $pass): void {
		$update = $this->db->getQueryBuilder();
		$update->update($pass->target->tableName)
			->set($pass->column, $update->createNamedParameter($this->fieldEncryptionHandler->encryptValue($row[$pass->column])))
			->where($update->expr()->eq('_id', $update->createNamedParameter($row['_id'], IQueryBuilder::PARAM_INT)));
		$update->executeStatement();
	}//end updateValue()
}//end class
