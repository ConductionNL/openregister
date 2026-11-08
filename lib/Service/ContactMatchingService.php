<?php

/**
 * OpenRegister ContactMatchingService
 *
 * Shared service for matching contact metadata (email, name, organization)
 * to OpenRegister entities with APCu caching.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ContactMatchingService implements ContactMatcherInterface {

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
		'organisation',
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
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The schema mapper for schema lookups.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The register mapper for register lookups.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

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
	 * @param ObjectOperationsInterface $objectService The object service
	 * @param SchemaMapperInterface $schemaMapper The schema mapper
	 * @param RegisterMapperInterface $registerMapper The register mapper
	 * @param ICacheFactory $cacheFactory The cache factory
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ObjectOperationsInterface $objectService,
		SchemaMapperInterface $schemaMapper,
		RegisterMapperInterface $registerMapper,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
	) {
		$this->objectService = $objectService;
		$this->schemaMapper = $schemaMapper;
		$this->registerMapper = $registerMapper;
		$this->logger = $logger;

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
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function matchByEmail(string $email): array {
		if (empty($email) === true) {
			return [];
		}

		// Fast path: skip if no schema declares linkedTypes:["contact"].
		// Without this, the ContactsMenuProvider's call to matchByEmail on
		// every contacts-menu render does a full-text search across every
		// schema in every register, which wedges Apache workers when
		// there are many schemas and no contact-linked ones.
		if ($this->hasContactLinkedSchemas() === false) {
			return [];
		}

		$email = strtolower(trim($email));
		$cacheKey = 'or_contact_match_email_' . hash('sha256', $email);

		$cached = $this->cachedMatches(cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		// Search across all registers and schemas.
		$results = $this->searchAndFilter(
			query: (new ContactMatchQuery(searchTerm: $email, matchType: 'email'))
				->withScoring(confidence: 1.0, exactMatch: true)
				->withPatterns(propertyPatterns: self::EMAIL_PROPERTY_PATTERNS)
		);

		$this->cacheMatches(cacheKey: $cacheKey, results: $results);

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
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function matchByName(?string $name): array {
		if (empty($name) === true) {
			return [];
		}

		if ($this->hasContactLinkedSchemas() === false) {
			return [];
		}

		$name = trim($name);
		$cacheKey = 'or_contact_match_name_' . hash('sha256', strtolower($name));

		$cached = $this->cachedMatches(cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$nameParts = array_filter(
			explode(' ', $name),
			static function (string $part): bool {
				return strlen($part) > 1;
			}
		);

		// Search for the full name.
		$results = $this->searchAndFilterByName(searchTerm: $name, nameParts: array_values($nameParts));

		$this->cacheMatches(cacheKey: $cacheKey, results: $results);

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
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function matchByOrganization(?string $organization): array {
		if (empty($organization) === true) {
			return [];
		}

		if ($this->hasContactLinkedSchemas() === false) {
			return [];
		}

		$organization = trim($organization);
		$cacheKey = 'or_contact_match_org_' . hash('sha256', strtolower($organization));

		$cached = $this->cachedMatches(cacheKey: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		$results = $this->searchAndFilter(
			query: (new ContactMatchQuery(searchTerm: $organization, matchType: 'organization'))
				->withScoring(confidence: 0.5, exactMatch: false)
				->withPatterns(
					propertyPatterns: array_merge(self::ORG_PROPERTY_PATTERNS, ['naam', 'name']),
					schemaFilter: self::ORG_SCHEMA_PATTERNS
				)
		);

		$this->cacheMatches(cacheKey: $cacheKey, results: $results);

		return $results;
	}//end matchByOrganization()

	/**
	 * Combined contact matching with deduplication.
	 *
	 * Calls matchByEmail first, then matchByName and matchByOrganization if provided.
	 * Deduplicates by object UUID, keeping the highest confidence match.
	 *
	 * @param string $email The email address (required)
	 * @param string|null $name The display name (optional)
	 * @param string|null $organization The organization name (optional)
	 *
	 * @return array Combined, deduplicated match results sorted by confidence
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function matchContact(
		string $email,
		?string $name = null,
		?string $organization = null,
	): array {
		// Email matching (highest confidence).
		$allMatches = $this->byUuid(matches: $this->matchByEmail(email: $email));

		// Name matching (medium confidence).
		if (($name ?? '') !== '') {
			$allMatches = $this->keepStrongest(allMatches: $allMatches, matches: $this->matchByName(name: $name));
		}

		// Organization matching (lowest confidence).
		if (($organization ?? '') !== '') {
			$allMatches = $this->keepStrongest(
				allMatches: $allMatches,
				matches: $this->matchByOrganization(organization: $organization)
			);
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
	 * Key matches by their uuid, dropping those without one; a later match with the same uuid wins.
	 *
	 * @param array<int,array<string,mixed>> $matches The matches.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function byUuid(array $matches): array {
		$byUuid = [];
		foreach ($matches as $match) {
			$uuid = $match['uuid'] ?? '';
			if ($uuid !== '') {
				$byUuid[$uuid] = $match;
			}
		}

		return $byUuid;
	}//end byUuid()

	/**
	 * Get related object counts grouped by schema title.
	 *
	 * @param array $matches The match results from matchContact()
	 *
	 * @return array Associative array of schema title => count
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function getRelatedObjectCounts(array $matches): array {
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
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function invalidateCache(string $email): void {
		if ($this->cache === null || empty($email) === true) {
			return;
		}

		$cacheKey = 'or_contact_match_email_' . hash('sha256', strtolower(trim($email)));
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
	 *
	 * @spec openspec/specs/contacts-actions/spec.md
	 */
	public function invalidateCacheForObject(array $object): void {
		if ($this->cache === null) {
			return;
		}

		foreach ($object as $key => $value) {
			$this->invalidateForProperty(key: $key, value: $value);
		}
	}//end invalidateCacheForObject()

	/**
	 * Invalidate the cached matches of one property's value when it is an email in an email-like property.
	 *
	 * @param int|string $key   The property name.
	 * @param mixed      $value The property value.
	 *
	 * @return void
	 */
	private function invalidateForProperty(int|string $key, mixed $value): void {
		if (is_string($value) === false || empty($value) === true) {
			return;
		}

		if ($this->isEmailProperty(key: $key) === true && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
			$this->invalidateCache(email: $value);
		}
	}//end invalidateForProperty()

	/**
	 * Whether a property name looks like an email field.
	 *
	 * @param int|string $key The property name.
	 *
	 * @return bool
	 */
	private function isEmailProperty(int|string $key): bool {
		$keyLower = strtolower((string)$key);
		foreach (self::EMAIL_PROPERTY_PATTERNS as $pattern) {
			if (str_contains($keyLower, $pattern) === true) {
				return true;
			}
		}

		return false;
	}//end isEmailProperty()

	/**
	 * Per-request cache for the contact-linked-schemas check.
	 *
	 * @var boolean|null
	 */
	private ?bool $hasContactLinkedSchemasCache = null;

	/**
	 * Returns true when at least one schema declares `linkedTypes: ["contact"]` in its configuration.
	 *
	 * Used as a fast-path skip in matchByEmail/Name/Organization so the
	 * ContactsMenuProvider doesn't run an expensive cross-schema search
	 * on deployments that don't opt any schema into contact-linking.
	 *
	 * @return bool True if any schema opts into contact linking.
	 */
	private function hasContactLinkedSchemas(): bool {
		if ($this->hasContactLinkedSchemasCache !== null) {
			return $this->hasContactLinkedSchemasCache;
		}

		try {
			$schemas = $this->schemaMapper->findAll(_multitenancy: false);
			foreach ($schemas as $schema) {
				$linkedTypes = $schema->getLinkedTypes();
				if (in_array('contact', $linkedTypes, true) === true) {
					return $this->hasContactLinkedSchemasCache = true;
				}
			}
		} catch (\Throwable $e) {
			// Fail closed — if we can't tell, skip the search.
		}

		return $this->hasContactLinkedSchemasCache = false;
	}//end hasContactLinkedSchemas()

	/**
	 * Merge matches into a uuid-keyed set, keeping the higher confidence per uuid.
	 *
	 * @param array<string, array> $allMatches Matches so far, by uuid.
	 * @param array<int, array>    $matches    New matches; those without a uuid are skipped.
	 *
	 * @return array<string, array> The merged set.
	 */
	private function keepStrongest(array $allMatches, array $matches): array {
		foreach ($matches as $match) {
			$uuid = $match['uuid'] ?? '';
			if ($uuid === '') {
				continue;
			}

			if (isset($allMatches[$uuid]) === false
				|| $match['confidence'] > $allMatches[$uuid]['confidence']
			) {
				$allMatches[$uuid] = $match;
			}
		}

		return $allMatches;
	}//end keepStrongest()

	/**
	 * Cached matches for a key, each marked as cached; null on a miss or without a cache.
	 *
	 * @param string $cacheKey The cache key.
	 *
	 * @return array|null
	 */
	private function cachedMatches(string $cacheKey): ?array {
		if ($this->cache === null) {
			return null;
		}

		$cached = $this->cache->get($cacheKey);
		if ($cached === null) {
			return null;
		}

		$results = json_decode($cached, true);
		if (is_array($results) === false) {
			return null;
		}

		return array_map(
			static function (array $match): array {
				$match['cached'] = true;
				return $match;
			},
			$results
		);
	}//end cachedMatches()

	/**
	 * Store matches under a key for CACHE_TTL seconds, when a cache is available.
	 *
	 * @param string $cacheKey The cache key.
	 * @param array  $results  The matches.
	 *
	 * @return void
	 */
	private function cacheMatches(string $cacheKey, array $results): void {
		if ($this->cache !== null) {
			$this->cache->set($cacheKey, json_encode($results), self::CACHE_TTL);
		}
	}//end cacheMatches()

	/**
	 * Full-text search results for a term; empty when the search fails, the failure logged.
	 *
	 * @param string $searchTerm     The term to search for.
	 * @param string $failureMessage The log message on failure, with an {error} placeholder.
	 *
	 * @return array The search results.
	 */
	private function searchResults(string $searchTerm, string $failureMessage): array {
		try {
			$searchResults = $this->objectService->searchObjects(
				query: ['_search' => $searchTerm],
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Exception $e) {
			$this->logger->warning($failureMessage, ['error' => $e->getMessage()]);
			return [];
		}

		if (is_array($searchResults) === false) {
			return [];
		}

		return $searchResults;
	}//end searchResults()

	/**
	 * Search objects and keep those in an accepted schema with a matching property.
	 *
	 * @param ContactMatchQuery $query The search.
	 *
	 * @return array The filtered match results.
	 */
	private function searchAndFilter(ContactMatchQuery $query): array {
		$matches = [];
		foreach ($this->searchResults(searchTerm: $query->searchTerm, failureMessage: '[ContactMatching] Search failed: {error}') as $result) {
			if (is_array($result) === true && $query->acceptsSchema(result: $result) === true
				&& $query->matchesProperty(result: $result) === true
			) {
				$matches[] = $this->formatMatch(result: $result, query: $query);
			}
		}

		return $matches;
	}//end searchAndFilter()

	/**
	 * Search and filter by name with confidence scoring: 0.7 when every name part
	 * appears in a name-like property, 0.4 when some do.
	 *
	 * @param string $searchTerm The full name to search for
	 * @param array  $nameParts  The name parts for partial matching
	 *
	 * @return array The filtered match results with confidence scores
	 */
	private function searchAndFilterByName(string $searchTerm, array $nameParts): array {
		$matches = [];
		foreach ($this->searchResults(searchTerm: $searchTerm, failureMessage: '[ContactMatching] Name search failed: {error}') as $result) {
			if (is_array($result) === false) {
				continue;
			}

			$matchedParts = $this->countMatchingNameParts(result: $result, nameParts: $nameParts);
			if ($matchedParts === 0) {
				continue;
			}

			$confidence = 0.4;
			if ($matchedParts === count($nameParts)) {
				$confidence = 0.7;
			}

			$query = (new ContactMatchQuery(searchTerm: $searchTerm, matchType: 'name'))
				->withScoring(confidence: $confidence, exactMatch: false);
			$matches[] = $this->formatMatch(result: $result, query: $query);
		}//end foreach

		return $matches;
	}//end searchAndFilterByName()

	/**
	 * Count how many name parts appear in name-like properties.
	 *
	 * @param array $result    The search result object
	 * @param array $nameParts The name parts to look for
	 *
	 * @return int Number of name parts that match
	 */
	private function countMatchingNameParts(array $result, array $nameParts): int {
		$matchedParts = 0;
		$concatenatedValues = $this->nameValues(result: $result);

		foreach ($nameParts as $part) {
			if (stripos($concatenatedValues, strtolower($part)) !== false) {
				$matchedParts++;
			}
		}

		return $matchedParts;
	}//end countMatchingNameParts()

	/**
	 * The lower-cased string values of all name-like properties, each prefixed by a space.
	 *
	 * @param array<string,mixed> $result The search result.
	 *
	 * @return string
	 */
	private function nameValues(array $result): string {
		$concatenatedValues = '';

		// Collect all name-like property values.
		foreach ($result as $key => $value) {
			if (is_string($value) === false) {
				continue;
			}

			if ($this->isNameProperty(key: $key) === true) {
				$concatenatedValues .= ' ' . strtolower($value);
			}
		}

		return $concatenatedValues;
	}//end nameValues()

	/**
	 * Whether a property name looks like a name field.
	 *
	 * @param int|string $key The property name.
	 *
	 * @return bool
	 */
	private function isNameProperty(int|string $key): bool {
		$keyLower = strtolower((string)$key);
		foreach (self::NAME_PROPERTY_PATTERNS as $pattern) {
			if (str_contains($keyLower, strtolower($pattern)) === true) {
				return true;
			}
		}

		return false;
	}//end isNameProperty()

	/**
	 * Format a search result into a match array.
	 *
	 * @param array             $result The search result object
	 * @param ContactMatchQuery $query  The search, for the match type and confidence
	 *
	 * @return array The formatted match
	 */
	private function formatMatch(array $result, ContactMatchQuery $query): array {
		// Extract schema info.
		$schemaInfo = $this->schemaInfo(result: $result);

		// Extract register info.
		$registerInfo = $this->registerInfo(result: $result);

		// Determine a title for the matched object.
		$title = $result['title'] ?? $result['naam'] ?? $result['name'] ?? $result['@self']['uuid'] ?? 'Unknown';

		return [
			'uuid' => $result['@self']['uuid'] ?? $result['uuid'] ?? '',
			'register' => $registerInfo,
			'schema' => $schemaInfo,
			'title' => $title,
			'matchType' => $query->matchType,
			'confidence' => $query->confidence(),
			'properties' => $this->scalarProperties(result: $result),
			'cached' => false,
		];
	}//end formatMatch()

	/**
	 * The id and title of the result's schema; none when it has no schema.
	 *
	 * @param array<string,mixed> $result The search result.
	 *
	 * @return array<string,mixed>
	 */
	private function schemaInfo(array $result): array {
		if (isset($result['@self']['schema']) === false) {
			return [];
		}

		$schemaId = (int)$result['@self']['schema'];
		try {
			$schema = $this->schemaMapper->find($schemaId);
			return [
				'id' => $schemaId,
				'title' => $schema->getTitle() ?? $schema->getName() ?? 'Unknown',
			];
		} catch (\Exception $e) {
			return ['id' => $schemaId, 'title' => 'Unknown'];
		}
	}//end schemaInfo()

	/**
	 * The id and title of the result's register; none when it has no register.
	 *
	 * @param array<string,mixed> $result The search result.
	 *
	 * @return array<string,mixed>
	 */
	private function registerInfo(array $result): array {
		if (isset($result['@self']['register']) === false) {
			return [];
		}

		$registerId = (int)$result['@self']['register'];
		try {
			$register = $this->registerMapper->find($registerId);
			return [
				'id' => $registerId,
				'title' => $register->getTitle() ?? $register->getName() ?? 'Unknown',
			];
		} catch (\Exception $e) {
			return ['id' => $registerId, 'title' => 'Unknown'];
		}
	}//end registerInfo()

	/**
	 * The scalar properties of a result, without metadata (`@`/`_` prefixed) keys.
	 *
	 * @param array<string,mixed> $result The search result.
	 *
	 * @return array<string,mixed>
	 */
	private function scalarProperties(array $result): array {
		$properties = [];
		foreach ($result as $key => $value) {
			if (str_starts_with($key, '@') === true || str_starts_with($key, '_') === true) {
				continue;
			}

			if (is_scalar($value) === true) {
				$properties[$key] = $value;
			}
		}

		return $properties;
	}//end scalarProperties()
}//end class
