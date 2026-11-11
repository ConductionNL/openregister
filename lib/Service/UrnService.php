<?php

/**
 * OpenRegister UrnService
 *
 * Generates, parses, and resolves RFC 8141 URN identifiers for
 * register objects. The URN format is system-independent and stable
 * across instance migrations: `urn:nl-or:{instance}:{register}:{schema}:{uuid}`.
 * Resolution is bidirectional — URN → API URL and URL → URN.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
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
class UrnService implements UrnServiceInterface {

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
	 * @param SchemaCatalogInterface $catalog The register and schema mappers.
	 * @param IURLGenerator $urlGenerator The URL generator.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-1
	 */
	public function buildForObject(ObjectEntity $object): ?string {
		$uuid = (string)$object->getUuid();
		if ($uuid === '') {
			return null;
		}

		$registerSlug = $this->resolveRegisterSlug(registerRef: $object->getRegister());
		$schemaSlug = $this->resolveSchemaSlug(schemaRef: $object->getSchema());
		if ($registerSlug === null || $schemaSlug === null) {
			return null;
		}

		return $this->buildFor(ref: new ObjectRef(register: $registerSlug, schema: $schemaSlug, id: $uuid));
	}//end buildForObject()

	/**
	 * Build a URN from its three identifying parts.
	 *
	 * Lower-cases each component (URN comparison is case-insensitive
	 * per RFC 8141 §3, but emitting in a single canonical case avoids
	 * mismatch surprises in caches).
	 *
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug The schema slug.
	 * @param string $uuid The object UUID.
	 *
	 * @return string The constructed URN.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-1
	 */
	public function build(string $registerSlug, string $schemaSlug, string $uuid): string {
		return $this->buildFor(ref: new ObjectRef(register: $registerSlug, schema: $schemaSlug, id: $uuid));
	}//end build()

	/**
	 * Build a URN from the register slug, schema slug and uuid of an object.
	 *
	 * @param ObjectRef $ref The register slug, schema slug and object UUID.
	 *
	 * @return string The URN.
	 *
	 * @spec openspec/specs/urn-resource-addressing/spec.md
	 */
	public function buildFor(ObjectRef $ref): string {
		return sprintf(
			'urn:%s:%s:%s:%s:%s',
			self::DEFAULT_NID,
			$this->getInstanceSlug(),
			strtolower((string)$ref->register),
			strtolower((string)$ref->schema),
			strtolower($ref->id)
		);
	}//end buildFor()

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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-2
	 */
	public function parse(string $urn): ?array {
		$nss = $this->openRegisterNss(urn: $urn);
		if ($nss === null) {
			return null;
		}

		// Slugs may contain hyphens but never colons, so explode(':') gives a
		// clean partition: instance, register, schema, then the uuid.
		$parts = explode(':', $nss);
		if (count($parts) < 4) {
			return null;
		}

		return array_combine(['instance', 'register', 'schema', 'uuid'], array_slice($parts, 0, 4));
	}//end parse()

	/**
	 * The namespace-specific string of an `nl-or` URN, or null for any other string.
	 *
	 * RFC 8141 §3: the leading "urn:" and the NID are case-insensitive.
	 *
	 * @param string $urn The URN to parse.
	 *
	 * @return string|null
	 */
	private function openRegisterNss(string $urn): ?string {
		if (preg_match(self::URN_REGEX, $urn, $matches) !== 1) {
			return null;
		}

		if (strtolower($matches[1]) !== self::DEFAULT_NID) {
			return null;
		}

		return $matches[2];
	}//end openRegisterNss()

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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-2
	 */
	public function resolveUrl(string $urn): ?string {
		$parts = $this->parse(urn: $urn);
		// A cross-instance URN — resolution is out of scope for v1.
		if ($parts === null || $parts['instance'] !== $this->getInstanceSlug()) {
			return null;
		}

		$slugs = $this->resolvedSlugs(registerRef: $parts['register'], schemaRef: $parts['schema']);
		if ($slugs === null) {
			return null;
		}

		return $this->objectApiUrl(slugs: $slugs, uuid: $parts['uuid']);
	}//end resolveUrl()

	/**
	 * The absolute read-by-uuid REST url of an object.
	 *
	 * @param array{register: string, schema: string} $slugs The register and schema slugs.
	 * @param string $uuid The object uuid.
	 *
	 * @return string
	 */
	private function objectApiUrl(array $slugs, string $uuid): string {
		return $this->urlGenerator->getAbsoluteURL(
			sprintf(
				'/apps/openregister/api/objects/%s/%s/%s',
				rawurlencode($slugs['register']),
				rawurlencode($slugs['schema']),
				rawurlencode($uuid)
			)
		);
	}//end objectApiUrl()

	/**
	 * Resolve a register and a schema reference (id, uuid or slug) to their slugs.
	 *
	 * @param string $registerRef The register reference.
	 * @param string $schemaRef The schema reference.
	 *
	 * @return array{register: string, schema: string}|null Null when either does not resolve.
	 */
	private function resolvedSlugs(string $registerRef, string $schemaRef): ?array {
		$register = $this->findRegister(ref: $registerRef);
		$schema = $this->findSchema(ref: $schemaRef);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => (string)$register->getSlug(), 'schema' => (string)$schema->getSlug()];
	}//end resolvedSlugs()

	/**
	 * Reverse: extract a URN from an OpenRegister object URL.
	 *
	 * Accepts the same URL shapes the Smart Picker reference provider
	 * accepts. Returns null when the URL doesn't match.
	 *
	 * @param string $url The OpenRegister object URL.
	 *
	 * @return string|null The URN, or null when the URL is not a valid OR object reference.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-2
	 */
	public function urnFromUrl(string $url): ?string {
		foreach ($this->objectUrlPatterns() as $pattern) {
			if (preg_match($pattern, $url, $matches) === 1) {
				return $this->urnFromRefs(matches: $matches);
			}
		}

		return null;
	}//end urnFromUrl()

	/**
	 * The three OpenRegister object URL shapes (mirrors ObjectReferenceProvider).
	 *
	 * 1. Hash-routed: /apps/openregister/#/registers/{id}/schemas/{id}/objects/{uuid}.
	 * 2. API:         /apps/openregister/api/objects/{registerId|slug}/{schemaId|slug}/{uuid}.
	 * 3. Direct:      /apps/openregister/objects/{registerId|slug}/{schemaId|slug}/{uuid}.
	 *
	 * Each captures 1=register ref, 2=schema ref, 3=uuid.
	 *
	 * @return array<int, string> The regex patterns, in match order.
	 */
	private function objectUrlPatterns(): array {
		$escapedBase = preg_quote(rtrim($this->urlGenerator->getAbsoluteURL('/'), '/'), '/');
		$uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
		$tail = sprintf('([\w-]+)\/([\w-]+)\/(%s)$/i', $uuidPattern);
		$hash = sprintf(
			'/^%s(?:\/index\.php)?\/apps\/openregister\/#\/registers\/([\w-]+)\/schemas\/([\w-]+)\/objects\/(%s)$/i',
			$escapedBase,
			$uuidPattern
		);
		$api = sprintf('/^%s(?:\/index\.php)?\/apps\/openregister\/api\/objects\/%s', $escapedBase, $tail);
		$dir = sprintf('/^%s(?:\/index\.php)?\/apps\/openregister\/objects\/%s', $escapedBase, $tail);

		return [$hash, $api, $dir];
	}//end objectUrlPatterns()

	/**
	 * Build the URN for matched URL references, resolving numeric ids to slugs
	 * (URLs may carry either).
	 *
	 * @param array<int, string> $matches The pattern captures: 1=register, 2=schema, 3=uuid.
	 *
	 * @return string|null The URN, or null when the register or schema does not resolve.
	 */
	private function urnFromRefs(array $matches): ?string {
		$slugs = $this->resolvedSlugs(registerRef: $matches[1], schemaRef: $matches[2]);
		if ($slugs === null) {
			return null;
		}

		return $this->buildFor(ref: new ObjectRef(register: $slugs['register'], schema: $slugs['schema'], id: $matches[3]));
	}//end urnFromRefs()

	/**
	 * Bulk-resolve a list of URNs to their canonical URLs.
	 *
	 * Unresolved URNs map to `null` in the returned array — the input
	 * order is preserved as the array key shape `urn => url|null`.
	 *
	 * @param array<int, string> $urns The list of URNs to resolve.
	 *
	 * @return array<string, ?string> Map of urn → url-or-null.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-2
	 */
	public function resolveBulk(array $urns): array {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-1
	 */
	public function getInstanceSlug(): string {
		$configured = $this->configuredInstance();
		if ($configured !== '') {
			return $this->sanitiseSlug(value: $configured);
		}

		return $this->sanitiseSlug(value: $this->baseUrlHost());
	}//end getInstanceSlug()

	/**
	 * The operator's `openregister.urn_instance` override, empty when unset or unreadable.
	 *
	 * @return string
	 */
	private function configuredInstance(): string {
		try {
			return (string)$this->appConfig->getValueString('openregister', self::APP_CONFIG_INSTANCE, '');
		} catch (\Throwable $e) {
			return '';
		}
	}//end configuredInstance()

	/**
	 * The host of the instance's absolute base url, `localhost` when there is none.
	 *
	 * @return string
	 */
	private function baseUrlHost(): string {
		try {
			$host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);
		} catch (\Throwable $e) {
			return 'localhost';
		}

		if (is_string($host) === false || $host === '') {
			return 'localhost';
		}

		return $host;
	}//end baseUrlHost()

	/**
	 * Lower-case + replace non-alnum with hyphens for slug-safe use inside the URN body.
	 *
	 * @param string $value The raw value to sanitise.
	 *
	 * @return string The sanitised slug.
	 */
	private function sanitiseSlug(string $value): string {
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
	private function resolveRegisterSlug(?string $registerRef): ?string {
		if ((string)$registerRef === '') {
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
	private function resolveSchemaSlug(?string $schemaRef): ?string {
		if ((string)$schemaRef === '') {
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
	private function findRegister(string $ref): ?Register {
		return $this->quietly(
			failure: sprintf('register lookup failed for "%s"', $ref),
			lookup: fn (): Register => $this->catalog->registers()->find($ref, _rbac: false, _multitenancy: false)
		);
	}//end findRegister()

	/**
	 * Find a schema by id, uuid, or slug. Returns null on miss.
	 *
	 * @param string $ref The schema reference.
	 *
	 * @return Schema|null The schema entity, or null on miss.
	 */
	private function findSchema(string $ref): ?Schema {
		return $this->quietly(
			failure: sprintf('schema lookup failed for "%s"', $ref),
			lookup: fn (): Schema => $this->catalog->schemas()->find($ref, _rbac: false, _multitenancy: false)
		);
	}//end findSchema()

	/**
	 * Run a lookup, null on a miss; any other failure is logged at debug and also yields null.
	 *
	 * @param string $failure The debug message prefix for a failed lookup.
	 * @param \Closure(): T $lookup The lookup.
	 *
	 * @template T of object
	 *
	 * @return T|null
	 */
	private function quietly(string $failure, \Closure $lookup): ?object {
		try {
			return $lookup();
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug('[UrnService] ' . $failure . ': ' . $e->getMessage());
			return null;
		}
	}//end quietly()
}//end class
