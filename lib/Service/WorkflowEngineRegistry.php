<?php

/**
 * OpenRegister WorkflowEngineRegistry
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-84
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\WorkflowEngine;
use OCA\OpenRegister\Db\WorkflowEngineMapper;
use OCA\OpenRegister\WorkflowEngine\N8nAdapter;
use OCA\OpenRegister\WorkflowEngine\WindmillAdapter;
use OCA\OpenRegister\WorkflowEngine\WorkflowEngineInterface;
use OCP\App\IAppManager;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Registry for managing workflow engines and resolving adapters.
 */
class WorkflowEngineRegistry
{
    /**
     * Constructor for WorkflowEngineRegistry.
     *
     * @param WorkflowEngineMapper $mapper          Engine mapper
     * @param N8nAdapter           $n8nAdapter      n8n adapter
     * @param WindmillAdapter      $windmillAdapter Windmill adapter
     * @param ICrypto              $crypto          Crypto service for credential encryption
     * @param IAppManager          $appManager      App manager for auto-discovery
     * @param LoggerInterface      $logger          Logger
     */
    public function __construct(
        private readonly WorkflowEngineMapper $mapper,
        private readonly N8nAdapter $n8nAdapter,
        private readonly WindmillAdapter $windmillAdapter,
        private readonly ICrypto $crypto,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve the correct adapter for an engine configuration.
     *
     * @param WorkflowEngine $engine Engine configuration
     *
     * @return WorkflowEngineInterface Configured adapter
     *
     * @throws InvalidArgumentException If engine type is unsupported
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-84
     */
    public function resolveAdapter(WorkflowEngine $engine): WorkflowEngineInterface
    {
        $authConfig = $this->decryptAuthConfig(engine: $engine);

        $adapter = match ($engine->getEngineType()) {
            'n8n'      => $this->n8nAdapter,
            'windmill' => $this->windmillAdapter,
            default    => throw new InvalidArgumentException(
                "Unsupported engine type: '{$engine->getEngineType()}'"
            ),
        };

        $adapter->configure(baseUrl: $engine->getBaseUrl(), authConfig: $authConfig);

        return $adapter;
    }//end resolveAdapter()

    /**
     * Resolve an adapter by engine ID.
     *
     * @param int $engineId Engine ID
     *
     * @return WorkflowEngineInterface
     */
    public function resolveAdapterById(int $engineId): WorkflowEngineInterface
    {
        $engine = $this->mapper->find($engineId);

        return $this->resolveAdapter(engine: $engine);
    }//end resolveAdapterById()

    /**
     * Get all registered engines.
     *
     * @return array<int, WorkflowEngine>
     */
    public function getEngines(): array
    {
        return $this->mapper->findAll();
    }//end getEngines()

    /**
     * Get engines by type.
     *
     * @param string $engineType Engine type
     *
     * @return array<int, WorkflowEngine>
     */
    public function getEnginesByType(string $engineType): array
    {
        return $this->mapper->findByType($engineType);
    }//end getEnginesByType()

    /**
     * Get a single engine by ID.
     *
     * @param int $id Engine ID
     *
     * @return WorkflowEngine
     */
    public function getEngine(int $id): WorkflowEngine
    {
        return $this->mapper->find($id);
    }//end getEngine()

    /**
     * Create a new engine with encrypted credentials.
     *
     * @param array<string, mixed> $data Engine configuration data
     *
     * @return WorkflowEngine
     */
    public function createEngine(array $data): WorkflowEngine
    {
        if (isset($data['authConfig']) === true && is_array($data['authConfig']) === true) {
            $data['authConfig'] = $this->crypto->encrypt(json_encode($data['authConfig']));
        }

        return $this->mapper->createFromArray($data);
    }//end createEngine()

    /**
     * Update an engine with encrypted credentials.
     *
     * @param int                  $id   Engine ID
     * @param array<string, mixed> $data Updated data
     *
     * @return WorkflowEngine
     */
    public function updateEngine(int $id, array $data): WorkflowEngine
    {
        if (isset($data['authConfig']) === true && is_array($data['authConfig']) === true) {
            $data['authConfig'] = $this->crypto->encrypt(json_encode($data['authConfig']));
        }

        return $this->mapper->updateFromArray($id, $data);
    }//end updateEngine()

    /**
     * Delete an engine.
     *
     * @param int $id Engine ID
     *
     * @return WorkflowEngine The deleted engine
     */
    public function deleteEngine(int $id): WorkflowEngine
    {
        $engine = $this->mapper->find($id);
        $this->mapper->delete($engine);

        return $engine;
    }//end deleteEngine()

    /**
     * Run a health check on an engine and update its status.
     *
     * @param int $id Engine ID
     *
     * @return array{healthy: bool, responseTime: int}
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-85
     */
    public function healthCheck(int $id): array
    {
        $engine  = $this->mapper->find($id);
        $adapter = $this->resolveAdapter(engine: $engine);

        $start   = hrtime(true);
        $healthy = $adapter->healthCheck();
        $elapsed = (int) ((hrtime(true) - $start) / 1_000_000);

        $engine->setHealthStatus($healthy);
        $engine->setLastHealthCheck(new DateTime());
        $engine->setUpdated(new DateTime());
        $this->mapper->update($engine);

        return [
            'healthy'      => $healthy,
            'responseTime' => $elapsed,
        ];
    }//end healthCheck()

    /**
     * Discover available workflow engine ExApps.
     *
     * @return array<int, array{engineType: string, suggestedBaseUrl: string, installed: bool}>
     */
    public function discoverEngines(): array
    {
        $available = [];

        // Requires app_api to be installed for ExApp discovery.
        if ($this->appManager->isEnabledForUser('app_api') === false) {
            return [];
        }

        $knownEngines = [
            'n8n'      => ['appId' => 'n8n', 'defaultPort' => 5678],
            'windmill' => ['appId' => 'windmill', 'defaultPort' => 8000],
        ];

        foreach ($knownEngines as $type => $config) {
            $isInstalled = $this->appManager->isEnabledForUser($config['appId']);

            if ($isInstalled === true) {
                $available[] = [
                    'engineType'       => $type,
                    'suggestedBaseUrl' => 'http://localhost:'.$config['defaultPort'],
                    'installed'        => true,
                ];
            }
        }

        return $available;
    }//end discoverEngines()

    /**
     * Decrypt the auth config from an engine entity.
     *
     * @param WorkflowEngine $engine Engine entity
     *
     * @return array<string, mixed> Decrypted auth config
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-84
     */
    private function decryptAuthConfig(WorkflowEngine $engine): array
    {
        $encrypted = $engine->getAuthConfig();

        if ($encrypted === null || $encrypted === '') {
            return ['authType' => $engine->getAuthType() ?? 'none'];
        }

        try {
            $decrypted = $this->crypto->decrypt($encrypted);

            $config = json_decode($decrypted, true) ?? [];
            $config['authType'] = $engine->getAuthType() ?? 'none';

            return $config;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[WorkflowEngineRegistry] Failed to decrypt auth config',
                context: ['engineId' => $engine->getId(), 'error' => $e->getMessage()]
            );

            return ['authType' => $engine->getAuthType() ?? 'none'];
        }
    }//end decryptAuthConfig()
}//end class
