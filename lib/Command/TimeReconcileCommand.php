<?php

/**
 * TimeReconcileCommand
 *
 * OCC command that recalculates denormalized per-object time totals from the
 * source entries in the link table. Repairs drift between the stored
 * total_minutes and the true sum of duration_minutes rows (AD-2).
 *
 * Usage:
 *   php occ openregister:time:reconcile
 *   php occ openregister:time:reconcile --dry-run
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\TimeLinkMapper;
use OCA\OpenRegister\Service\TimeEntryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile time totals in the link table.
 *
 * Iterates every distinct object UUID in openregister_time_links, sums the
 * duration_minutes rows, and writes the result back to total_minutes.
 * Each corrected row is audit-logged (spec requirement).
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
 */
class TimeReconcileCommand extends Command
{

    /**
     * Constructor.
     *
     * @param TimeLinkMapper  $timeLinkMapper  Time link mapper.
     * @param TimeEntryService $timeEntryService Time entry service for totals.
     * @param LoggerInterface  $logger           Logger.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
     */
    public function __construct(
        private readonly TimeLinkMapper $timeLinkMapper,
        private readonly TimeEntryService $timeEntryService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command.
     *
     * @return void
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:time:reconcile')
            ->setDescription('Recalculate denormalized per-object time totals from source entries')
            ->addOption(
                name: 'dry-run',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Show what would be corrected without writing changes'
            );
    }//end configure()

    /**
     * Execute the reconcile command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Command exit code.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption(name: 'dry-run') === true;

        if ($dryRun === true) {
            $output->writeln('<comment>Dry-run mode — no changes will be written.</comment>');
        }

        $uuids     = $this->timeLinkMapper->findDistinctObjectUuids();
        $total     = count($uuids);
        $corrected = 0;
        $skipped   = 0;

        $output->writeln("Processing $total object(s)...");

        foreach ($uuids as $uuid) {
            $trueTotal   = $this->timeLinkMapper->sumDurationByObjectUuid(objectUuid: $uuid);
            $storedLinks = $this->timeLinkMapper->findByObjectUuid(objectUuid: $uuid);
            $storedTotal = count($storedLinks) > 0 ? $storedLinks[0]->getTotalMinutes() : 0;

            if ($trueTotal === $storedTotal) {
                $skipped++;
                continue;
            }

            $output->writeln(
                sprintf(
                    '  <info>%s</info>: stored=%d, actual=%d → %s',
                    $uuid,
                    $storedTotal,
                    $trueTotal,
                    $dryRun === true ? 'would correct' : 'correcting'
                )
            );

            if ($dryRun === false) {
                $this->timeLinkMapper->updateTotalForObject(
                    objectUuid: $uuid,
                    totalMinutes: $trueTotal
                );
                $this->logger->info(
                    '[TimeReconcileCommand] Corrected total for object {uuid}: {old} → {new}',
                    ['uuid' => $uuid, 'old' => $storedTotal, 'new' => $trueTotal]
                );
            }

            $corrected++;
        }

        $action = $dryRun === true ? 'Would correct' : 'Corrected';
        $output->writeln(
            sprintf(
                '<info>Done.</info> %d corrected, %d already correct (total: %d).',
                $corrected,
                $skipped,
                $total
            )
        );
        $output->writeln("$action: $corrected object(s).");

        return Command::SUCCESS;
    }//end execute()
}//end class
