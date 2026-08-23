<?php

/**
 * OpenRegister migrate-application command
 *
 * Re-points registers and schemas from one owning application id to another,
 * for use when a fleet app's `<id>` changes (procest -> dossiq, nldesign ->
 * thematiq, and the rest of the 2026 rename programme).
 *
 * WHY THIS HAS TO EXIST. An app declares its register through an OAS file whose
 * `x-openregister.app` names the owning application. On import, ImportHandler
 * resolves the two halves DIFFERENTLY:
 *
 *   - a register is matched by SLUG alone (`registerMapper->find($data['slug'])`)
 *     and then has `setApplication($appId)` applied, so it follows a rename on
 *     its own;
 *   - a schema is matched by `findByApplicationAndSlug($slug, $appId)`, i.e. by
 *     the PAIR.
 *
 * So when the app id changes, every schema lookup misses. The import does not
 * fail and does not warn: it takes the "not found, will create new one" branch
 * and builds a second, empty set of schemas under the new application id, while
 * the originals — and every object stored against them — stay behind under the
 * old one. The UI then renders empty collections and the API 404s for
 * `<oldapp>-<schema>`, which reads as missing data rather than as a rename that
 * was never finished.
 *
 * Run this BEFORE the first import under the new id, or after one to repair.
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

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move registers and schemas from one owning application id to another.
 */
class MigrateSchemaApplicationCommand extends Command {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(private readonly IDBConnection $db) {
		parent::__construct();

	}//end __construct()


	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'openregister:migrate-application')
			->setDescription('Re-point registers and schemas from one owning app id to another (app rename)')
			->addArgument('from', InputArgument::REQUIRED, 'The current application id, e.g. procest')
			->addArgument('to', InputArgument::REQUIRED, 'The new application id, e.g. dossiq')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');

	}//end configure()


	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 on success, 1 on refusal.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$from  = (string)$input->getArgument('from');
		$to    = (string)$input->getArgument('to');
		$dry   = (bool)$input->getOption('dry-run');

		if ($from === '' || $to === '') {
			$output->writeln('<error>Both `from` and `to` must be non-empty.</error>');
			return 1;
		}

		if ($from === $to) {
			$output->writeln('<error>`from` and `to` are the same id; nothing to do.</error>');
			return 1;
		}

		$schemas   = $this->countFor(table: 'openregister_schemas', application: $from);
		$registers = $this->countFor(table: 'openregister_registers', application: $from);

		$output->writeln(sprintf('Application "%s" currently owns %d schema(s) and %d register(s).', $from, $schemas, $registers));

		if (($schemas + $registers) === 0) {
			// Nothing under the old id is the expected state on a re-run, and
			// also the state after someone has already imported under the new
			// id. Those are not the same thing, so say which one this is
			// rather than reporting a bare success.
			$already = $this->countFor(table: 'openregister_schemas', application: $to);
			$output->writeln(sprintf('<info>Nothing owned by "%s". "%s" owns %d schema(s).</info>', $from, $to, $already));
			return 0;
		}

		// A slug already present under the target id means an import has run
		// under the new name and forked the schema. Moving the original on top
		// of it would leave two rows with the same (slug, application) and no
		// way to tell which one the objects belong to. Refuse and name them.
		$collisions = $this->collidingSlugs(from: $from, to: $to);
		if (empty($collisions) === false) {
			$output->writeln('<error>Refusing: these slugs already exist under the target application id.</error>');
			foreach ($collisions as $slug) {
				$output->writeln('  - ' . $slug);
			}

			$output->writeln('An import has already forked these schemas. Resolve the duplicates first (see openregister:schemas:dedup).');
			return 1;
		}

		if ($dry === true) {
			$output->writeln(
				sprintf(
					'<comment>Dry run: would move %d schema(s) and %d register(s) from "%s" to "%s".</comment>',
					$schemas,
					$registers,
					$from,
					$to
				)
			);
			return 0;
		}

		$movedSchemas   = $this->moveApplication(table: 'openregister_schemas', from: $from, to: $to);
		$movedRegisters = $this->moveApplication(table: 'openregister_registers', from: $from, to: $to);

		$output->writeln(sprintf('<info>Moved %d schema(s) and %d register(s) from "%s" to "%s".</info>', $movedSchemas, $movedRegisters, $from, $to));
		$output->writeln('Objects are unaffected: they reference their schema by id, which does not change here.');

		return 0;

	}//end execute()


	/**
	 * Count rows owned by an application id.
	 *
	 * @param string $table       The table to count in.
	 * @param string $application The owning application id.
	 *
	 * @return int The row count.
	 */
	private function countFor(string $table, string $application): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'c'))
			->from($table)
			->where($qb->expr()->eq('application', $qb->createNamedParameter($application)));

		$result = $qb->executeQuery();
		$row    = $result->fetch();
		$result->closeCursor();

		return (int)($row['c'] ?? 0);

	}//end countFor()


	/**
	 * Find slugs that exist under BOTH application ids.
	 *
	 * @param string $from The current application id.
	 * @param string $to   The new application id.
	 *
	 * @return string[] The colliding slugs.
	 */
	private function collidingSlugs(string $from, string $to): array {
		return self::planCollisions(
			fromSlugs: $this->slugsFor(application: $from),
			toSlugs: $this->slugsFor(application: $to)
		);

	}//end collidingSlugs()


	/**
	 * Which slugs appear under BOTH application ids.
	 *
	 * Pure and static so the refusal rule can be tested without a database —
	 * it is the part that must not be wrong. Matching is case-insensitive
	 * because that is how findByApplicationAndSlug() looks schemas up
	 * (`lower(slug)`); comparing case-sensitively here would report "no
	 * collision" for a pair the importer would nevertheless treat as the same
	 * schema.
	 *
	 * @param string[] $fromSlugs Slugs owned by the current application id.
	 * @param string[] $toSlugs   Slugs owned by the new application id.
	 *
	 * @return string[] The colliding slugs, lower-cased and unique.
	 */
	public static function planCollisions(array $fromSlugs, array $toSlugs): array {
		$target = [];
		foreach ($toSlugs as $slug) {
			$target[strtolower((string)$slug)] = true;
		}

		if (empty($target) === true) {
			return [];
		}

		$hits = [];
		foreach ($fromSlugs as $slug) {
			$lower = strtolower((string)$slug);
			if (isset($target[$lower]) === true) {
				$hits[$lower] = true;
			}
		}

		return array_keys($hits);

	}//end planCollisions()


	/**
	 * Read every schema slug owned by an application id.
	 *
	 * @param string $application The owning application id.
	 *
	 * @return string[] The slugs.
	 */
	private function slugsFor(string $application): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('slug')
			->from('openregister_schemas')
			->where($qb->expr()->eq('application', $qb->createNamedParameter($application)));

		$result = $qb->executeQuery();
		$slugs  = [];
		while (($row = $result->fetch()) !== false) {
			$slugs[] = (string)$row['slug'];
		}

		$result->closeCursor();

		return $slugs;

	}//end slugsFor()


	/**
	 * Re-point every row owned by `from` at `to`.
	 *
	 * @param string $table The table to update.
	 * @param string $from  The current application id.
	 * @param string $to    The new application id.
	 *
	 * @return int The number of rows updated.
	 */
	private function moveApplication(string $table, string $from, string $to): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('application', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('application', $qb->createNamedParameter($from)));

		return (int)$qb->executeStatement();

	}//end moveApplication()


}//end class
