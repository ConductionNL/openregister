<?php

/**
 * OpenRegister ManifestService
 *
 * Enriches a host-app manifest.json with a `runtime.user` block derived
 * from the requesting user's OpenRegister profile object.
 *
 * Behaviour summary
 * -----------------
 * 1. If the manifest carries no `currentUserSchema` key → return unchanged.
 * 2. Anonymous request            → `runtime.user = null`.
 * 3. Logged-in user, no profile   → `runtime.user = { id, roles: ["learner"] }`.
 * 4. Logged-in user, profile found → `runtime.user` populated with every
 *    `x-openregister-calculations.*` field evaluated against the profile object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/manifest-user-context/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enriches a manifest with `runtime.user` context for the authenticated user.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ManifestService
{
    /**
     * Maximum accepted length of a manifest-supplied schema slug.
     *
     * Bounded length + a strict charset on schemaSlug keep a compromised
     * manifest.json from steering profile/calculation lookup at arbitrary
     * schemas — see the manifest review note "schemaSlug not validated".
     *
     * @var int
     */
    private const MAX_SLUG_LENGTH = 128;

    /**
     * Regex for accepted schema-slug characters. Mirrors the slugs we
     * actually emit (lowercase alnum + dash + underscore).
     *
     * @var string
     */
    private const SLUG_PATTERN = '/^[A-Za-z0-9_\-]{1,128}$/';

    /**
     * Manifest annotation that declares which profile-object fields are
     * safe to surface in `runtime.user`. When the schema's configuration
     * carries no such allowlist, ONLY the fields named by the calling
     * manifest's calculation map are surfaced. Raw `profile->getObject()`
     * fields are never blindly merged into runtime.user — that was the
     * leak path flagged in the review.
     *
     * @var string
     */
    private const FIELD_ALLOWLIST_KEY = 'x-openregister-manifest-user-fields';

    /**
     * Built-in profile fields that are ALWAYS safe to surface (no PII,
     * already exposed via other Nextcloud APIs for the same user).
     *
     * @var list<string>
     */
    private const DEFAULT_SAFE_FIELDS = ['id', 'roles'];

    /**
     * Constructor.
     *
     * @param ObjectService        $objectService OR object retrieval service.
     * @param SchemaMapper         $schemaMapper  Schema lookup by slug.
     * @param CalculationEvaluator $evaluator     Expression evaluator.
     * @param IUserSession         $userSession   Nextcloud user session.
     * @param LoggerInterface      $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly CalculationEvaluator $evaluator,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Return the manifest enriched with a `runtime.user` block.
     *
     * @param array<string, mixed> $manifest Parsed manifest.json from the calling app.
     *
     * @return array<string, mixed> Enriched manifest (original if no `currentUserSchema`).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getEnrichedManifest(array $manifest): array
    {
        // 1. No currentUserSchema declared → return unchanged.
        $schemaSlug = ($manifest['currentUserSchema'] ?? null);
        if ($schemaSlug === null || $schemaSlug === '') {
            return $manifest;
        }

        // Validate the schema slug before doing ANY lookup with it: a
        // compromised or malformed manifest must not be able to point
        // profile/calculation resolution at an attacker-controlled string
        // (length, charset, non-printable injection). Fail closed when
        // validation fails.
        if (is_string($schemaSlug) === false
            || strlen($schemaSlug) > self::MAX_SLUG_LENGTH
            || preg_match(self::SLUG_PATTERN, $schemaSlug) !== 1
        ) {
            $this->logger->warning(
                message: sprintf('[ManifestService] Rejected invalid currentUserSchema slug %s', var_export($schemaSlug, true)),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $manifest['runtime']['user'] = null;
            return $manifest;
        }

        // 2. Anonymous request → runtime.user = null.
        $user = $this->userSession->getUser();
        if ($user === null) {
            $manifest['runtime']['user'] = null;
            return $manifest;
        }

        $userId = $user->getUID();

        // 3. Try to find the user's profile object in OR.
        $profileObject = $this->resolveUserProfile(schemaSlug: (string) $schemaSlug, userId: $userId);

        if ($profileObject === null) {
            // 4a. No profile → minimal fallback.
            $manifest['runtime']['user'] = [
                'id'    => $userId,
                'roles' => ['learner'],
            ];
            return $manifest;
        }

        // 4b. Profile found → evaluate calculations and inject.
        $manifest['runtime']['user'] = $this->buildUserContext(
            userId: $userId,
            profile: $profileObject,
            schemaSlug: (string) $schemaSlug
        );

        return $manifest;
    }//end getEnrichedManifest()

    /**
     * Locate the user's profile object for the given schema slug.
     *
     * Filters by `ncUserId === $userId` inside the magic table for that schema.
     *
     * @param string $schemaSlug Schema slug declared in the manifest.
     * @param string $userId     Nextcloud user ID.
     *
     * @return ObjectEntity|null The profile object, or null if not found.
     */
    private function resolveUserProfile(string $schemaSlug, string $userId): ?ObjectEntity
    {
        try {
            $schemas = $this->schemaMapper->findBySlug(slug: $schemaSlug, limit: 1);
            if (count($schemas) === 0) {
                $this->logger->debug(
                    message: sprintf('[ManifestService] Schema "%s" not found', $schemaSlug),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return null;
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                message: sprintf('[ManifestService] Schema lookup failed for "%s": %s', $schemaSlug, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        try {
            // RBAC + multitenancy MUST remain on. The profile lookup is per
            // current user — disabling RBAC/multitenancy here would silently
            // return profiles from other tenants whenever the same NC UID
            // exists in more than one tenant. The (schema, ncUserId) filter
            // is a *narrowing* filter on top of the tenant scope, not a
            // substitute for it.
            $results = $this->objectService->findAll(
                config: [
                    'limit'   => 1,
                    'filters' => [
                        'schema'   => $schemaSlug,
                        'ncUserId' => $userId,
                    ],
                ]
            );

            return count($results) > 0 ? $results[0] : null;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: sprintf('[ManifestService] Profile lookup failed for user "%s": %s', $userId, $e->getMessage()),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }//end try
    }//end resolveUserProfile()

    /**
     * Build the `runtime.user` block from the profile object.
     *
     * Seeds the block with the raw profile payload and then overlays every
     * `x-openregister-calculations.*` result that is NOT materialised
     * (materialised fields are already in the stored data). Non-materialised
     * calculations are evaluated at read-time here so that the manifest always
     * reflects the freshest derived state.
     *
     * @param string       $userId     Nextcloud user ID (always included as `id`).
     * @param ObjectEntity $profile    The user's profile object entity.
     * @param string       $schemaSlug Schema slug (used for schema re-lookup to read calcs).
     *
     * @return array<string, mixed> The `runtime.user` data block.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function buildUserContext(string $userId, ObjectEntity $profile, string $schemaSlug): array
    {
        $data = $profile->getObject() ?? [];

        // Resolve the field allowlist BEFORE merging anything from the
        // profile object. Without an allowlist every stored field — including
        // schema-internal or PII fields — would leak verbatim to anything
        // consuming the manifest. The allowlist lives on the schema
        // configuration under x-openregister-manifest-user-fields; calculated
        // (non-materialised) fields named in the calc map are always added,
        // since by definition they are derived/safe-to-surface values.
        $calcs        = $this->getCalculations(schemaSlug: $schemaSlug);
        $allowedNames = $this->resolveAllowedFieldNames(
            schemaSlug: $schemaSlug,
            calcs: $calcs
        );

        $filteredData = [];
        foreach ($allowedNames as $field) {
            if (array_key_exists($field, $data) === true) {
                $filteredData[$field] = $data[$field];
            }
        }

        // Always surface the Nextcloud user ID as `id`.
        $context = array_merge($filteredData, ['id' => $userId]);

        // Evaluate non-materialised calculations to enrich the context.
        if ($calcs === null) {
            return $context;
        }

        // Build @self metadata the same way the listener does, so expressions
        // referencing @self.created / @self.updated work correctly.
        $created       = $profile->getCreated();
        $updated       = $profile->getUpdated();
        $data['@self'] = [
            'id'       => $profile->getUuid(),
            'uuid'     => $profile->getUuid(),
            'register' => $profile->getRegister(),
            'schema'   => $profile->getSchema(),
            'owner'    => $profile->getOwner(),
            'created'  => $created !== null ? $created->format(DateTimeInterface::ATOM) : null,
            'updated'  => $updated !== null ? $updated->format(DateTimeInterface::ATOM) : null,
        ];

        foreach ($calcs as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            try {
                $value = $this->evaluator->evaluate($data, ($spec['expression'] ?? null));
            } catch (EvaluationException $e) {
                $this->logger->warning(
                    message: sprintf(
                        '[ManifestService] Calculation "%s" failed for user "%s": %s',
                        (string) $name,
                        $userId,
                        $e->getMessage()
                    ),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                continue;
            }

            // Only inject non-materialised computations here; materialised ones
            // are already stored in the profile payload and surfaced via $context.
            $materialise = ($spec['materialise'] ?? false);
            if ($materialise !== true) {
                $context[(string) $name] = $this->serialise(value: $value);
            }
        }//end foreach

        return $context;
    }//end buildUserContext()

    /**
     * Read `x-openregister-calculations` from the schema identified by slug.
     *
     * @param string $schemaSlug The schema slug.
     *
     * @return array<string, mixed>|null The calculations map, or null when absent.
     */
    private function getCalculations(string $schemaSlug): ?array
    {
        try {
            $schemas = $this->schemaMapper->findBySlug(slug: $schemaSlug, limit: 1);
        } catch (Throwable) {
            return null;
        }

        if (count($schemas) === 0) {
            return null;
        }

        $config = ($schemas[0]->getConfiguration() ?? []);
        $calcs  = ($config['x-openregister-calculations'] ?? null);
        return is_array($calcs) === true ? $calcs : null;
    }//end getCalculations()

    /**
     * Resolve the list of profile fields safe to copy into runtime.user.
     *
     * Source of truth, in order:
     * 1. `x-openregister-manifest-user-fields` on the schema configuration
     *    — an explicit author-provided allowlist (list<string>).
     * 2. The keys of `x-openregister-calculations` whose entries are
     *    materialised — these are derived values the schema author has
     *    already declared safe to surface.
     * 3. The default safe fields (id, roles).
     *
     * NEVER returns the full key list of the profile object: that was the
     * leak path flagged by review (raw `$profile->getObject()` merge).
     *
     * @param string                    $schemaSlug Validated schema slug.
     * @param array<string, mixed>|null $calcs      Calculation map for the schema.
     *
     * @return list<string> Distinct field names allowed in runtime.user.
     */
    private function resolveAllowedFieldNames(string $schemaSlug, ?array $calcs): array
    {
        $explicit = $this->loadFieldAllowlist(schemaSlug: $schemaSlug);
        $allowed  = self::DEFAULT_SAFE_FIELDS;

        if ($explicit !== null) {
            $allowed = array_merge($allowed, $explicit);
        }

        if ($calcs !== null) {
            foreach ($calcs as $name => $spec) {
                if (is_string($name) === false) {
                    continue;
                }

                if (is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
                    $allowed[] = $name;
                }
            }
        }

        return array_values(array_unique($allowed));
    }//end resolveAllowedFieldNames()

    /**
     * Load the explicit user-field allowlist from the schema configuration.
     *
     * @param string $schemaSlug Validated schema slug.
     *
     * @return list<string>|null List of allowed field names, or null when
     *                           the schema declares no allowlist.
     */
    private function loadFieldAllowlist(string $schemaSlug): ?array
    {
        try {
            $schemas = $this->schemaMapper->findBySlug(slug: $schemaSlug, limit: 1);
        } catch (Throwable) {
            return null;
        }

        if (count($schemas) === 0) {
            return null;
        }

        $config    = ($schemas[0]->getConfiguration() ?? []);
        $allowlist = ($config[self::FIELD_ALLOWLIST_KEY] ?? null);

        if (is_array($allowlist) === false) {
            return null;
        }

        $normalised = [];
        foreach ($allowlist as $entry) {
            if (is_string($entry) === true && $entry !== '') {
                $normalised[] = $entry;
            }
        }

        return $normalised;
    }//end loadFieldAllowlist()

    /**
     * Serialise a calculation result into a JSON-friendly value.
     *
     * @param mixed $value Raw evaluator output.
     *
     * @return mixed JSON-safe representation.
     */
    private function serialise(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }//end serialise()
}//end class
