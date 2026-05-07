<?php

/**
 * OpenRegister DeepLinkRegistryService
 *
 * Registry service for deep link registrations from consuming apps.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-19
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-25
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-26
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-27
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registry service for deep link registrations from consuming apps.
 *
 * Consuming apps (Procest, Pipelinq, etc.) register URL templates per
 * (register, schema) pair. The search provider uses this to link results
 * to the owning app instead of OpenRegister's generic object view.
 *
 * Registrations are in-memory only (static array) and populated fresh
 * on each request via app boot cycles.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class DeepLinkRegistryService
{

    /**
     * In-memory registry keyed by "registerSlug::schemaSlug".
     *
     * @var array<string, DeepLinkRegistration>
     */
    private static array $registrations = [];

    /**
     * Cached slug→ID map for registers. Null means not yet loaded.
     *
     * @var array<string, int>|null
     */
    private static ?array $registerSlugMap = null;

    /**
     * Cached slug→ID map for schemas. Null means not yet loaded.
     *
     * @var array<string, int>|null
     */
    private static ?array $schemaSlugMap = null;

    /**
     * Cached reverse map: ID→slug for registers.
     *
     * @var array<int, string>|null
     */
    private static ?array $registerIdMap = null;

    /**
     * Cached reverse map: ID→slug for schemas.
     *
     * @var array<int, string>|null
     */
    private static ?array $schemaIdMap = null;

    /**
     * Container for lazy resolution of mappers (avoids circular DI).
     *
     * @var ContainerInterface
     */
    private readonly ContainerInterface $container;

    /**
     * Logger for debugging registry operations.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor for DeepLinkRegistryService.
     *
     * Uses ContainerInterface instead of direct mapper injection to avoid
     * circular DI resolution during app bootstrap (RegisterMapper ↔ MagicMapper).
     *
     * @param ContainerInterface $container The DI container for lazy mapper resolution
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        ContainerInterface $container,
        LoggerInterface $logger
    ) {
        $this->container = $container;
        $this->logger    = $logger;
    }//end __construct()

    /**
     * Register a deep link pattern for a (register, schema) combination.
     *
     * @param string $appId        The consuming app ID (e.g., "procest")
     * @param string $registerSlug The register slug
     * @param string $schemaSlug   The schema slug
     * @param string $urlTemplate  URL template with placeholders (e.g., "/apps/procest/#/cases/{uuid}")
     * @param string $icon         Optional icon identifier (defaults to "icon-{appId}")
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-19
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-25
     */
    public function register(
        string $appId,
        string $registerSlug,
        string $schemaSlug,
        string $urlTemplate,
        string $icon=''
    ): void {
        $key = $registerSlug.'::'.$schemaSlug;

        if (isset(self::$registrations[$key]) === true) {
            $this->logger->debug(
                '[DeepLinkRegistry] Ignoring duplicate registration for {key} from {appId} (already claimed by {existing})',
                [
                    'key'      => $key,
                    'appId'    => $appId,
                    'existing' => self::$registrations[$key]->appId,
                ]
            );
            return;
        }

        $effectiveIcon = 'icon-'.$appId;
        if ($icon !== '') {
            $effectiveIcon = $icon;
        }

        self::$registrations[$key] = new DeepLinkRegistration(
            appId: $appId,
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            urlTemplate: $urlTemplate,
            icon: $effectiveIcon,
        );

        $this->logger->debug(
            '[DeepLinkRegistry] Registered deep link for {key} → {appId} ({template})',
            [
                'key'      => $key,
                'appId'    => $appId,
                'template' => $urlTemplate,
            ]
        );
    }//end register()

    /**
     * Resolve a deep link registration by register and schema integer IDs.
     *
     * @param int $registerId The register database ID
     * @param int $schemaId   The schema database ID
     *
     * @return DeepLinkRegistration|null The registration, or null if none exists
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-26
     */
    public function resolve(int $registerId, int $schemaId): ?DeepLinkRegistration
    {
        if (empty(self::$registrations) === true) {
            return null;
        }

        $this->ensureIdMaps();

        $registerSlug = self::$registerIdMap[$registerId] ?? null;
        $schemaSlug   = self::$schemaIdMap[$schemaId] ?? null;

        if ($registerSlug === null || $schemaSlug === null) {
            return null;
        }

        $key = $registerSlug.'::'.$schemaSlug;
        return self::$registrations[$key] ?? null;
    }//end resolve()

    /**
     * Resolve a URL for a search result, falling back to null if no registration exists.
     *
     * @param int   $registerId     The register database ID
     * @param int   $schemaId       The schema database ID
     * @param array $objectData     The object data from search results
     * @param array $contactContext Optional contact context for placeholder resolution
     *                              Supports: contactId, contactEmail, contactName
     *
     * @return string|null The resolved URL, or null to use default
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-27
     */
    public function resolveUrl(
        int $registerId,
        int $schemaId,
        array $objectData,
        array $contactContext=[]
    ): ?string {
        $registration = $this->resolve(registerId: $registerId, schemaId: $schemaId);
        if ($registration === null) {
            return null;
        }

        return $registration->resolveUrl(
            objectData: $objectData,
            contactContext: $contactContext
        );
    }//end resolveUrl()

    /**
     * Get the icon for a registered deep link, or null if no registration.
     *
     * @param int $registerId The register database ID
     * @param int $schemaId   The schema database ID
     *
     * @return string|null The icon identifier, or null
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-18
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-26
     */
    public function resolveIcon(int $registerId, int $schemaId): ?string
    {
        $registration = $this->resolve(registerId: $registerId, schemaId: $schemaId);
        return $registration?->icon;
    }//end resolveIcon()

    /**
     * Lazily build the ID↔slug maps from database.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-19
     */
    private function ensureIdMaps(): void
    {
        if (self::$registerIdMap !== null) {
            return;
        }

        self::$registerIdMap = [];
        self::$schemaIdMap   = [];

        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $registers      = $registerMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
            foreach ($registers as $register) {
                $slug = $register->getSlug();
                $id   = $register->getId();
                if ($slug !== null && $slug !== '') {
                    self::$registerIdMap[$id] = $slug;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                '[DeepLinkRegistry] Failed to load registers for slug resolution: {error}',
                ['error' => $e->getMessage()]
            );
        }

        try {
            $schemaMapper = $this->container->get(SchemaMapper::class);
            $schemas      = $schemaMapper->findAll(
                _rbac: false,
                _multitenancy: false
            );
            foreach ($schemas as $schema) {
                $slug = $schema->getSlug();
                $id   = $schema->getId();
                if ($slug !== null && $slug !== '') {
                    self::$schemaIdMap[$id] = $slug;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                '[DeepLinkRegistry] Failed to load schemas for slug resolution: {error}',
                ['error' => $e->getMessage()]
            );
        }
    }//end ensureIdMaps()

    /**
     * Check whether any registrations exist.
     *
     * @return bool True if at least one deep link is registered
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-19
     */
    public function hasRegistrations(): bool
    {
        return empty(self::$registrations) === false;
    }//end hasRegistrations()

    /**
     * Reset all registrations. Used for testing only.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-19
     */
    public static function reset(): void
    {
        self::$registrations   = [];
        self::$registerSlugMap = null;
        self::$schemaSlugMap   = null;
        self::$registerIdMap   = null;
        self::$schemaIdMap     = null;
    }//end reset()
}//end class
