<?php
/**
 * OpenRegister Preview Handler
 *
 * This file contains the handler class for previewing configuration changes
 * in the OpenRegister application.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Class PreviewHandler
 *
 * Handles previewing configuration changes before import.
 * Provides methods to compare current vs. proposed configurations
 * and preview the impact of importing configurations.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */
class PreviewHandler
{

    /**
     * Register mapper for database operations.
     *
     * @var RegisterMapper The register mapper instance.
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Schema mapper for database operations.
     *
     * @var SchemaMapper The schema mapper instance.
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Logger for logging operations.
     *
     * @var LoggerInterface The logger interface.
     */
    private readonly LoggerInterface $logger;

    /**
     * Configuration service reference for delegation.
     *
     * Set via setConfigurationService() to avoid circular dependency.
     *
     * @var ConfigurationService|null The configuration service.
     */
    private ?ConfigurationService $configurationService = null;


    /**
     * Constructor for PreviewHandler.
     *
     * @param RegisterMapper  $registerMapper The register mapper.
     * @param SchemaMapper    $schemaMapper   The schema mapper.
     * @param LoggerInterface $logger         The logger interface.
     */
    public function __construct(
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        LoggerInterface $logger
    ) {
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;
        $this->logger         = $logger;

    }//end __construct()


    /**
     * Set the ConfigurationService dependency.
     *
     * This method allows setting the ConfigurationService after construction
     * to avoid circular dependency issues.
     *
     * @param ConfigurationService $configurationService The configuration service instance.
     *
     * @return void
     */
    public function setConfigurationService(ConfigurationService $configurationService): void
    {
        $this->configurationService = $configurationService;

    }//end setConfigurationService()


    /**
     * Preview configuration changes.
     *
     * This method fetches remote configuration and previews what would change
     * if it were imported. It shows additions, updates, and deletions for
     * registers, schemas, and objects.
     *
     * @param Configuration $configuration The configuration to preview.
     *
     * @return array|JSONResponse Array of preview information or error response.
     *
     * @throws Exception If configuration service not set.
     *
     * @phpstan-return array{
     *     registers: array,
     *     schemas: array,
     *     objects: array,
     *     metadata: array
     * }|JSONResponse
     */
    public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse
    {
        if ($this->configurationService === null) {
            throw new Exception('ConfigurationService must be set before calling previewConfigurationChanges');
        }

        // Fetch the remote configuration.
        $remoteData = $this->configurationService->fetchRemoteConfiguration($configuration);

        if ($remoteData instanceof JSONResponse) {
            return $remoteData;
        }

        // Initialize preview result.
        $preview = [
            'registers'        => [],
            'schemas'          => [],
            'objects'          => [],
            'endpoints'        => [],
            'sources'          => [],
            'mappings'         => [],
            'jobs'             => [],
            'synchronizations' => [],
            'rules'            => [],
        ];

        // Preview registers.
        if (($remoteData['components']['registers'] ?? null) !== null && is_array($remoteData['components']['registers']) === true) {
            foreach ($remoteData['components']['registers'] as $slug => $registerData) {
                $preview['registers'][] = $this->previewRegisterChange(slug: $slug, registerData: $registerData);
            }
        }

        // Preview schemas.
        if (($remoteData['components']['schemas'] ?? null) !== null && is_array($remoteData['components']['schemas']) === true) {
            foreach ($remoteData['components']['schemas'] as $slug => $schemaData) {
                $preview['schemas'][] = $this->previewSchemaChange(slug: $slug, schemaData: $schemaData);
            }
        }

        // Preview objects.
        if (($remoteData['components']['objects'] ?? null) !== null && is_array($remoteData['components']['objects']) === true) {
            // Build register and schema slug to ID maps.
            $registerSlugToId = [];
            $schemaSlugToId   = [];

            // Get existing registers and schemas to build maps.
            $allRegisters = $this->registerMapper->findAll();
            foreach ($allRegisters as $register) {
                $registerSlugToId[strtolower($register->getSlug() ?? '')] = $register->getId();
            }

            $allSchemas = $this->schemaMapper->findAll();
            foreach ($allSchemas as $schema) {
                $schemaSlugToId[strtolower($schema->getSlug() ?? '')] = $schema->getId();
            }

            foreach ($remoteData['components']['objects'] as $objectData) {
                $preview['objects'][] = $this->previewObjectChange(
                    objectData: $objectData,
                    registerSlugToId: $registerSlugToId,
                    schemaSlugToId: $schemaSlugToId
                );
            }
        }//end if

        // Add metadata about the preview.
        $preview['metadata'] = [
            'configurationId'    => $configuration->getId(),
            'configurationTitle' => $configuration->getTitle(),
            'sourceUrl'          => $configuration->getSourceUrl(),
            'remoteVersion'      => $remoteData['version'] ?? $remoteData['info']['version'] ?? null,
            'localVersion'       => $configuration->getLocalVersion(),
            'previewedAt'        => (new DateTime())->format('c'),
            'totalChanges'       => (
                count($preview['registers']) + count($preview['schemas']) + count($preview['objects'])
            ),
        ];

        return $preview;

    }//end previewConfigurationChanges()


    /**
     * Preview changes for a single register.
     *
     * This method compares a register from remote configuration with the existing
     * local register and determines if it would be created, updated, or skipped.
     *
     * @param string $slug         The register slug.
     * @param array  $registerData The register data from remote configuration.
     *
     * @return array Preview information for this register.
     *
     * @phpstan-return array{
     *     type: string,
     *     action: string,
     *     slug: string,
     *     title: string,
     *     current: array|null,
     *     proposed: array,
     *     changes: array
     * }
     */
    private function previewRegisterChange(string $slug, array $registerData): array
    {
        $slug = strtolower($slug);

        // Try to find existing register.
        $existingRegister = null;
        try {
            $existingRegister = $this->registerMapper->find($slug);
        } catch (Exception $e) {
            // Register doesn't exist.
        }

        // Determine action.
        if ($existingRegister === null) {
            $action = 'create';
        } else {
            $action = 'update';
        }

        $preview = [
            'type'     => 'register',
            'action'   => $action,
            'slug'     => $slug,
            'title'    => $registerData['title'] ?? $slug,
            'current'  => null,
            'proposed' => $registerData,
            'changes'  => [],
        ];

        // If register exists, compare versions and build change list.
        if ($existingRegister !== null) {
            $currentData        = $existingRegister->jsonSerialize();
            $preview['current'] = $currentData;

            // Check if version allows update.
            $currentVersion  = $existingRegister->getVersion() ?? '0.0.0';
            $proposedVersion = $registerData['version'] ?? '0.0.0';

            if (version_compare($proposedVersion, $currentVersion, '<=') === true) {
                $preview['action'] = 'skip';
                $preview['reason'] = "Remote version ({$proposedVersion}) is not newer than current version ({$currentVersion})";
            } else {
                // Build list of changed fields.
                $preview['changes'] = $this->compareArrays(current: $currentData, proposed: $registerData);
            }
        }

        return $preview;

    }//end previewRegisterChange()


    /**
     * Preview changes for a single schema.
     *
     * This method compares a schema from remote configuration with the existing
     * local schema and determines if it would be created, updated, or skipped.
     *
     * @param string $slug       The schema slug.
     * @param array  $schemaData The schema data from remote configuration.
     *
     * @return array Preview information for this schema.
     *
     * @phpstan-return array{
     *     type: string,
     *     action: string,
     *     slug: string,
     *     title: string,
     *     current: array|null,
     *     proposed: array,
     *     changes: array
     * }
     */
    private function previewSchemaChange(string $slug, array $schemaData): array
    {
        $slug = strtolower($slug);

        // Try to find existing schema.
        $existingSchema = null;
        try {
            $existingSchema = $this->schemaMapper->find($slug);
        } catch (Exception $e) {
            // Schema doesn't exist.
        }

        // Determine action.
        if ($existingSchema === null) {
            $action = 'create';
        } else {
            $action = 'update';
        }

        $preview = [
            'type'     => 'schema',
            'action'   => $action,
            'slug'     => $slug,
            'title'    => $schemaData['title'] ?? $slug,
            'current'  => null,
            'proposed' => $schemaData,
            'changes'  => [],
        ];

        // If schema exists, compare versions and build change list.
        if ($existingSchema !== null) {
            $currentData        = $existingSchema->jsonSerialize();
            $preview['current'] = $currentData;

            // Check if version allows update.
            $currentVersion  = $existingSchema->getVersion() ?? '0.0.0';
            $proposedVersion = $schemaData['version'] ?? '0.0.0';

            if (version_compare($proposedVersion, $currentVersion, '<=') === true) {
                $preview['action'] = 'skip';
                $preview['reason'] = "Remote version ({$proposedVersion}) is not newer than current version ({$currentVersion})";
            } else {
                // Build list of changed fields.
                $preview['changes'] = $this->compareArrays(current: $currentData, proposed: $schemaData);
            }
        }

        return $preview;

    }//end previewSchemaChange()


    /**
     * Placeholder method - will be populated with extracted method.
     *
     * @param array $objectData       The object data.
     * @param array $registerSlugToId Register slug to ID map.
     * @param array $schemaSlugToId   Schema slug to ID map.
     *
     * @return array Preview information.
     */
    private function previewObjectChange(array $objectData, array $registerSlugToId, array $schemaSlugToId): array
    {
        // Method body will be extracted from ConfigurationService.
        return [];

    }//end previewObjectChange()


    /**
     * Placeholder method - will be populated with extracted method.
     *
     * @param array  $current  Current array.
     * @param array  $proposed Proposed array.
     * @param string $prefix   Prefix for nested keys.
     *
     * @return array Array of changes.
     */
    private function compareArrays(array $current, array $proposed, string $prefix=''): array
    {
        // Method body will be extracted from ConfigurationService.
        return [];

    }//end compareArrays()


    /**
     * Placeholder method - will be populated with extracted method.
     *
     * @param Configuration $configuration The configuration.
     * @param array         $selection     Selection criteria.
     *
     * @return array Import results.
     *
     * @throws Exception If import fails.
     */
    public function importConfigurationWithSelection(Configuration $configuration, array $selection): array
    {
        // Method body will be extracted from ConfigurationService.
        return [];

    }//end importConfigurationWithSelection()


}//end class
