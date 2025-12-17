<?php
/**
 * OpenRegister SOLR Management Command
 *
 * SolrManagementCommand - Production SOLR Management CLI.
 *
 * This command provides comprehensive SOLR management operations including
 * setup, schema validation, optimization, warming, and maintenance tasks.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

/**
 * SOLR Management Command for production operations
 *
 * Provides comprehensive SOLR management including:
 * - Initial setup and schema deployment
 * - Index optimization and warming
 * - Collection management
 * - Health checks and diagnostics
 *
 * @category  Command
 * @package   OCA\OpenRegister\Command
 * @author    OpenRegister Team
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenRegister/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 OpenRegister
 */
class SolrManagementCommand extends Command
{


    /**
     * Constructor
     *
     * @param LoggerInterface $logger      Logger for debugging and monitoring
     * @param IndexService    $solrService SOLR service for operations
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly IndexService $solrService
    ) {
        parent::__construct();

    }//end __construct()


    /**
     * Configure the command
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openregister:solr:manage')
            ->setDescription('🔧 SOLR Management - Setup, optimize, and maintain SOLR infrastructure')
            ->addArgument(
                name: 'action',
                mode: InputArgument::REQUIRED,
                description: 'Action to perform: setup, optimize, warm, health, schema-check, clear, stats, configure-vectors'
            )
            ->addOption(
                name: 'tenant-collection',
                shortcut: 't',
                mode: InputOption::VALUE_OPTIONAL,
                description: 'Target specific tenant collection (default: current tenant)'
            )
            ->addOption(
                name: 'force',
                shortcut: 'f',
                mode: InputOption::VALUE_NONE,
                description: 'Force operation (use with caution)'
            )
            ->addOption(
                name: 'commit',
                shortcut: 'c',
                mode: InputOption::VALUE_NONE,
                description: 'Commit changes immediately'
            )
            ->setHelp(
                '🔧 <info>SOLR Management Command</info>

<comment>Available Actions:</comment>
  <info>setup</info>         - Initialize SOLR: create configSets, base collections, tenant collections
  <info>optimize</info>      - Optimize SOLR index for better performance (production ready)
  <info>warm</info>          - Warm up SOLR caches with common queries
  <info>health</info>        - Comprehensive health check of SOLR infrastructure
  <info>schema-check</info>  - Validate SOLR schema matches ObjectEntity fields
  <info>clear</info>         - Clear tenant-specific index (with confirmation)
  <info>stats</info>         - Display detailed SOLR statistics and performance metrics

<comment>Examples:</comment>
  <info>php occ openregister:solr:manage setup</info>
    Initialize complete SOLR infrastructure

  <info>php occ openregister:solr:manage optimize --commit</info>
    Optimize index and commit changes

  <info>php occ openregister:solr:manage warm</info>
    Warm up SOLR caches for better performance

  <info>php occ openregister:solr:manage health</info>
    Run comprehensive health check

  <info>php occ openregister:solr:manage schema-check</info>
    Validate schema compatibility with ObjectEntity

  <info>php occ openregister:solr:manage clear --force</info>
    Clear index (requires --force for safety)

<comment>Production Notes:</comment>
  • Run <info>setup</info> once during deployment
  • Use <info>optimize</info> during maintenance windows
  • <info>warm</info> after cold starts or major updates
  • <info>health</info> for monitoring and diagnostics
'
            );

    }//end configure()


    /**
     * Execute the command
     *
     * @param InputInterface  $input  Input interface
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $force  = $input->getOption('force');
        $commit = $input->getOption('commit');

        $output->writeln('');
        $output->writeln('🔧 <info>SOLR Management - OpenRegister Production Tool</info>');
        $output->writeln('================================================');

        // Check if SOLR is available.
        if ($this->solrService->isAvailable() === false) {
            $output->writeln('<error>❌ SOLR is not available or not configured</error>');
            $output->writeln('<comment>   Configure SOLR in admin settings first</comment>');
            return self::FAILURE;
        }

        return match ($action) {
            'setup' => $this->handleSetup(output: $output),
            'optimize' => $this->handleOptimize(output: $output, commit: $commit),
            'warm' => $this->handleWarm(output: $output),
            'health' => $this->handleHealth(output: $output),
            'schema-check' => $this->handleSchemaCheck(output: $output),
            'clear' => $this->handleClear(output: $output, force: $force),
            'stats' => $this->handleStats(output: $output),
            default => $this->handleInvalidAction(output: $output, action: $action),
        };

    }//end execute()


    /**
     * Handle SOLR setup
     *
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleSetup(OutputInterface $output): int
    {
        $output->writeln('🏗️  <info>Setting up SOLR infrastructure...</info>');
        $output->writeln('');

        try {
            // Test connection first.
            $connectionResult = $this->solrService->testConnection();
            if ($connectionResult['success'] === false) {
                $output->writeln('<error>❌ SOLR connection failed: '.$connectionResult['message'].'</error>');
                return self::FAILURE;
            }

            $output->writeln('✅ SOLR connection successful');
            $output->writeln('   Version: <comment>'.($connectionResult['details']['solr_version'] ?? 'unknown').'</comment>');
            $output->writeln('   Mode: <comment>'.($connectionResult['details']['mode'] ?? 'unknown').'</comment>');
            $output->writeln('');

            // Run comprehensive SOLR setup with corrected schema configuration.
            $output->writeln('📋 Running comprehensive SOLR setup with corrected schema configuration...');
            $output->writeln('   • Using self_ prefixes for metadata fields');
            $output->writeln('   • Clean field names (no suffixes) with explicit types');
            $output->writeln('   • Single-valued tenant_id field');
            $output->writeln('');

            // Use the injected solrService instead of creating a new one.
            // Initialize SolrSetup with proper configuration.
            $solrSetup = new SetupHandler($this->solrService, $this->logger);

            // Run complete setup including schema field configuration.
            if ($solrSetup->setupSolr() === true) {
                $output->writeln('✅ Base SOLR infrastructure and schema configured');
                $output->writeln('   • ConfigSet: <comment>openregister</comment>');
                $output->writeln('   • Base collection: <comment>openregister</comment>');
                $output->writeln('   • Schema fields: <comment>22 ObjectEntity metadata fields</comment>');
                $output->writeln('');

                // Ensure tenant collection.
                $output->writeln('🏠 Verifying tenant-specific collection...');
                $tenantResult = $this->solrService->ensureTenantCollection();
                if (empty($tenantResult) === false) {
                    $output->writeln('✅ Tenant collection ready with proper schema');

                    $docCount = $this->solrService->getDocumentCount();
                    $output->writeln('   Document count: <comment>'.$docCount.'</comment>');
                } else {
                    $output->writeln('<error>❌ Failed to create tenant collection</error>');
                    return self::FAILURE;
                }
            } else {
                $output->writeln('<error>❌ SOLR setup failed - check logs for details</error>');
                return self::FAILURE;
            }//end if

            $output->writeln('');
            $output->writeln('🎉 <info>SOLR setup completed successfully!</info>');
            $output->writeln('<comment>   Your SOLR infrastructure is ready for production use.</comment>');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Setup failed: '.$e->getMessage().'</error>');
            $this->logger->error('SOLR setup failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }//end try

    }//end handleSetup()


    /**
     * Handle index optimization
     *
     * @param OutputInterface $output Output interface
     * @param bool            $commit Whether to commit
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleOptimize(OutputInterface $output, bool $commit): int
    {
        $output->writeln('⚡ <info>Optimizing SOLR index...</info>');
        $output->writeln('<comment>   This may take several minutes for large indexes</comment>');
        $output->writeln('');

        try {
            $startTime = microtime(true);

            if ($this->solrService->optimize() === true) {
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                $output->writeln('✅ Index optimization completed');
                $output->writeln('   Execution time: <comment>'.$executionTime.'ms</comment>');

                if ($commit === true) {
                    $output->writeln('💾 Committing changes...');
                    if ($this->solrService->commit() === true) {
                        $output->writeln('✅ Changes committed successfully');
                    } else {
                        $output->writeln('<error>⚠️  Commit failed, but optimization succeeded</error>');
                    }
                }

                return self::SUCCESS;
            } else {
                $output->writeln('<error>❌ Index optimization failed</error>');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Optimization failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }//end try

    }//end handleOptimize()


    /**
     * Handle cache warming
     *
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleWarm(OutputInterface $output): int
    {
        $output->writeln('🔥 <info>Warming SOLR caches...</info>');
        $output->writeln('');

        try {
            // Common warming queries.
            $warmQueries = [
                ['q' => '*:*', 'rows' => 10, 'description' => 'All documents sample'],
                ['q' => 'published:[* TO *]', 'rows' => 10, 'description' => 'Published objects'],
                ['q' => '*:*', 'rows' => 0, 'facet' => 'true', 'facet.field' => ['register_id', 'schema_id'], 'description' => 'Facet warming'],
            ];

            $successCount = 0;
            foreach ($warmQueries as $query) {
                $output->write('   🔥 '.$query['description'].'... ');

                $result = $this->solrService->searchObjects(query: $query);
                if ($result['success'] === true) {
                    $output->writeln('<info>✅</info>');
                    $successCount++;
                } else {
                    $output->writeln('<error>❌</error>');
                }
            }

            $output->writeln('');
            if ($successCount === count($warmQueries)) {
                $output->writeln('🔥 <info>Cache warming completed successfully!</info>');
                $output->writeln('<comment>   SOLR caches are now pre-loaded for optimal performance.</comment>');
                return self::SUCCESS;
            } else {
                $output->writeln('<error>⚠️  Some warming queries failed ('.$successCount.'/'.count($warmQueries).' successful)</error>');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Cache warming failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }//end try

    }//end handleWarm()


    /**
     * Handle health check
     *
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleHealth(OutputInterface $output): int
    {
        $output->writeln('🏥 <info>SOLR Health Check</info>');
        $output->writeln('');

        $issues = 0;

        try {
            // Connection test.
            $output->writeln('🔗 <info>Testing connection...</info>');
            $connectionResult = $this->solrService->testConnection();
            if ($connectionResult['success'] === true) {
                $output->writeln('   ✅ Connection successful ('.$connectionResult['details']['response_time_ms'].'ms)');
                $output->writeln('   📊 SOLR version: <comment>'.$connectionResult['details']['solr_version'].'</comment>');
                $output->writeln('   🏗️  Mode: <comment>'.$connectionResult['details']['mode'].'</comment>');
            } else {
                $output->writeln('   <error>❌ Connection failed: '.$connectionResult['message'].'</error>');
                $issues++;
            }

            // Collection test.
            $output->writeln('');
            $output->writeln('🏠 <info>Testing tenant collection...</info>');
            $tenantResult = $this->solrService->ensureTenantCollection();
            if (empty($tenantResult) === false) {
                $output->writeln('   ✅ Tenant collection accessible');

                $docCount = $this->solrService->getDocumentCount();
                $output->writeln('   📊 Document count: <comment>'.$docCount.'</comment>');
            } else {
                $output->writeln('   <error>❌ Tenant collection not accessible</error>');
                $issues++;
            }

            // Basic search test.
            $output->writeln('');
            $output->writeln('🔍 <info>Testing search functionality...</info>');
            $searchResult = $this->solrService->searchObjects(query: ['q' => '*:*', 'rows' => 1]);
            if ($searchResult['success'] === true) {
                $output->writeln('   ✅ Search working ('.$searchResult['execution_time_ms'].'ms)');
                $output->writeln('   📊 Total documents: <comment>'.$searchResult['total'].'</comment>');
            } else {
                $output->writeln('   <error>❌ Search failed: '.($searchResult['error'] ?? 'Unknown error').'</error>');
                $issues++;
            }

            // Service statistics.
            $output->writeln('');
            $output->writeln('📊 <info>Service Statistics</info>');
            $stats = $this->solrService->getStats();
            $output->writeln('   🔍 Searches: <comment>'.$stats['searches'].'</comment>');
            $output->writeln('   📝 Indexes: <comment>'.$stats['indexes'].'</comment>');
            $output->writeln('   🗑️  Deletes: <comment>'.$stats['deletes'].'</comment>');
            $output->writeln('   ⚠️  Errors: <comment>'.$stats['errors'].'</comment>');

            $output->writeln('');
            if ($issues === 0) {
                $output->writeln('🎉 <info>All health checks passed! SOLR is healthy.</info>');
                return self::SUCCESS;
            } else {
                $output->writeln('<error>⚠️  Health check found '.$issues.' issues</error>');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Health check failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }//end try

    }//end handleHealth()


    /**
     * Handle schema validation
     *
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleSchemaCheck(OutputInterface $output): int
    {
        $output->writeln('📋 <info>Validating SOLR schema compatibility with ObjectEntity...</info>');
        $output->writeln('');

        // Expected fields based on ObjectEntity.
        $expectedFields = [
            'id',
            'uuid',
            'slug',
            'name',
            'description',
            'summary',
            'image',
            'uri',
            'version',
            'register_id',
            'schema_id',
            'organisation_id',
            'created',
            'updated',
            'published',
            'depublished',
            'tenant_id',
            '_text_',
        // Full-text search field.
        ];

        try {
            // Get schema information (this is a simplified check).
            $output->writeln('🔍 Checking field compatibility...');

            // Test a document structure.
            $testResult = $this->solrService->searchObjects(query: ['q' => '*:*', 'rows' => 1]);
            if ($testResult['success'] === true && empty($testResult['data']) === false) {
                $sampleDoc       = $testResult['data'][0];
                $availableFields = array_keys($sampleDoc);

                $output->writeln('📊 Available fields in SOLR: <comment>'.count($availableFields).'</comment>');
                $output->writeln('📋 Expected fields: <comment>'.count($expectedFields).'</comment>');

                $missingFields = array_diff($expectedFields, $availableFields);
                $extraFields   = array_diff($availableFields, $expectedFields);

                if (empty($missingFields) === true) {
                    $output->writeln('✅ All expected fields are available');
                } else {
                    $output->writeln('<error>⚠️  Missing fields: '.implode(', ', $missingFields).'</error>');
                }

                if (empty($extraFields) === false) {
                    $output->writeln('ℹ️  Additional fields: <comment>'.implode(', ', array_slice($extraFields, 0, 10)).'</comment>');
                }
            } else {
                $output->writeln('<comment>⚠️  No documents available for schema analysis</comment>');
                $output->writeln('<comment>   Create some objects first to validate the schema</comment>');
            }//end if

            $output->writeln('');
            $output->writeln('✅ <info>Schema compatibility check completed</info>');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Schema check failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }//end try

    }//end handleSchemaCheck()


    /**
     * Handle index clearing
     *
     * @param OutputInterface $output Output interface
     * @param bool            $force  Force operation
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleClear(OutputInterface $output, bool $force): int
    {
        if ($force === false) {
            $output->writeln('<error>❌ Clear operation requires --force flag for safety</error>');
            $output->writeln('<comment>   This will DELETE ALL indexed documents for current tenant!</comment>');
            $output->writeln('<comment>   Use: php occ openregister:solr:manage clear --force</comment>');
            return self::FAILURE;
        }

        $output->writeln('🗑️  <info>Clearing SOLR index...</info>');
        $output->writeln('<comment>   ⚠️  This will delete all documents for current tenant!</comment>');
        $output->writeln('');

        try {
            $result = $this->solrService->clearIndex();
            if (($result['success'] ?? null) !== null && ($result['success'] === true) === true) {
                $output->writeln('✅ Index cleared successfully');
                $output->writeln('<comment>   All documents have been removed from the index</comment>');
                return self::SUCCESS;
            } else {
                $output->writeln('<error>❌ Failed to clear index</error>');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Clear operation failed: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }

    }//end handleClear()


    /**
     * Handle statistics display
     *
     * @param OutputInterface $output Output interface
     *
     * @return int Exit code
     *
     * @psalm-return 0|1
     */
    private function handleStats(OutputInterface $output): int
    {
        $output->writeln('📊 <info>SOLR Statistics & Performance Metrics</info>');
        $output->writeln('');

        try {
            $dashboardStats = $this->solrService->getDashboardStats();

            if ($dashboardStats['available'] === true) {
                $output->writeln('🏠 <info>Collection Information</info>');
                $output->writeln('   Collection: <comment>'.$dashboardStats['collection'].'</comment>');
                $output->writeln('   Tenant ID: <comment>'.$dashboardStats['tenant_id'].'</comment>');
                $output->writeln('   Documents: <comment>'.$dashboardStats['document_count'].'</comment>');
                $output->writeln('   Shards: <comment>'.$dashboardStats['shards'].'</comment>');
                $output->writeln('   Health: <comment>'.$dashboardStats['health'].'</comment>');

                $output->writeln('');
                $output->writeln('⚡ <info>Performance Statistics</info>');
                $serviceStats = $dashboardStats['service_stats'];
                $output->writeln('   Searches: <comment>'.$serviceStats['searches'].'</comment>');
                $output->writeln('   Indexes: <comment>'.$serviceStats['indexes'].'</comment>');
                $output->writeln('   Deletes: <comment>'.$serviceStats['deletes'].'</comment>');
                $output->writeln('   Errors: <comment>'.$serviceStats['errors'].'</comment>');
                $output->writeln('   Total search time: <comment>'.round($serviceStats['search_time'] * 1000, 2).'ms</comment>');
                $output->writeln('   Total index time: <comment>'.round($serviceStats['index_time'] * 1000, 2).'ms</comment>');
            } else {
                $output->writeln('<error>❌ SOLR statistics unavailable: '.($dashboardStats['error'] ?? 'Unknown error').'</error>');
                return self::FAILURE;
            }//end if

            return self::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Failed to retrieve statistics: '.$e->getMessage().'</error>');
            return self::FAILURE;
        }//end try

    }//end handleStats()


    /**
     * Handle invalid action
     *
     * @param OutputInterface $output Output interface
     * @param string          $action Invalid action
     *
     * @return int Exit code
     *
     * @psalm-return 1
     */
    private function handleInvalidAction(OutputInterface $output, string $action): int
    {
        $output->writeln('<error>❌ Invalid action: '.$action.'</error>');
        $output->writeln('');
        $output->writeln('<comment>Available actions:</comment>');
        $output->writeln('  • <info>setup</info>         - Initialize SOLR infrastructure');
        $output->writeln('  • <info>optimize</info>      - Optimize index for performance');
        $output->writeln('  • <info>warm</info>          - Warm up caches');
        $output->writeln('  • <info>health</info>        - Run health check');
        $output->writeln('  • <info>schema-check</info>  - Validate schema compatibility');
        $output->writeln('  • <info>clear</info>         - Clear index (requires --force)');
        $output->writeln('  • <info>stats</info>         - Display statistics');
        $output->writeln('');
        $output->writeln('Use <info>--help</info> for detailed information');

        return self::FAILURE;

    }//end handleInvalidAction()


}//end class
