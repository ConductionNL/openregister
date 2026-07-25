<?php
/**
 * occ command to reconcile magic-table columns against schema definitions.
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
 */
class ReconcileMagicTablesCommand extends Command
{

    /**
     * Constructor.
     *
     * @param RegisterMapper $registerMapper Register lookups.
     * @param MagicMapper    $magicMapper    Magic-table sync + introspection.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly MagicMapper $magicMapper
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
        $this->setName(name: 'openregister:tables:reconcile')
            ->setDescription(
                'Detect and repair magic-table column drift — physical columns missing for schema '
                .'properties that were added to an existing schema without a subsequent write.'
            )
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Create the missing columns. Without this flag the command runs in dry-run mode and '
                .'only reports the drift it would repair.'
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
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int 0 on success (including "nothing to do"); 1 when a repair failed.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply         = (bool) $input->getOption('apply');
        $registerFilter = $input->getOption('register');

        $registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);

        $driftTables    = 0;
        $driftColumns   = 0;
        $repaired       = 0;
        $failed         = 0;

        foreach ($registers as $register) {
            if ($register instanceof Register === false) {
                continue;
            }

            if ($registerFilter !== null && (int) $register->getId() !== (int) $registerFilter) {
                continue;
            }

            $schemas = $this->registerMapper->getSchemasByRegisterId(
                registerId: (int) $register->getId(),
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
                    $missing         = $this->magicMapper->findMissingColumns(
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
                }

                if (empty($missing) === true) {
                    continue;
                }

                $driftTables++;
                $driftColumns += count($missing);

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

                if ($apply === false) {
                    continue;
                }

                try {
                    $this->magicMapper->ensureTableForRegisterSchema(
                        register: $register,
                        schema: $schema
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

        return ($failed === 0) ? 0 : 1;

    }//end execute()

}//end class
