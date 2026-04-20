<?php

/**
 * OpenRegister ContactMatchingService
 *
 * Shared service for matching contact metadata (email, name, organization)
 * to OpenRegister entities with APCu caching.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for matching contact metadata to OpenRegister entities.
 *
 * Provides matching by email (primary, confidence 1.0), name (secondary, 0.4-0.7),
 * and organization (tertiary, 0.5) with APCu cache (TTL 60s).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ContactMatchingService
{

    /**
     * Cache TTL in seconds.
     *
     * @var int
     */
    private const CACHE_TTL = 60;

    /**
     * Property names that indicate an email field.
     *
     * @var array<string>
     */
    private const EMAIL_PROPERTY_PATTERNS = [
        'email',
        'e-mail',
        'mail',
        'emailadres',
        'emailaddress',
    ];

    /**
     * Property names that indicate a name field.
     *
     * @var array<string>
     */
    private const NAME_PROPERTY_PATTERNS = [
        'naam',
        'name',
        'voornaam',
        'achternaam',
        'firstname',
        'lastname',
        'first_name',
        'last_name',
        'fullname',
        'full_name',
        'volledigenaam',
    ];

    /**
     * Property names that indicate an organization field.
     *
     * @var array<string>
     */
    private const ORG_PROPERTY_PATTERNS = [
        'organisatie',
        'organization',
        'organisation',
        'bedrijf',
        'company',
    ];

    /**
     * Schema name patterns indicating an organization-type schema.
     *
     * @var array<string>
     */
    private const ORG_SCHEMA_PATTERNS = [
        'organisat',
        'company',
        'bedrijf',
        'organization',
        'organisation',
    ];

    /**
     * The object service for searching entities.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * The schema mapper for schema lookups.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * The register mapper for register lookups.
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * The distributed cache instance.
     *
     * @var ICache|null
     */
    private readonly ?ICache $cache;

    /**
     * Logger for debugging.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor for ContactMatchingService.
     *
     * @param ObjectService   $objectService  The object service
     * @param SchemaMapper    $schemaMapper   The schema mapper
     * @param RegisterMapper  $registerMapper The register mapper
     * @param ICacheFactory   $cacheFactory   The cache factory
     * @param LoggerInterface $logger         The logger
     *
     * @return void
     */
    public function __construct(
        ObjectService $objectService,
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        $this->objectService  = $objectService;
        $this->schemaMapper   = $schemaMapper;
        $this->registerMapper = $registerMapper;
        $this->logger         = $logger;

        try {
            $this->cache = $cacheFactory->createDistributed('openregister_contacts');
        } catch (\Exception $e) {
            $this->logger->warning(
                '[ContactMatching] Failed to create cache: {error}',
                ['error' => $e->getMessage()]
            );
            $this->cache = null;
        }
    }//end __construct()

    /**
     * Match a contact by email address (highest confidence).
     *
     * Searches across all registers and schemas for objects containing the
     * given email address. Results are cached with TTL 60s.
     *
     * @param string $email The email address to match
     *
     * @return array The match results with confidence 1.0
     */
    public function matchByEmail(string $email): array
    {
        if (empty($email) === true) {
            return [];
        }

        $email    = strtolower(trim($email));
        $cacheKey = 'or_contact_match_email_'.hash('sha256', $email);

        // Check cache first.
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results = json_decode($cached, true);
                if (is_array($results) === true) {
                    return array_map(
                        static function (array $match): array {
                            $match['cached'] = true;
                            return $match;
                        },
                        $results
                    );
                }
            }
        }

        // Search across all registers and schemas.
        $results = $this->searchAndFilter(
            searchTerm: $email,
            propertyPatterns: self::EMAIL_PROPERTY_PATTERNS,
            matchType: 'email',
            confidence: 1.0,
            exactMatch: true
        );

        // Cache results.
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode($results), self::CACHE_TTL);
        }

        return $results;
    }//end matchByEmail()

    /**
     * Match a contact by display name (medium confidence).
     *
     * Splits name into parts and searches for objects with matching name properties.
     * Full match = 0.7, partial match = 0.4.
     *
     * @param string|null $name The display name to match
     *
     * @return array The match results
     */
    public function matchByName(?string $name): array
    {
        if (empty($name) === true) {
            return [];
        }

        $name     = trim($name);
        $cacheKey = 'or_contact_match_name_'.hash('sha256', strtolower($name));

        // Check cache first.
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results = json_decode($cached, true);
                if (is_array($results) === true) {
                    return array_map(
                        static function (array $match): array {
                            $match['cached'] = true;
                            return $match;
                        },
                        $results
                    );
                }
            }
        }

        $nameParts = array_filter(
                explode(' ', $name),
                static function (string $part): bool {
                    return strlen($part) > 1;
                }
                );

        // Search for the full name.
        $results = $this->searchAndFilterByName(
            searchTerm: $name,
            nameParts: array_values($nameParts),
            propertyPatterns: self::NAME_PROPERTY_PATTERNS
        );

        // Cache results.
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode($results), self::CACHE_TTL);
        }

        return $results;
    }//end matchByName()

    /**
     * Match a contact by organization name (lowest confidence).
     *
     * Searches for organization-type objects matching the given name.
     * Only matches in schemas that are semantically "organization" schemas.
     *
     * @param string|null $organization The organization name to match
     *
     * @return array The match results with confidence 0.5
     */
    public function matchByOrganization(?string $organization): array
    {
        if (empty($organization) === true) {
            return [];
        }

        $organization = trim($organization);
        $cacheKey     = 'or_contact_match_org_'.hash('sha256', strtolower($organization));

        // Check cache first.
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $results = json_decode($cached, true);
                if (is_array($results) === true) {
                    return array_map(
                        static function (array $match): array {
                            $match['cached'] = true;
                            return $match;
                        },
                        $results
                    );
                }
            }
        }

        $results = $this->searchAndFilter(
            searchTerm: $organization,
            propertyPatterns: array_merge(self::ORG_PROPERTY_PATTERNS, ['naam', 'name']),
            matchType: 'organization',
            confidence: 0.5,
            exactMatch: false,
            schemaFilter: self::ORG_SCHEMA_PATTERNS
        );

        // Cache results.
        if ($this->cache !== null) {
            $this->cache->set($cacheKey, json_encode($results), self::CACHE_TTL);
        }

        return $results;
    }//end matchByOrganization()

    /**
     * Combined contact matching with deduplication.
     *
     * Calls matchByEmail first, then matchByName and matchByOrganization if provided.
     * Deduplicates by object UUID, keeping the highest confidence match.
     *
     * @param string      $email        The email address (required)
     * @param string|null $name         The display name (optional)
     * @param string|null $organization The organization name (optional)
     *
     * @return array Combined, deduplicated match results sorted by confidence
     */
    public function matchContact(
        string $email,
        ?string $name=null,
        ?string $organization=null
    ): array {
        $allMatches = [];

        // Email matching (highest confidence).
        $emailMatches = $this->matchByEmail(email: $email);
        foreach ($emailMatches as $match) {
            $uuid = $match['uuid'] ?? '';
            if ($uuid !== '') {
                $allMatches[$uuid] = $match;
            }
        }

        // Name matching (medium confidence).
        if ($name !== null && $name !== '') {
            $nameMatches = $this->matchByName(name: $name);
            foreach ($nameMatches as $match) {
                $uuid = $match['uuid'] ?? '';
                if ($uuid === '') {
                    continue;
                }

                // Keep highest confidence.
                if (isset($allMatches[$uuid]) === false
                    || $match['confidence'] > $allMatches[$uuid]['confidence']
                ) {
                    $allMatches[$uuid] = $match;
                }
            }
        }

        // Organization matching (lowest confidence).
        if ($organization !== null && $organization !== '') {
            $orgMatches = $this->matchByOrganization(organization: $organization);
            foreach ($orgMatches as $match) {
                $uuid = $match['uuid'] ?? '';
                if ($uuid === '') {
                    continue;
                }

                // Keep highest confidence.
                if (isset($allMatches[$uuid]) === false
                    || $match['confidence'] > $allMatches[$uuid]['confidence']
                ) {
                    $allMatches[$uuid] = $match;
                }
            }
        }

        // Sort by confidence descending.
        $results = array_values($allMatches);
        usort(
                $results,
                static function (array $a, array $b): int {
                    return ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0);
                }
                );

        return $results;
    }//end matchContact()

    /**
     * Get related object counts grouped by schema title.
     *
     * @param array $matches The match results from matchContact()
     *
     * @return array Associative array of schema title => count
     */
    public function getRelatedObjectCounts(array $matches): array
    {
        $counts = [];
        foreach ($matches as $match) {
            $schemaTitle = $match['schema']['title'] ?? 'Unknown';
            if (isset($counts[$schemaTitle]) === false) {
                $counts[$schemaTitle] = 0;
            }

            $counts[$schemaTitle]++;
        }

        return $counts;
    }//end getRelatedObjectCounts()

    /**
     * Invalidate cache for a specific email address.
     *
     * @param string $email The email address to invalidate
     *
     * @return void
     */
    public function invalidateCache(string $email): void
    {
        if ($this->cache === null || empty($email) === true) {
            return;
        }

        $cacheKey = 'or_contact_match_email_'.hash('sha256', strtolower(trim($email)));
        $this->cache->remove($cacheKey);

        $this->logger->debug(
            '[ContactMatching] Cache invalidated for email: {email}',
            ['email' => $email]
        );
    }//end invalidateCache()

    /**
     * Invalidate cache for all email-like property values in an object.
     *
     * @param array $object The object data array
     *
     * @return void
     */
    public function invalidateCacheForObject(array $object): void
    {
        if ($this->cache === null) {
            return;
        }

        foreach ($object as $key => $value) {
            if (is_string($value) === false || empty($value) === true) {
                continue;
            }

            $keyLower        = strtolower((string) $key);
            $isEmailProperty = false;
            foreach (self::EMAIL_PROPERTY_PATTERNS as $pattern) {
                if (str_contains($keyLower, $pattern) === true) {
                    $isEmailProperty = true;
                    break;
                }
            }

            if ($isEmailProperty === true && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $this->invalidateCache(email: $value);
            }
        }
    }//end invalidateCacheForObject()

    /**
     * Search objects and filter by property patterns.
     *
     * @param string     $searchTerm       The term to search for
     * @param array      $propertyPatterns Property name patterns to match
     * @param string     $matchType        The match type label
     * @param float      $confidence       The confidence score
     * @param bool       $exactMatch       Whether to require exact value match
     * @param array|null $schemaFilter     Optional schema name patterns to restrict results
     *
     * @return array The filtered match results
     */
    private function searchAndFilter(
        string $searchTerm,
        array $propertyPatterns,
        string $matchType,
        float $confidence,
        bool $exactMatch=true,
        ?array $schemaFilter=null
    ): array {
        try {
            $searchResults = $this->objectService->searchObjects(
                query: ['_search' => $searchTerm],
                _rbac: true,
                _multitenancy: true
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                '[ContactMatching] Search failed: {error}',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($searchResults) === false) {
            return [];
        }

        $matches = [];
        foreach ($searchResults as $result) {
            if (is_array($result) === false) {
                continue;
            }

            // Apply schema filter if provided.
            if ($schemaFilter !== null) {
                $schemaName    = strtolower($result['schema']['title'] ?? $result['schema']['name'] ?? '');
                $matchesSchema = false;
                foreach ($schemaFilter as $pattern) {
                    if (str_contains($schemaName, strtolower($pattern)) === true) {
                        $matchesSchema = true;
                        break;
                    }
                }

                if ($matchesSchema === false) {
                    continue;
                }
            }

            // Check if the search term appears in the right property type.
            $hasMatch = $this->hasMatchingProperty(
                result: $result,
                    searchTerm: $searchTerm,
                    propertyPatterns: $propertyPatterns,
                    exactMatch: $exactMatch
            );
            if ($hasMatch === true) {
                $matches[] = $this->formatMatch(result: $result, matchType: $matchType, confidence: $confidence);
            }
        }//end foreach

        return $matches;
    }//end searchAndFilter()

    /**
     * Search and filter by name with confidence scoring.
     *
     * @param string $searchTerm       The full name to search for
     * @param array  $nameParts        The name parts for partial matching
     * @param array  $propertyPatterns Property name patterns to match
     *
     * @return array The filtered match results with confidence scores
     */
    private function searchAndFilterByName(
        string $searchTerm,
        array $nameParts,
        array $propertyPatterns
    ): array {
        try {
            $searchResults = $this->objectService->searchObjects(
                query: ['_search' => $searchTerm],
                _rbac: true,
                _multitenancy: true
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                '[ContactMatching] Name search failed: {error}',
                ['error' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($searchResults) === false) {
            return [];
        }

        $matches = [];
        foreach ($searchResults as $result) {
            if (is_array($result) === false) {
                continue;
            }

            $matchedParts = $this->countMatchingNameParts(result: $result, nameParts: $nameParts, propertyPatterns: $propertyPatterns);
            $totalParts   = count($nameParts);

            if ($matchedParts === 0) {
                continue;
            }

            // Full match = 0.7, partial = 0.4.
            $confidence = ($matchedParts === $totalParts) ? 0.7 : 0.4;

            $matches[] = $this->formatMatch(result: $result, matchType: 'name', confidence: $confidence);
        }

        return $matches;
    }//end searchAndFilterByName()

    /**
     * Check if an object has a matching property.
     *
     * @param array  $result           The search result object
     * @param string $searchTerm       The value to look for
     * @param array  $propertyPatterns Property name patterns
     * @param bool   $exactMatch       Whether to require exact match
     *
     * @return bool True if a matching property is found
     */
    private function hasMatchingProperty(
        array $result,
        string $searchTerm,
        array $propertyPatterns,
        bool $exactMatch
    ): bool {
        foreach ($result as $key => $value) {
            if (is_string($value) === false) {
                continue;
            }

            $keyLower = strtolower((string) $key);
            foreach ($propertyPatterns as $pattern) {
                if (str_contains($keyLower, strtolower($pattern)) === false) {
                    continue;
                }

                if ($exactMatch === true) {
                    if (strtolower($value) === strtolower($searchTerm)) {
                        return true;
                    }
                } else {
                    if (stripos($value, $searchTerm) !== false
                        || stripos($searchTerm, $value) !== false
                    ) {
                        return true;
                    }
                }
            }
        }//end foreach

        return false;
    }//end hasMatchingProperty()

    /**
     * Count how many name parts appear in name-like properties.
     *
     * @param array $result           The search result object
     * @param array $nameParts        The name parts to look for
     * @param array $propertyPatterns Property name patterns
     *
     * @return int Number of name parts that match
     */
    private function countMatchingNameParts(
        array $result,
        array $nameParts,
        array $propertyPatterns
    ): int {
        $matchedParts       = 0;
        $concatenatedValues = '';

        // Collect all name-like property values.
        foreach ($result as $key => $value) {
            if (is_string($value) === false) {
                continue;
            }

            $keyLower = strtolower((string) $key);
            foreach ($propertyPatterns as $pattern) {
                if (str_contains($keyLower, strtolower($pattern)) === true) {
                    $concatenatedValues .= ' '.strtolower($value);
                    break;
                }
            }
        }

        // Check each name part.
        foreach ($nameParts as $part) {
            if (stripos($concatenatedValues, strtolower($part)) !== false) {
                $matchedParts++;
            }
        }

        return $matchedParts;
    }//end countMatchingNameParts()

    /**
     * Format a search result into a match array.
     *
     * @param array  $result     The search result object
     * @param string $matchType  The match type (email, name, organization)
     * @param float  $confidence The confidence score
     *
     * @return array The formatted match
     */
    private function formatMatch(array $result, string $matchType, float $confidence): array
    {
        $schemaInfo   = [];
        $registerInfo = [];

        // Extract schema info.
        if (isset($result['@self']['schema']) === true) {
            $schemaId = (int) $result['@self']['schema'];
            try {
                $schema     = $this->schemaMapper->find($schemaId);
                $schemaInfo = [
                    'id'    => $schemaId,
                    'title' => $schema->getTitle() ?? $schema->getName() ?? 'Unknown',
                ];
            } catch (\Exception $e) {
                $schemaInfo = ['id' => $schemaId, 'title' => 'Unknown'];
            }
        }

        // Extract register info.
        if (isset($result['@self']['register']) === true) {
            $registerId = (int) $result['@self']['register'];
            try {
                $register     = $this->registerMapper->find($registerId);
                $registerInfo = [
                    'id'    => $registerId,
                    'title' => $register->getTitle() ?? $register->getName() ?? 'Unknown',
                ];
            } catch (\Exception $e) {
                $registerInfo = ['id' => $registerId, 'title' => 'Unknown'];
            }
        }

        // Determine a title for the matched object.
        $title = $result['title'] ?? $result['naam'] ?? $result['name'] ?? $result['@self']['uuid'] ?? 'Unknown';

        // Build properties subset (exclude metadata).
        $properties = [];
        foreach ($result as $key => $value) {
            if (str_starts_with($key, '@') === true || str_starts_with($key, '_') === true) {
                continue;
            }

            if (is_scalar($value) === true) {
                $properties[$key] = $value;
            }
        }

        return [
            'uuid'       => $result['@self']['uuid'] ?? $result['uuid'] ?? '',
            'register'   => $registerInfo,
            'schema'     => $schemaInfo,
            'title'      => $title,
            'matchType'  => $matchType,
            'confidence' => $confidence,
            'properties' => $properties,
            'cached'     => false,
        ];
    }//end formatMatch()
}//end class
