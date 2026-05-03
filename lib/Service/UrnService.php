<?php

/**
 * OpenRegister UrnService
 *
 * Generates, parses, and resolves RFC 8141 URN identifiers for
 * register objects. The URN format is system-independent and stable
 * across instance migrations: `urn:nl-or:{instance}:{register}:{schema}:{uuid}`.
 * Resolution is bidirectional — URN → API URL and URL → URN.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * RFC 8141-compliant URN identifiers for OpenRegister objects.
 *
 * Default URN shape:
 *   `urn:nl-or:{instance-slug}:{register-slug}:{schema-slug}:{uuid}`
 *
 * The `nl-or` NID (Namespace Identifier) is an informal namespace used
 * by OpenRegister deployments. Per RFC 8141 §5.1 informal NIDs are
 * permitted; a formal IANA registration is a follow-up if/when this
 * goes wider than Conduction deployments.
 *
 * The `instance-slug` defaults to a sanitised hostname from the configured
 * `overwrite.cli.url` (or `localhost` in dev). It can be overridden per-app
 * via `IAppConfig` key `openregister.urn_instance` so federated instances
 * can advertise a stable identifier independent of their public URL.
 */
class UrnService
{

    /**
     * Default URN namespace identifier (NID).
     *
     * Informal NID per RFC 8141 §5.1. Replace with a registered NID when
     * formal IANA registration lands.
     */
    public const DEFAULT_NID = 'nl-or';

    /**
     * Per-app config key for the URN instance identifier.
     *
     * Override the default (auto-derived from `overwrite.cli.url` host)
     * by setting:
     *
     *     occ config:app:set openregister urn_instance --value="my-stable-id"
     */
    public const APP_CONFIG_INSTANCE = 'urn_instance';

    /**
     * RFC 8141 URN regex (anchored).
     *
     * Captures: 1=NID, 2=NSS (the colon-separated rest).
     * NID rules: alphanum, hyphens, length 2-32.
     */
    private const URN_REGEX = '/^urn:([a-zA-Z0-9][a-zA-Z0-9-]{1,31}):(.+)$/i';

    /**
     * Constructor.
     *
     * @param RegisterMapper  $registerMapper The register mapper.
     * @param SchemaMapper    $schemaMapper   The schema mapper.
     * @param IURLGenerator   $urlGenerator   The URL generator.
     * @param IAppConfig      $appConfig      The app configuration.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Build the URN for a given object.
     *
     * Returns null if the object lacks the identity needed (uuid +
     * register + schema). The result is RFC 8141-compliant.
     *
     * Example:
     *   `urn:nl-or:nextcloud-example-com:decidesk:meeting:1c1c970f-d50c-4943-8128-78999e240eec`
     *
     * @param ObjectEntity $object The object to build the URN for.
     *
     * @return string|null The URN, or null when identity is incomplete.
     */
    public function buildForObject(ObjectEntity $object): ?string
    {
        $uuid = $object->getUuid();
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $registerSlug = $this->resolveRegisterSlug(registerRef: $object->getRegister());
        $schemaSlug   = $this->resolveSchemaSlug(schemaRef: $object->getSchema());
        if ($registerSlug === null || $schemaSlug === null) {
            return null;
        }

        return $this->build(
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            uuid: $uuid
        );
    }//end buildForObject()

    /**
     * Build a URN from its three identifying parts.
     *
     * Lower-cases each component (URN comparison is case-insensitive
     * per RFC 8141 §3, but emitting in a single canonical case avoids
     * mismatch surprises in caches).
     *
     * @param string $registerSlug The register slug.
     * @param string $schemaSlug   The schema slug.
     * @param string $uuid         The object UUID.
     *
     * @return string The constructed URN.
     */
    public function build(string $registerSlug, string $schemaSlug, string $uuid): string
    {
        return sprintf(
            'urn:%s:%s:%s:%s:%s',
            self::DEFAULT_NID,
            $this->getInstanceSlug(),
            strtolower($registerSlug),
            strtolower($schemaSlug),
            strtolower($uuid)
        );
    }//end build()

    /**
     * Parse a URN string into its components.
     *
     * Returns null when the URN does not match the OpenRegister shape
     * (wrong NID, missing parts). Cross-instance URNs (different
     * `instance-slug`) parse successfully — federation resolution is
     * a separate concern and lives in v1.1.
     *
     * @param string $urn The URN to parse.
     *
     * @return array{instance: string, register: string, schema: string, uuid: string}|null
     */
    public function parse(string $urn): ?array
    {
        // RFC 8141 §3: leading "urn:" is case-insensitive.
        if (preg_match(self::URN_REGEX, $urn, $matches) !== 1) {
            return null;
        }

        $nid = strtolower($matches[1]);
        $nss = $matches[2];

        if ($nid !== self::DEFAULT_NID) {
            return null;
        }

        $parts = explode(':', $nss);
        if (count($parts) < 4) {
            return null;
        }

        // Last part is uuid; preceding three are instance/register/schema.
        // Slugs may contain hyphens but never colons, so explode(':')
        // gives a clean partition.
        return [
            'instance' => $parts[0],
            'register' => $parts[1],
            'schema'   => $parts[2],
            'uuid'     => $parts[3],
        ];
    }//end parse()

    /**
     * Resolve a URN to the canonical API URL of the underlying object.
     *
     * Returns null when:
     *   - the URN doesn't parse
     *   - the URN belongs to a different instance (federation = v1.1)
     *   - the register/schema can't be looked up
     *
     * The returned URL is the standard read-by-uuid REST endpoint
     * `/apps/openregister/api/objects/{register}/{schema}/{uuid}` —
     * same shape that `ObjectReferenceProvider` (Smart Picker) matches.
     *
     * @param string $urn The URN to resolve.
     *
     * @return string|null The absolute API URL, or null when not resolvable.
     */
    public function resolveUrl(string $urn): ?string
    {
        $parts = $this->parse(urn: $urn);
        if ($parts === null) {
            return null;
        }

        // Cross-instance URN — resolution is out of scope for v1.
        if ($parts['instance'] !== $this->getInstanceSlug()) {
            return null;
        }

        // Resolve register + schema by slug to confirm they exist.
        try {
            $register = $this->findRegister(ref: $parts['register']);
            $schema   = $this->findSchema(ref: $parts['schema']);
        } catch (\Throwable $e) {
            return null;
        }

        if ($register === null || $schema === null) {
            return null;
        }

        return $this->urlGenerator->getAbsoluteURL(
            sprintf(
                '/apps/openregister/api/objects/%s/%s/%s',
                rawurlencode((string) $register->getSlug()),
                rawurlencode((string) $schema->getSlug()),
                rawurlencode($parts['uuid'])
            )
        );
    }//end resolveUrl()

    /**
     * Reverse: extract a URN from an OpenRegister object URL.
     *
     * Accepts the same URL shapes the Smart Picker reference provider
     * accepts. Returns null when the URL doesn't match.
     *
     * @param string $url The OpenRegister object URL.
     *
     * @return string|null The URN, or null when the URL is not a valid OR object reference.
     */
    public function urnFromUrl(string $url): ?string
    {
        $base        = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
        $escapedBase = preg_quote($base, '/');
        $uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        // Three URL shapes (mirrors ObjectReferenceProvider):
        // 1. Hash-routed: /apps/openregister/#/registers/{id}/schemas/{id}/objects/{uuid}.
        // 2. API:         /apps/openregister/api/objects/{registerId|slug}/{schemaId|slug}/{uuid}.
        // 3. Direct:      /apps/openregister/objects/{registerId|slug}/{schemaId|slug}/{uuid}.
        $tail = sprintf('([\w-]+)\/([\w-]+)\/(%s)$/i', $uuidPattern);
        $hash = sprintf(
            '/^%s(?:\/index\.php)?\/apps\/openregister\/#\/registers\/([\w-]+)\/schemas\/([\w-]+)\/objects\/(%s)$/i',
            $escapedBase,
            $uuidPattern
        );
        $api  = sprintf('/^%s(?:\/index\.php)?\/apps\/openregister\/api\/objects\/%s', $escapedBase, $tail);
        $dir  = sprintf('/^%s(?:\/index\.php)?\/apps\/openregister\/objects\/%s', $escapedBase, $tail);

        $patterns = [
            $hash,
            $api,
            $dir,
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                $registerRef = $matches[1];
                $schemaRef   = $matches[2];
                $uuid        = $matches[3];

                // Resolve numeric ids to slugs (URLs may carry either).
                try {
                    $register = $this->findRegister(ref: $registerRef);
                    $schema   = $this->findSchema(ref: $schemaRef);
                } catch (\Throwable $e) {
                    return null;
                }

                if ($register === null || $schema === null) {
                    return null;
                }

                return $this->build(
                    registerSlug: (string) $register->getSlug(),
                    schemaSlug: (string) $schema->getSlug(),
                    uuid: $uuid
                );
            }//end if
        }//end foreach

        return null;
    }//end urnFromUrl()

    /**
     * Bulk-resolve a list of URNs to their canonical URLs.
     *
     * Unresolved URNs map to `null` in the returned array — the input
     * order is preserved as the array key shape `urn => url|null`.
     *
     * @param array<int, string> $urns The list of URNs to resolve.
     *
     * @return array<string, ?string> Map of urn → url-or-null.
     */
    public function resolveBulk(array $urns): array
    {
        $out = [];
        foreach ($urns as $urn) {
            if (is_string($urn) === false || $urn === '') {
                continue;
            }

            $out[$urn] = $this->resolveUrl(urn: $urn);
        }

        return $out;
    }//end resolveBulk()

    /**
     * Get the instance slug used in URN construction.
     *
     * Resolution order:
     *   1. App config `openregister.urn_instance` (operator override).
     *   2. Sanitised host portion of `overwrite.cli.url`.
     *   3. Fallback: `localhost`.
     *
     * @return string The instance slug.
     */
    public function getInstanceSlug(): string
    {
        try {
            $configured = (string) $this->appConfig->getValueString(
                'openregister',
                self::APP_CONFIG_INSTANCE,
                ''
            );
        } catch (\Throwable $e) {
            $configured = '';
        }

        if ($configured !== '') {
            return $this->sanitiseSlug(value: $configured);
        }

        try {
            $base = $this->urlGenerator->getAbsoluteURL('/');
            $host = parse_url($base, PHP_URL_HOST);
            if ($host === false || $host === null || $host === '') {
                $host = 'localhost';
            }
        } catch (\Throwable $e) {
            $host = 'localhost';
        }

        return $this->sanitiseSlug(value: $host);
    }//end getInstanceSlug()

    /**
     * Lower-case + replace non-alnum with hyphens for slug-safe use inside the URN body.
     *
     * @param string $value The raw value to sanitise.
     *
     * @return string The sanitised slug.
     */
    private function sanitiseSlug(string $value): string
    {
        $slug = strtolower($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }//end sanitiseSlug()

    /**
     * Resolve the register slug for a register reference.
     *
     * @param string|null $registerRef The register id/uuid/slug.
     *
     * @return string|null The slug, or null when not resolvable.
     */
    private function resolveRegisterSlug(?string $registerRef): ?string
    {
        if ($registerRef === null || $registerRef === '') {
            return null;
        }

        $register = $this->findRegister(ref: $registerRef);
        return $register?->getSlug();
    }//end resolveRegisterSlug()

    /**
     * Resolve the schema slug for a schema reference.
     *
     * @param string|null $schemaRef The schema id/uuid/slug.
     *
     * @return string|null The slug, or null when not resolvable.
     */
    private function resolveSchemaSlug(?string $schemaRef): ?string
    {
        if ($schemaRef === null || $schemaRef === '') {
            return null;
        }

        $schema = $this->findSchema(ref: $schemaRef);
        return $schema?->getSlug();
    }//end resolveSchemaSlug()

    /**
     * Find a register by id, uuid, or slug. Returns null on miss.
     *
     * @param string $ref The register reference.
     *
     * @return Register|null The register entity, or null on miss.
     */
    private function findRegister(string $ref): ?Register
    {
        try {
            return $this->registerMapper->find($ref, _rbac: false, _multitenancy: false);
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[UrnService] register lookup failed for "%s": %s', $ref, $e->getMessage())
            );
            return null;
        }
    }//end findRegister()

    /**
     * Find a schema by id, uuid, or slug. Returns null on miss.
     *
     * @param string $ref The schema reference.
     *
     * @return Schema|null The schema entity, or null on miss.
     */
    private function findSchema(string $ref): ?Schema
    {
        try {
            return $this->schemaMapper->find($ref, _rbac: false, _multitenancy: false);
        } catch (DoesNotExistException $e) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[UrnService] schema lookup failed for "%s": %s', $ref, $e->getMessage())
            );
            return null;
        }
    }//end findSchema()
}//end class
