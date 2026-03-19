<?php

/**
 * OpenRegister Migrate Storage Command
 *
 * OCC command for migrating objects between blob storage and magic tables.
 *
 * @category  Command
 * @package   OCA\OpenRegister\Command
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\MigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command for migrating objects between blob storage and magic tables.
 *
 * Usage:
 *   php occ openregister:migrate-storage to-magic 1 5
 *   php occ openregister:migrate-storage to-blob 1 5 --dry-run
 *   php occ openregister:migrate-storage to-magic 1 5 --status
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
class MigrateStorageCommand extends Command
{
    /**
     * Constructor for MigrateStorageCommand.
     *
     * @param MigrationService $migrationService Migration service instance.
     */
    public function __construct(
        private readonly MigrationService $migrationService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:migrate-storage')
            ->setDescription('Migrate objects between blob storage and magic tables')
            ->addArgument(
                name: 'direction',
                mode: InputArgument::REQUIRED,
                description: 'Migration direction: to-magic or to-blob'
            )
            ->addArgument(
                name: 'register',
                mode: InputArgument::REQUIRED,
                description: 'Register ID or slug'
            )
            ->addArgument(
                name: 'schema',
                mode: InputArgument::REQUIRED,
                description: 'Schema ID or slug'
            )
            ->addOption(
                name: 'batch-size',
                shortcut: 'b',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Batch size for migration',
                default: '100'
            )
            ->addOption(
                name: 'dry-run',
                mode: InputOption::VALUE_NONE,
                description: 'Preview migration without making changes'
            )
            ->addOption(
                name: 'status',
                shortcut: 's',
                mode: InputOption::VALUE_NONE,
                description: 'Show storage status only'
            )
            ->setHelp(
                '<info>Migrate objects between blob storage and magic tables</info>

<comment>Directions:</comment>
  <info>to-magic</info>   Move objects from blob storage to a magic table
  <info>to-blob</info>    Move objects from a magic table to blob storage

<comment>Examples:</comment>
  <info>php occ openregister:migrate-storage to-magic 1 5</info>
    Migrate register 1, schema 5 objects from blob to magic table

  <info>php occ openregister:migrate-storage to-blob 1 5 --dry-run</info>
    Preview migration from magic table to blob storage

  <info>php occ openregister:migrate-storage to-magic 1 5 --status</info>
    Show current storage status for register 1, schema 5

  <info>php occ openregister:migrate-storage to-magic 1 5 -b 50</info>
    Migrate in batches of 50
'
            );
    }//end configure()

    /**
     * Execute the migration command.
     *
     * @param InputInterface  $input  Input interface.
     * @param OutputInterface $output Output interface.
     *
     * @return int Command exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $direction   = $input->getArgument('direction');
        $registerArg = $input->getArgument('register');
        $schemaArg   = $input->getArgument('schema');
        $batchSize   = (int) $input->getOption('batch-size');
        $dryRun      = $input->getOption('dry-run');
        $statusOnly  = $input->getOption('status');

        // Validate direction.
        if (in_array($direction, ['to-magic', 'to-blob'], true) === false) {
            $output->writeln('<error>Invalid direction: '.$direction.'. Use "to-magic" or "to-blob".</error>');
            return self::FAILURE;
        }

        // Resolve register and schema.
        try {
            $resolved = $this->migrationService->resolveRegisterAndSchema(
                registerId: $registerArg,
                schemaId: $schemaArg
            );
            $register = $resolved['register'];
            $schema   = $resolved['schema'];
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to resolve register/schema: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>OpenRegister Storage Migration</info>');
        $output->writeln('================================');
        $output->writeln('  Register: <comment>'.$register->getName().'</comment> (ID: '.$register->getId().')');
        $output->writeln('  Schema:   <comment>'.$schema->getName().'</comment> (ID: '.$schema->getId().')');
        $output->writeln('');

        // Show status.
        try {
            $status = $this->migrationService->getStorageStatus(register: $register, schema: $schema);
        } catch (\Exception $e) {
            $output->writeln('<error>Failed to get storage status: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        $output->writeln('<info>Storage Status:</info>');
        $output->writeln('  Blob storage:  <comment>'.$status['blobStorage']['count'].' objects</comment>');
        $magicExists = $status['magicTable']['exists'];
        if ($magicExists === true) {
            $magicInfo = $status['magicTable']['count'].' objects';
        } else {
            $magicInfo = 'does not exist';
        }

        $output->writeln('  Magic table:   <comment>'.$magicInfo.'</comment>');
        $output->writeln('');

        if ($statusOnly === true) {
            return self::SUCCESS;
        }

        // Run migration.
        if ($direction === 'to-magic') {
            $directionLabel = 'blob -> magic table';
        } else {
            $directionLabel = 'magic table -> blob';
        }

        $output->writeln('<info>Migrating: '.$directionLabel.'</info>');

        if ($dryRun === true) {
            $output->writeln('<comment>DRY RUN - no changes will be made</comment>');
        }

        $output->writeln('  Batch size: <comment>'.$batchSize.'</comment>');
        $output->writeln('');

        try {
            if ($direction === 'to-magic') {
                $report = $this->migrationService->migrateToMagicTable(
                    register: $register,
                    schema: $schema,
                    batchSize: $batchSize,
                    dryRun: $dryRun
                );
            } else {
                $report = $this->migrationService->migrateToBlobStorage(
                    register: $register,
                    schema: $schema,
                    batchSize: $batchSize,
                    dryRun: $dryRun
                );
            }
        } catch (\Exception $e) {
            $output->writeln('<error>Migration failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

        // Display report.
        $output->writeln('<info>Migration Report:</info>');
        $output->writeln('  Total source objects: <comment>'.$report['total'].'</comment>');
        $output->writeln('  Migrated:            <comment>'.$report['migrated'].'</comment>');
        $output->writeln('  Skipped (duplicate): <comment>'.$report['skipped'].'</comment>');
        $output->writeln('  Failed:              <comment>'.$report['failed'].'</comment>');

        if (count($report['errors']) > 0) {
            $output->writeln('');
            $output->writeln('<error>Errors:</error>');
            foreach ($report['errors'] as $error) {
                $output->writeln('  - UUID '.$error['uuid'].': '.$error['message']);
            }
        }

        $output->writeln('');

        if ($report['failed'] > 0) {
            $output->writeln('<error>Migration completed with errors.</error>');
            return self::FAILURE;
        }

        $output->writeln('<info>Migration completed successfully.</info>');
        return self::SUCCESS;
    }//end execute()
}//end class
