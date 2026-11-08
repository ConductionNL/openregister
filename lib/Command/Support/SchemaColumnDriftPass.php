<?php

/**
 * OpenRegister SchemaColumnDriftPass
 *
 * One run of the per-schema pass of `openregister:reconcile-magic-tables`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports, and on --apply repairs, the missing and too-narrow columns of each
 * schema's magic table, then prints the closing line of the whole command.
 *
 * Built once per command run: it carries that run's output, its --apply flag
 * and the metadata column names the metadata pass already handled.
 */
final class SchemaColumnDriftPass {

	/**
	 * Console output of this run.
	 *
	 * @var OutputInterface
	 */
	private OutputInterface $output;

	/**
	 * The magic mapper that reads and alters the tables.
	 *
	 * @var MagicMapperInterface
	 */
	private MagicMapperInterface $magicMapper;

	/**
	 * Whether --apply was given.
	 *
	 * @var bool
	 */
	private bool $apply;

	/**
	 * Metadata column names; the metadata pass reported (and on --apply added)
	 * them, so counting them here would count the same drift twice.
	 *
	 * @var list<string>
	 */
	private array $metadataNames;

	/**
	 * Start one run.
	 *
	 * @param OutputInterface $output Console output.
	 * @param MagicMapperInterface $magicMapper The magic mapper.
	 * @param array{apply: bool, metadata: list<string>} $options --apply and the metadata column names.
	 */
	public function __construct(OutputInterface $output, MagicMapperInterface $magicMapper, array $options) {
		$this->output = $output;
		$this->magicMapper = $magicMapper;
		$this->apply = $options['apply'];
		$this->metadataNames = $options['metadata'];
	}//end __construct()

	/**
	 * Report, and on --apply repair, the column drift of one schema's table.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 *
	 * @return array{tables: int, columns: int, repaired: int, failed: int} This schema's counts.
	 */
	public function reconcile(Register $register, Schema $schema): array {
		$counts = ['tables' => 0, 'columns' => 0, 'repaired' => 0, 'failed' => 0];
		$drift = $this->driftOrSkip(register: $register, schema: $schema);
		if ($drift === null || (empty($drift['missing']) === true && empty($drift['narrow']) === true)) {
			return $counts;
		}

		$counts['tables'] = 1;
		$counts['columns'] = count($drift['missing']) + count($drift['narrow']);
		$this->report(drift: $drift);
		if ($this->apply === true) {
			$repaired = $this->repair(drift: $drift);
			$counts['repaired'] = (int)$repaired;
			$counts['failed'] = (int)($repaired === false);
		}

		return $counts;
	}//end reconcile()

	/**
	 * Print the closing line of the command and pick its exit code.
	 *
	 * @param array<string, int> $counts The totals of both passes.
	 *
	 * @return int 0, or 1 when a repair failed.
	 */
	public function summarise(array $counts): int {
		if ($counts['tables'] === 0) {
			$this->output->writeln('<info>No magic-table column drift found.</info>');
			return 0;
		}

		if ($this->apply === false) {
			$this->output->writeln(
				sprintf(
					'<comment>%d table(s), %d column(s) would be repaired. Re-run with --apply.</comment>',
					$counts['tables'],
					$counts['columns']
				)
			);
			return 0;
		}

		$this->output->writeln(
			sprintf('<info>Repaired %d/%d table(s); %d failed.</info>', $counts['repaired'], $counts['tables'], $counts['failed'])
		);

		return (int)($counts['failed'] !== 0);
	}//end summarise()

	/**
	 * The drift of one schema's table, or null after printing why it was skipped.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 *
	 * @return array|null The drift, or null when inspecting the table threw.
	 */
	private function driftOrSkip(Register $register, Schema $schema): ?array {
		try {
			return $this->drift(register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->output->writeln(
				sprintf('<comment>skip r%d/%s (%s): %s</comment>', $register->getId(), $schema->getSlug(), $schema->getId(), $e->getMessage())
			);
			return null;
		}
	}//end driftOrSkip()

	/**
	 * The missing and too-narrow columns of one schema's table.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 *
	 * @return array{register: Register, schema: Schema, table: string, current: array, required: array, missing: array, narrow: array} The drift.
	 */
	private function drift(Register $register, Schema $schema): array {
		$tableName = $this->magicMapper->getTableNameForRegisterSchema(register: $register, schema: $schema);

		// Table not materialised yet — ensureTable will create it on apply.
		$current = $this->magicMapper->getExistingTableColumns(tableName: $tableName);
		$required = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);
		$missing = $this->magicMapper->findMissingColumns(currentColumns: $current, requiredColumns: $required);
		$narrow = $this->magicMapper->findDecimalColumnsNeedingWiden(currentColumns: $current, requiredColumns: $required);

		return [
			'register' => $register,
			'schema' => $schema,
			'table' => $tableName,
			'current' => $current,
			'required' => $required,
			'missing' => array_diff_key($missing, array_flip($this->metadataNames)),
			'narrow' => $narrow,
		];
	}//end drift()

	/**
	 * Print the missing and the too-narrow columns of one table.
	 *
	 * @param array $drift The drift from drift().
	 *
	 * @return void
	 */
	private function report(array $drift): void {
		$label = sprintf('r%d schema=%s(#%d)', $drift['register']->getId(), $drift['schema']->getSlug(), $drift['schema']->getId());
		if (empty($drift['missing']) === false) {
			$this->output->writeln(
				sprintf('%s missing %d: %s', $label, count($drift['missing']), implode(', ', array_keys($drift['missing'])))
			);
		}

		if (empty($drift['narrow']) === false) {
			$this->output->writeln(
				sprintf('%s number columns to widen %d: %s', $label, count($drift['narrow']), implode(', ', $drift['narrow']))
			);
		}
	}//end report()

	/**
	 * Add the missing columns and widen the narrow ones of one table.
	 *
	 * @param array $drift The drift from drift().
	 *
	 * @return bool True when the repair went through.
	 */
	private function repair(array $drift): bool {
		try {
			$this->magicMapper->ensureTableForRegisterSchema(register: $drift['register'], schema: $drift['schema']);
			$this->magicMapper->widenDecimalColumns(
				tableName: $drift['table'],
				currentColumns: $drift['current'],
				requiredColumns: $drift['required']
			);
			return true;
		} catch (\Throwable $e) {
			$this->output->writeln(sprintf('  <error>repair failed: %s</error>', $e->getMessage()));
			return false;
		}
	}//end repair()
}//end class
