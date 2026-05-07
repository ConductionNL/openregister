<?php

/**
 * OpenRegister Object Reference Provider
 *
 * Provides Smart Picker integration for OpenRegister objects. Allows users
 * to search for and insert rich references to register objects in Mail,
 * Text, Talk, Collectives, and any Nextcloud app supporting the Smart Picker.
 *
 * @category Reference
 * @package  OCA\OpenRegister\Reference
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

namespace OCA\OpenRegister\Reference;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Reference provider for OpenRegister objects.
 *
 * Resolves OpenRegister object URLs into rich preview cards for the Smart Picker.
 * Supports hash-routed UI URLs, API object URLs, and direct object routes.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider
{

    /**
     * Internal fields to exclude from preview properties.
     *
     * @var string[]
     */
    private const INTERNAL_FIELDS = [
        '@self',
        '_translationMeta',
        '_schema',
        '_register',
        '_id',
        '_uuid',
        '_created',
        '_updated',
        '_owner',
        '_organisation',
        'id',
        'uuid',
    ];

    /**
     * Maximum number of preview properties to display.
     *
     * @var int
     */
    private const MAX_PREVIEW_PROPERTIES = 4;

    /**
     * Maximum length for description text.
     *
     * @var int
     */
    private const MAX_DESCRIPTION_LENGTH = 200;

    /**
     * The URL generator service
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

    /**
     * The localization service
     *
     * @var IL10N
     */
    private readonly IL10N $l10n;

    /**
     * The object service for fetching objects
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Deep link registry for consuming-app URL resolution
     *
     * @var DeepLinkRegistryService
     */
    private readonly DeepLinkRegistryService $deepLinkRegistry;

    /**
     * Schema mapper for resolving schema names
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Register mapper for resolving register names
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Logger for debugging
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * The current user ID (nullable for public/anonymous access)
     *
     * @var string|null
     */
    private readonly ?string $userId;

    /**
     * Constructor for ObjectReferenceProvider.
     *
     * @param IURLGenerator           $urlGenerator     The URL generator
     * @param IL10N                   $l10n             The localization service
     * @param ObjectService           $objectService    The object service
     * @param DeepLinkRegistryService $deepLinkRegistry Deep link registry
     * @param SchemaMapper            $schemaMapper     Schema mapper
     * @param RegisterMapper          $registerMapper   Register mapper
     * @param LoggerInterface         $logger           Logger
     * @param string|null             $userId           Current user ID
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function __construct(
        IURLGenerator $urlGenerator,
        IL10N $l10n,
        ObjectService $objectService,
        DeepLinkRegistryService $deepLinkRegistry,
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        LoggerInterface $logger,
        ?string $userId
    ) {
        $this->urlGenerator  = $urlGenerator;
        $this->l10n          = $l10n;
        $this->objectService = $objectService;
        $this->deepLinkRegistry = $deepLinkRegistry;
        $this->schemaMapper     = $schemaMapper;
        $this->registerMapper   = $registerMapper;
        $this->logger           = $logger;
        $this->userId           = $userId;
    }//end __construct()

    /**
     * Returns the unique identifier for this reference provider.
     *
     * @return string Provider ID
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getId(): string
    {
        return 'openregister-ref-objects';
    }//end getId()

    /**
     * Returns the display title for the Smart Picker entry.
     *
     * @return string Translated title
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Register Objects');
    }//end getTitle()

    /**
     * Returns the order/priority for Smart Picker sorting.
     *
     * @return int Order value (lower = higher priority)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getOrder(): int
    {
        return 10;
    }//end getOrder()

    /**
     * Returns the icon URL for the Smart Picker entry.
     *
     * @return string URL to the app icon
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getIconUrl(): string
    {
        return $this->urlGenerator->imagePath('openregister', 'app-dark.svg');
    }//end getIconUrl()

    /**
     * Returns the supported search provider IDs for the Smart Picker.
     *
     * @return string[] List of search provider IDs
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getSupportedSearchProviderIds(): array
    {
        return ['openregister_objects'];
    }//end getSupportedSearchProviderIds()

    /**
     * Check if a URL matches an OpenRegister object reference.
     *
     * Supports three URL patterns:
     * 1. Hash-routed UI: /apps/openregister/#/registers/{id}/schemas/{id}/objects/{uuid}
     * 2. API endpoint:   /apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}
     * 3. Direct route:   /apps/openregister/objects/{registerId}/{schemaId}/{uuid}
     *
     * All patterns support optional /index.php/ prefix.
     *
     * @param string $referenceText The URL to check
     *
     * @return bool True if the URL matches an OpenRegister object reference
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function matchReference(string $referenceText): bool
    {
        return $this->parseReference(referenceText: $referenceText) !== null;
    }//end matchReference()

    /**
     * Resolve a matched URL into a rich reference object.
     *
     * Fetches the object data, schema/register names, and deep link URL to
     * build a rich preview card for the Smart Picker widget.
     *
     * @param string $referenceText The matched URL
     *
     * @return IReference|null The reference object or null on failure
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function resolveReference(string $referenceText): ?IReference
    {
        $parsed = $this->parseReference(referenceText: $referenceText);
        if ($parsed === null) {
            return null;
        }

        $registerId = $parsed['registerId'];
        $schemaId   = $parsed['schemaId'];
        $uuid       = $parsed['uuid'];

        try {
            // Fetch the object using ObjectService.
            $object = $this->objectService->find(
                id: $uuid,
                register: $registerId,
                schema: $schemaId
            );

            if ($object === null) {
                return null;
            }

            $objectData = $object->jsonSerialize();
            $selfData   = $objectData['@self'] ?? [];

            // Extract title.
            $title = $this->extractTitle(objectData: $objectData, selfData: $selfData);

            // Extract description.
            $description = $this->extractDescription(objectData: $objectData);

            // Resolve schema and register names.
            $schemaTitle   = $this->resolveSchemaName(schemaId: $schemaId);
            $registerTitle = $this->resolveRegisterName(registerId: $registerId);

            // Resolve deep link URL.
            $flatData = array_merge(
                is_array($selfData) === true ? $selfData : [],
                ['uuid' => $uuid, 'register' => $registerId, 'schema' => $schemaId]
            );

            $objectUrl = $this->deepLinkRegistry->resolveUrl(
                registerId: $registerId,
                schemaId: $schemaId,
                objectData: $flatData
            );

            if ($objectUrl === null) {
                $objectUrl = $this->urlGenerator->linkToRoute(
                    'openregister.objects.show',
                    ['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid]
                );
            }

            $objectUrl = $this->urlGenerator->getAbsoluteURL($objectUrl);

            // Resolve icon.
            $iconUrl = $this->deepLinkRegistry->resolveIcon(
                registerId: $registerId,
                schemaId: $schemaId
            );

            if ($iconUrl === null) {
                $iconUrl = $this->urlGenerator->imagePath('openregister', 'app-dark.svg');
            }

            // Extract preview properties.
            $properties = $this->extractPreviewProperties(objectData: $objectData);

            // Get updated timestamp.
            $updated = $selfData['updated'] ?? $objectData['updated'] ?? '';

            // Build rich data.
            $richData = [
                'id'          => $uuid,
                'title'       => $title,
                'description' => $description,
                'schema'      => ['id' => $schemaId, 'title' => $schemaTitle],
                'register'    => ['id' => $registerId, 'title' => $registerTitle],
                'url'         => $objectUrl,
                'icon_url'    => $iconUrl,
                'updated'     => $updated,
                'properties'  => $properties,
            ];

            // Build the reference.
            $reference = new Reference($referenceText);
            $reference->setTitle($title);
            $reference->setDescription($description);
            $reference->setImageUrl($iconUrl);
            $reference->setUrl($objectUrl);
            $reference->setRichObject('openregister-object', $richData);

            return $reference;
        } catch (\Exception $exception) {
            // Catch all exceptions including authorization errors.
            // Return null to prevent metadata leakage on RBAC failures.
            $this->logger->debug(
                '[ObjectReferenceProvider] Failed to resolve reference: {error}',
                [
                    'error'     => $exception->getMessage(),
                    'reference' => $referenceText,
                ]
            );
            return null;
        }//end try
    }//end resolveReference()

    /**
     * Returns the cache prefix for a reference URL.
     *
     * @param string $referenceId The reference URL
     *
     * @return string Cache prefix based on register/schema/uuid
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getCachePrefix(string $referenceId): string
    {
        $parsed = $this->parseReference(referenceText: $referenceId);
        if ($parsed === null) {
            return $referenceId;
        }

        return $parsed['registerId'].'/'.$parsed['schemaId'].'/'.$parsed['uuid'];
    }//end getCachePrefix()

    /**
     * Returns the cache key for a reference URL.
     *
     * Uses the current user ID to ensure per-user caching (RBAC may differ).
     *
     * @param string $referenceId The reference URL
     *
     * @return string|null Cache key (user ID or empty string for anonymous)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function getCacheKey(string $referenceId): ?string
    {
        return $this->userId ?? '';
    }//end getCacheKey()

    /**
     * Parse a reference URL into its component parts.
     *
     * @param string $referenceText The URL to parse
     *
     * @return array{registerId: int, schemaId: int, uuid: string}|null Parsed parts or null
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    public function parseReference(string $referenceText): ?array
    {
        $baseUrl = $this->urlGenerator->getAbsoluteURL('/');
        $baseUrl = rtrim($baseUrl, '/');

        // Escape the base URL for use in regex.
        $escapedBase = preg_quote($baseUrl, '/');

        // UUID pattern (standard v4 format).
        $uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        // Pattern 1: Hash-routed UI URL.
        // /apps/openregister/#/registers/{id}/schemas/{id}/objects/{uuid}.
        $hashPattern = '/^'.$escapedBase.'(?:\/index\.php)?\/apps\/openregister\/#\/registers\/(\d+)\/schemas\/(\d+)\/objects\/('.$uuidPattern.')$/i';

        if (preg_match($hashPattern, $referenceText, $matches) === 1) {
            return [
                'registerId' => (int) $matches[1],
                'schemaId'   => (int) $matches[2],
                'uuid'       => $matches[3],
            ];
        }

        // Pattern 2: API object URL.
        // /apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}.
        $apiPattern = '/^'.$escapedBase.'(?:\/index\.php)?\/apps\/openregister\/api\/objects\/(\d+)\/(\d+)\/('.$uuidPattern.')$/i';

        if (preg_match($apiPattern, $referenceText, $matches) === 1) {
            return [
                'registerId' => (int) $matches[1],
                'schemaId'   => (int) $matches[2],
                'uuid'       => $matches[3],
            ];
        }

        // Pattern 3: Direct object show route.
        // /apps/openregister/objects/{registerId}/{schemaId}/{uuid}.
        $directPattern = '/^'.$escapedBase.'(?:\/index\.php)?\/apps\/openregister\/objects\/(\d+)\/(\d+)\/('.$uuidPattern.')$/i';

        if (preg_match($directPattern, $referenceText, $matches) === 1) {
            return [
                'registerId' => (int) $matches[1],
                'schemaId'   => (int) $matches[2],
                'uuid'       => $matches[3],
            ];
        }

        return null;
    }//end parseReference()

    /**
     * Extract the display title from object data.
     *
     * @param array $objectData The full object data
     * @param array $selfData   The @self metadata
     *
     * @return string The object title
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    private function extractTitle(array $objectData, array $selfData): string
    {
        // Try @self.name first.
        if (empty($selfData['name']) === false && is_string($selfData['name']) === true) {
            return $selfData['name'];
        }

        // Try title property.
        if (empty($objectData['title']) === false && is_string($objectData['title']) === true) {
            return $objectData['title'];
        }

        // Try name property.
        if (empty($objectData['name']) === false && is_string($objectData['name']) === true) {
            return $objectData['name'];
        }

        // Fall back to UUID.
        $uuid = $selfData['id'] ?? $objectData['id'] ?? '';
        if (is_string($uuid) === true && $uuid !== '') {
            return $uuid;
        }

        return $this->l10n->t('Unknown Object');
    }//end extractTitle()

    /**
     * Extract a description from object data.
     *
     * @param array $objectData The full object data
     *
     * @return string Truncated description (max 200 chars)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    private function extractDescription(array $objectData): string
    {
        // Try summary first.
        if (empty($objectData['summary']) === false && is_string($objectData['summary']) === true) {
            return mb_substr($objectData['summary'], 0, self::MAX_DESCRIPTION_LENGTH);
        }

        // Try description.
        if (empty($objectData['description']) === false && is_string($objectData['description']) === true) {
            $desc = mb_substr($objectData['description'], 0, self::MAX_DESCRIPTION_LENGTH);
            if (mb_strlen($objectData['description']) > self::MAX_DESCRIPTION_LENGTH) {
                $desc .= '...';
            }

            return $desc;
        }

        return '';
    }//end extractDescription()

    /**
     * Extract up to 4 preview properties from object data.
     *
     * Skips internal fields and non-scalar values.
     *
     * @param array $objectData The full object data
     *
     * @return array<int, array{label: string, value: string}> Preview properties
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    private function extractPreviewProperties(array $objectData): array
    {
        $properties = [];
        $count      = 0;

        foreach ($objectData as $key => $value) {
            if ($count >= self::MAX_PREVIEW_PROPERTIES) {
                break;
            }

            // Skip internal fields.
            if (in_array($key, self::INTERNAL_FIELDS, true) === true) {
                continue;
            }

            // Skip fields starting with underscore or @.
            if (strpos($key, '_') === 0 || strpos($key, '@') === 0) {
                continue;
            }

            // Only include scalar string/number values.
            if (is_string($value) === true && $value !== '') {
                $properties[] = [
                    'label' => ucfirst($key),
                    'value' => mb_substr($value, 0, 100),
                ];
                $count++;
            } else if (is_int($value) === true || is_float($value) === true) {
                $properties[] = [
                    'label' => ucfirst($key),
                    'value' => (string) $value,
                ];
                $count++;
            }
        }//end foreach

        return $properties;
    }//end extractPreviewProperties()

    /**
     * Resolve a schema ID to its display title.
     *
     * @param int $schemaId The schema ID
     *
     * @return string The schema title or fallback
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    private function resolveSchemaName(int $schemaId): string
    {
        try {
            $schema = $this->schemaMapper->find($schemaId);
            $title  = $schema->getTitle();
            if ($title !== null && $title !== '') {
                return $title;
            }
        } catch (\Exception $e) {
            // Fall through to default.
        }

        return $this->l10n->t('Unknown Schema');
    }//end resolveSchemaName()

    /**
     * Resolve a register ID to its display title.
     *
     * @param int $registerId The register ID
     *
     * @return string The register title or fallback
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-3
     */
    private function resolveRegisterName(int $registerId): string
    {
        try {
            $register = $this->registerMapper->find($registerId);
            $title    = $register->getTitle();
            if ($title !== null && $title !== '') {
                return $title;
            }
        } catch (\Exception $e) {
            // Fall through to default.
        }

        return $this->l10n->t('Unknown Register');
    }//end resolveRegisterName()
}//end class
