<?php
/**
 * OpenRegister SOLR Debug Command
 *
 * SOLR Debug Command for testing SOLR functionality step by step.
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

use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * SOLR Debug Command for testing SOLR functionality step by step
 *
 * @category  Command
 * @package   OCA\OpenRegister\Command
 * @author    OpenRegister Team
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 */
class SolrDebugCommand extends Command
{
    /**
     * Constructor
     *
     * Initializes the SOLR debug command with required services.
     *
     * @param SettingsService $settingsService Settings service for SOLR configuration
     * @param LoggerInterface $logger          Logger for debugging output
     * @param IConfig         $config          Nextcloud configuration
     * @param IClientService  $clientService   HTTP client service (unused)
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IConfig $config,
        /**
         * HTTP client service (unused but required by dependency injection).
         *
         * @psalm-suppress UnusedProperty
         */
        private readonly IClientService $clientService
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
        $this
            ->setName('openregister:solr:debug')
            ->setDescription('Debug SOLR configuration and functionality step by step')
            ->addOption(
                'setup',
                's',
                InputOption::VALUE_NONE,
                'Run SOLR setup process'
            )
            ->addOption(
                'test-connection',
                't',
                InputOption::VALUE_NONE,
                'Test SOLR connection'
            )
            ->addOption(
                'check-cores',
                'c',
                InputOption::VALUE_NONE,
                'Check existing cores/collections'
            )
            ->addOption(
                'tenant-info',
                'i',
                InputOption::VALUE_NONE,
                'Show tenant information'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Run all debug steps'
            );

    }//end configure()

    /**
     * Execute the command
     *
     * @param InputInterface  $input  Input interface
     * @param OutputInterface $output Output interface
     *
     * @return int Command exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>🔍 SOLR Debug Tool - OpenRegister Multi-Tenant</info>');
        $output->writeln('================================================');

        $runAll = $input->getOption('all');

        if ($runAll === true || $input->getOption('tenant-info') === true) {
            $this->showTenantInfo($output);
        }

        if ($runAll === true || $input->getOption('setup') === true) {
            $this->testSetup($output);
        }

        if ($runAll === true || $input->getOption('test-connection') === true) {
            $this->testConnection($output);
        }

        if ($runAll === true || $input->getOption('check-cores') === true) {
            $this->checkCores($output);
        }

        $hasSetup          = $input->getOption('setup') === true;
        $hasTestConnection = $input->getOption('test-connection') === true;
        $hasCheckCores     = $input->getOption('check-cores') === true;
        $hasTenantInfo     = $input->getOption('tenant-info') === true;

        if ($runAll === false && $hasSetup === false && $hasTestConnection === false && $hasCheckCores === false && $hasTenantInfo === false) {
            $output->writeln('<comment>No options specified. Use --all or specific options like --setup, --test-connection, --check-cores</comment>');
            return Command::SUCCESS;
        }

        return Command::SUCCESS;

    }//end execute()

    /**
     * Show tenant information
     *
     * @param OutputInterface $output Output interface
     *
     * @return void
     */
    private function showTenantInfo(OutputInterface $output): void
    {
        $output->writeln('<info>📋 Tenant Information</info>');

        // Generate tenant ID the same way as SolrService.
        $instanceId    = $this->config->getSystemValue(key: 'instanceid', default: 'default');
        $overwriteHost = $this->config->getSystemValue(key: 'overwrite.cli.url', default: '');

        // Use overwrite host for tenant ID if set, otherwise use instance ID.
        $tenantId = empty($overwriteHost) === false ? 'nc_'.hash('crc32', $overwriteHost) : 'nc_'.substr($instanceId, 0, 8);

        // Display overwrite host value or 'not set'.
        $overwriteHostDisplay = ($overwriteHost !== '' && $overwriteHost !== null) ? $overwriteHost : 'not set';

        $output->writeln("  Instance ID: <comment>$instanceId</comment>");
        $output->writeln("  Overwrite Host: <comment>$overwriteHostDisplay</comment>");
        $output->writeln("  Generated Tenant ID: <comment>$tenantId</comment>");

        // Get SOLR settings.
        $solrSettings       = $this->settingsService->getSolrSettings();
        $baseCoreName       = $solrSettings['core'] ?? 'openregister';
        $tenantSpecificCore = $baseCoreName.'_'.$tenantId;

        $output->writeln("  Base Core Name: <comment>$baseCoreName</comment>");
        $output->writeln("  Tenant Specific Core: <comment>$tenantSpecificCore</comment>");
        $output->writeln('');

    }//end showTenantInfo()

    /**
     * Test SOLR setup
     *
     * @param OutputInterface $output Output interface
     *
     * @return void
     */
    private function testSetup(OutputInterface $output): void
    {
        $output->writeln('<info>🔧 Testing SOLR Setup</info>');

        try {
            $solrSettings = $this->settingsService->getSolrSettings();

            if ($solrSettings['enabled'] === false) {
                $output->writeln('<error>❌ SOLR is disabled in settings</error>');
                return;
            }

            $output->writeln('  SOLR Configuration:');
            $output->writeln("    Host: <comment>{$solrSettings['host']}</comment>");
            $output->writeln("    Port: <comment>{$solrSettings['port']}</comment>");
            $output->writeln("    Path: <comment>{$solrSettings['path']}</comment>");
            $output->writeln("    Core: <comment>{$solrSettings['core']}</comment>");
            $output->writeln("    Scheme: <comment>{$solrSettings['scheme']}</comment>");

            // Create IndexService from settings.
            // NOTE: This requires proper dependency injection - IndexService needs FileHandler, ObjectHandler, SchemaHandler, SearchBackendInterface
            // For now, this will fail at runtime and needs to be fixed with proper DI.
            // TODO: Inject these dependencies via constructor instead of using getContainer().
            // Command classes don't have getContainer() method - this needs to be fixed.
            $output->writeln('<error>IndexService creation requires dependency injection - not yet implemented</error>');
            return;
            // Test setup.
            $setup  = new SetupHandler($solrService, $this->logger);
            $result = $setup->setupSolr();

            if ($result === true) {
                $output->writeln('<info>✅ SOLR setup completed successfully</info>');
            } else {
                $output->writeln('<error>❌ SOLR setup failed</error>');
            }
        } catch (\Exception $e) {
            $output->writeln("<error>❌ Setup failed: {$e->getMessage()}</error>");
        }//end try

        $output->writeln('');

    }//end testSetup()

    /**
     * Test SOLR connection
     *
     * @param OutputInterface $output Output interface
     *
     * @return void
     */
    private function testConnection(OutputInterface $output): void
    {
        $output->writeln('<info>🔗 Testing SOLR Connection</info>');

        try {
            // Get SOLR service via direct DI injection.
            $container   = \OC::$server->getRegisteredAppContainer('openregister');
            $solrService = $container->get(IndexService::class);

            if ($solrService === null) {
                $output->writeln('<error>❌ Failed to create SOLR service</error>');
                return;
            }

            if ($solrService->isAvailable() === false) {
                $output->writeln('<error>❌ SOLR service is not available</error>');
                return;
            }

            $connectionResult = $solrService->testConnection();

            if ($connectionResult['success'] === true) {
                $output->writeln('<info>✅ SOLR connection successful (Guzzle HTTP)</info>');
                $output->writeln("  Response time: <comment>{$connectionResult['details']['response_time_ms']}ms</comment>");
                $output->writeln("  SOLR version: <comment>{$connectionResult['details']['solr_version']}</comment>");
                $output->writeln("  Tenant ID: <comment>{$connectionResult['details']['tenant_id']}</comment>");
                $output->writeln("  Mode: <comment>{$connectionResult['details']['mode']}</comment>");

                // Test tenant collection creation.
                $output->writeln('');
                $output->writeln('<info>🏗️ Testing tenant collection creation...</info>');
                if ($solrService->ensureTenantCollection() === true) {
                    $output->writeln('<info>✅ Tenant collection ready</info>');
                    $docCount = $solrService->getDocumentCount();
                    $output->writeln("  Document count: <comment>$docCount</comment>");
                } else {
                    $output->writeln('<error>❌ Failed to create tenant collection</error>');
                }
            } else {
                $output->writeln("<error>❌ Connection failed: {$connectionResult['message']}</error>");
            }
        } catch (\Exception $e) {
            $output->writeln("<error>❌ Connection test failed: {$e->getMessage()}</error>");
        }//end try

        $output->writeln('');

    }//end testConnection()

    /**
     * Check existing cores/collections
     *
     * @param OutputInterface $output Output interface
     *
     * @return void
     */
    private function checkCores(OutputInterface $output): void
    {
        $output->writeln('<info>🗄️  Checking SOLR Cores/Collections</info>');

        try {
            $solrSettings = $this->settingsService->getSolrSettings();

            if ($solrSettings['enabled'] === false) {
                $output->writeln('<error>❌ SOLR is disabled</error>');
                return;
            }

            // Test direct SOLR admin API calls.
            $this->testSolrAdminAPI(output: $output, solrSettings: $solrSettings);
        } catch (\Exception $e) {
            $output->writeln("<error>❌ Core check failed: {$e->getMessage()}</error>");
        }

        $output->writeln('');

    }//end checkCores()

    /**
     * Test SOLR Admin API directly
     *
     * @param OutputInterface $output       Output interface
     * @param array           $solrSettings SOLR configuration
     *
     * @return void
     */
    private function testSolrAdminAPI(OutputInterface $output, array $solrSettings): void
    {
        // Test cores listing (standalone SOLR).
        $coresUrl = sprintf(
            '%s://%s:%d%s/admin/cores?action=STATUS&wt=json',
                $solrSettings['scheme'],
            $solrSettings['host'],
            $solrSettings['port'],
            $solrSettings['path']
                );

        $output->writeln("  Testing cores API: <comment>$coresUrl</comment>");

        $coresResponse = file_get_contents($coresUrl);
        if ($coresResponse !== false && $coresResponse !== '' && $coresResponse !== null) {
            $coresData = json_decode($coresResponse, true);
            if (($coresData['status'] ?? null) !== null) {
                $coreCount = count($coresData['status']);
                $output->writeln("  <info>✅ Found $coreCount cores (standalone mode)</info>");
                foreach ($coresData['status'] as $coreName => $coreInfo) {
                    $docCount = $coreInfo['index']['numDocs'] ?? 'unknown';
                    $output->writeln("    - <comment>$coreName</comment> ($docCount documents)");
                }
            }
        } else {
            $output->writeln('  <comment>❓ Cores API not available (might be SolrCloud)</comment>');
        }

        // Test collections listing (SolrCloud).
        $collectionsUrl = sprintf(
            '%s://%s:%d%s/admin/collections?action=CLUSTERSTATUS&wt=json',
                $solrSettings['scheme'],
            $solrSettings['host'],
            $solrSettings['port'],
            $solrSettings['path']
                );

        $output->writeln("  Testing collections API: <comment>$collectionsUrl</comment>");

        $collectionsResponse = file_get_contents($collectionsUrl);
        if ($collectionsResponse !== false && $collectionsResponse !== '' && $collectionsResponse !== null) {
            $collectionsData = json_decode($collectionsResponse, true);
            if (($collectionsData['cluster']['collections'] ?? null) !== null) {
                $collectionCount = count($collectionsData['cluster']['collections']);
                $output->writeln("  <info>✅ Found $collectionCount collections (SolrCloud mode)</info>");
                // Iterate over collections directly to get string keys.
                // Collection names in Solr are always strings.
                foreach ($collectionsData['cluster']['collections'] as $collectionName => $_collectionData) {
                    // $collectionName is guaranteed to be a string when iterating over array.
                    $output->writeln("    - <comment>".$collectionName."</comment>");
                }
            }
        } else {
            $output->writeln('  <comment>❓ Collections API not available (might be standalone)</comment>');
        }

        // Test configSets listing.
        $configSetsUrl = sprintf(
            '%s://%s:%d%s/admin/configs?action=LIST&wt=json',
                $solrSettings['scheme'],
            $solrSettings['host'],
            $solrSettings['port'],
            $solrSettings['path']
                );

        $output->writeln("  Testing configSets API: <comment>$configSetsUrl</comment>");

        $configSetsResponse = file_get_contents($configSetsUrl);
        if ($configSetsResponse !== false && $configSetsResponse !== '' && $configSetsResponse !== null) {
            $configSetsData = json_decode($configSetsResponse, true);
            if (($configSetsData['configSets'] ?? null) !== null) {
                $configSetCount = count($configSetsData['configSets']);
                $output->writeln("  <info>✅ Found $configSetCount configSets</info>");
                foreach ($configSetsData['configSets'] as $configSetName) {
                    $output->writeln("    - <comment>$configSetName</comment>");
                }
            }
        } else {
            $output->writeln('  <comment>❓ ConfigSets API not available</comment>');
        }

    }//end testSolrAdminAPI()
}//end class
