<?php
/**
 * Occ command to remove duplicate app configuration rows.
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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Detect and remove duplicate app configuration rows.
 *
 * The configuration row for an app is a global platform record. Before #2072,
 * the import find-or-create org-filtered its lookup, so importing under a
 * different active organisation than the row's owning org could not see the
 * existing row and inserted a NEW one — instances accrued thousands of
 * duplicate rows for a single app.
 *
 * This command keeps the newest row per app (the one the fixed import now
 * resolves) and removes the older, stale duplicates. Configuration rows are
 * pure metadata — deleting one does NOT touch the registers, schemas or
 * objects it references — so a direct bulk delete is safe. Dry-run by default;
 * `--apply` performs the delete; `--app` scopes to one app.
 */
class DedupeConfigurationsCommand extends Command
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(
        private readonly IDBConnection $db
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Configure the command name, description and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:configurations:dedupe')
            ->setDescription(
                'Remove duplicate app configuration rows (keeps the newest per app). '
                .'Configuration rows are metadata only — registers, schemas and objects are untouched.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Actually delete the duplicate rows. Without this flag the command runs in dry-run '
                .'mode and only reports what it would delete.'
            )
            ->addOption(
                'app',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit to a single app id (default: every app).'
            );

    }//end configure()

    /**
     * Execute the dedupe.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 on success.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply     = (bool) $input->getOption('apply');
        $appFilter = $input->getOption('app');

        // Read every configuration row's id + app, newest first.
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'app', 'title')
            ->from('openregister_configurations')
            ->orderBy('id', 'DESC');

        if ($appFilter !== null) {
            $qb->where($qb->expr()->eq('app', $qb->createNamedParameter($appFilter, IQueryBuilder::PARAM_STR)));
        }

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $plan = self::planDeletions(rows: $rows);

        $dupeApps  = count($plan);
        $deleteIds = [];

        foreach ($plan as $app => $group) {
            $deleteIds = array_merge($deleteIds, $group['delete']);
            $output->writeln(
                sprintf(
                    'app=%s: keep id=%d, %d duplicate(s): %s',
                    $app,
                    $group['keep'],
                    count($group['delete']),
                    implode(', ', array_slice($group['delete'], 0, 10)).$this->overflowMark(total: count($group['delete']))
                )
            );
        }

        // The number of rows this run would delete, as opposed to the number of
        // APPS they belong to. It was never assigned: under PHP 8 an undefined
        // variable reads as null, so `=== 0` was always false and the
        // "no duplicates" branch could never fire — a clean run reported
        // "0 app(s), row(s) would be deleted" instead of saying there was
        // nothing to do.
        $dupeRows = count($deleteIds);

        if ($dupeRows === 0) {
            $output->writeln('<info>No duplicate configuration rows found.</info>');
            return 0;
        }

        if ($apply === false) {
            $output->writeln(
                sprintf(
                    '<comment>%d app(s), %d duplicate row(s) would be deleted. Re-run with --apply.</comment>',
                    $dupeApps,
                    $dupeRows
                )
            );
            return 0;
        }

        // Delete in chunks to keep the IN() list bounded.
        $deleted = 0;
        foreach (array_chunk($deleteIds, 500) as $chunk) {
            $del = $this->db->getQueryBuilder();
            $del->delete('openregister_configurations')
                ->where($del->expr()->in('id', $del->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $deleted += $del->executeStatement();
        }

        $output->writeln(
            sprintf('<info>Deleted %d duplicate configuration row(s) across %d app(s).</info>', $deleted, $dupeApps)
        );

        return 0;

    }//end execute()

    /**
     * The "and N more" marker when a delete list was truncated at ten.
     *
     * @param int $total The full count.
     *
     * @return string The marker, or an empty string when nothing was truncated.
     */
    private function overflowMark(int $total): string
    {
        if ($total > 10) {
            return ', …';
        }

        return '';

    }//end overflowMark()

    /**
     * Plan which configuration rows to delete, grouped by app.
     *
     * Rows must be supplied newest-first (id DESC). Per app with more than one
     * row, the first (newest) is kept and the rest are marked for deletion —
     * the newest row carries the latest imported content and is the row the
     * org-agnostic import find now resolves. Rows without an app value are
     * ignored (nothing to dedupe against).
     *
     * @param array<int,array<string,mixed>> $rows Rows with 'id' and 'app', newest first.
     *
     * @return array<string,array{keep:int,delete:int[]}> Per-app keep/delete plan (only apps with duplicates).
     */
    public static function planDeletions(array $rows): array
    {
        $byApp = [];
        foreach ($rows as $row) {
            $app = (string) ($row['app'] ?? '');
            if ($app === '') {
                continue;
            }

            $byApp[$app][] = (int) $row['id'];
        }

        $plan = [];
        foreach ($byApp as $app => $ids) {
            if (count($ids) < 2) {
                continue;
            }

            $plan[$app] = [
                'keep'   => $ids[0],
                'delete' => array_slice($ids, 1),
            ];
        }

        return $plan;

    }//end planDeletions()
}//end class
