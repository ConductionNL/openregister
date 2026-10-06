<?php

/**
 * AppHost Discovery Manifest
 *
 * The parsed `discovery` block of a leaf app's `src/manifest.json`: which
 * interoperability standards the app provides or consumes, which public entry
 * points it offers, and which Open Cloud Mesh resource types it shares.
 *
 * WHY A MANIFEST BLOCK AND NOT A CAPABILITY CLASS PER APP. An anonymous caller
 * of `/ocs/v2.php/cloud/capabilities` only receives capabilities implementing
 * `IPublicCapability`, so an app that wants to be found by another instance
 * has to publish one. Twenty apps each writing their own class would drift in
 * shape, and not every adopter calls `Bootstrap::register()`, so wiring it
 * there would silently miss some. One declarative block read by one engine
 * keeps the public shape identical across the fleet, and the same block feeds
 * the generated "Standards & federation" docs page, so the docs and the
 * capability cannot disagree.
 *
 * Parsing is FORGIVING PER ENTRY, unlike `StoreManifest` which disables the
 * whole block on a malformed field. A store that half-works is dangerous; a
 * discovery list missing one bad entry is merely shorter, and the rest of the
 * declaration is still true. Every dropped entry is reported through
 * `getProblems()` so the manifest validator and the logs can surface it.
 *
 * SAFE DEFAULTS. An entry that does not say how it is accessed is treated as
 * `authenticated`, and an authenticated entry never has its endpoint published.
 * Forgetting a field can therefore only hide a path, never expose one.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Discovery
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Discovery;

/**
 * Immutable, validated view of one app's `discovery` manifest block.
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */
class DiscoveryManifest {
	/**
	 * Version of the public payload shape. Bumped on an incompatible change so
	 * a remote instance can branch on it.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * Whether the app offers the standard or uses someone else's.
	 *
	 * @var array<int, string>
	 */
	public const ROLES = ['provides', 'consumes'];

	/**
	 * How a caller reaches the endpoint. Only `public` and `token` endpoints are
	 * published; `token` means "no Nextcloud login, but a credential the caller
	 * must already hold" (a ZGW JWT, a share token, an LTI launch).
	 *
	 * @var array<int, string>
	 */
	public const ACCESS = ['public', 'token', 'authenticated'];

	/**
	 * Access level assumed when an entry does not declare one.
	 *
	 * @var string
	 */
	public const DEFAULT_ACCESS = 'authenticated';

	/**
	 * Identifier grammar for standard ids, link names and OCM resource types.
	 *
	 * @var string
	 */
	private const SLUG = '/^[a-z0-9]+([.\-][a-z0-9]+)*$/';

	/**
	 * Constructor.
	 *
	 * @param string $appId Owning app id.
	 * @param bool $public Whether the app wants to be listed publicly.
	 * @param array<int, array<string, mixed>> $standards Validated standard entries.
	 * @param array<string, string> $links Validated public entry points.
	 * @param array<int, array<string, mixed>> $ocmResourceTypes Validated OCM resource types.
	 * @param array<int, string> $problems Why entries were dropped.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly bool $public = true,
		public readonly array $standards = [],
		public readonly array $links = [],
		public readonly array $ocmResourceTypes = [],
		private readonly array $problems = [],
	) {
	}//end __construct()

	/**
	 * Parse the `discovery` block out of a decoded manifest.
	 *
	 * A manifest without the block yields an empty, non-public manifest so the
	 * catalogue skips the app entirely rather than listing it with nothing.
	 *
	 * @param string $appId The app the manifest belongs to.
	 * @param array<string, mixed> $manifest The decoded `src/manifest.json`.
	 *
	 * @return self
	 */
	public static function fromManifest(string $appId, array $manifest): self {
		$block = ($manifest['discovery'] ?? null);
		if (is_array($block) === false) {
			return new self(appId: $appId, public: false);
		}

		$problems = [];
		$standards = [];
		foreach (self::listOf(value: ($block['standards'] ?? [])) as $index => $raw) {
			$entry = self::parseStandard(raw: $raw, problem: $problem);
			if ($entry === null) {
				$problems[] = sprintf('standards[%d]: %s', $index, $problem);
				continue;
			}

			$standards[] = $entry;
		}

		$links = [];
		foreach (self::mapOf(value: ($block['links'] ?? [])) as $name => $path) {
			if (is_string($name) === false || preg_match(self::SLUG, $name) !== 1) {
				$problems[] = sprintf('links.%s: name must be a lower-case slug', (string)$name);
				continue;
			}

			if (self::isLocalPath(value: $path) === false) {
				$problems[] = sprintf('links.%s: must be a path starting with "/" on this instance', $name);
				continue;
			}

			$links[$name] = $path;
		}

		$ocm = [];
		foreach (self::listOf(value: ($block['ocmResourceTypes'] ?? [])) as $index => $raw) {
			$type = self::parseOcmResourceType(raw: $raw, problem: $problem);
			if ($type === null) {
				$problems[] = sprintf('ocmResourceTypes[%d]: %s', $index, $problem);
				continue;
			}

			$ocm[] = $type;
		}

		return new self(
			appId: $appId,
			public: (($block['public'] ?? true) !== false),
			standards: $standards,
			links: $links,
			ocmResourceTypes: $ocm,
			problems: $problems,
		);
	}//end fromManifest()

	/**
	 * Whether the block declares anything worth publishing.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->standards === [] && $this->links === [] && $this->ocmResourceTypes === [];
	}//end isEmpty()

	/**
	 * Why entries were dropped while parsing, for logs and the manifest gate.
	 *
	 * @return array<int, string>
	 */
	public function getProblems(): array {
		return $this->problems;
	}//end getProblems()

	/**
	 * The shape published to anonymous callers under `<appId>.discovery`.
	 *
	 * Endpoints of `authenticated` entries are left out on purpose: the entry
	 * still says the app speaks the standard, which is what a peer needs to
	 * decide whether a connection is possible, without handing out a map of
	 * login-only routes.
	 *
	 * @return array<string, mixed>
	 */
	public function toPublicPayload(): array {
		$standards = [];
		foreach ($this->standards as $entry) {
			if ($entry['access'] === 'authenticated') {
				unset($entry['endpoint']);
			}

			$standards[] = $entry;
		}

		$payload = [
			'contractVersion' => self::CONTRACT_VERSION,
			'standards' => $standards,
		];
		if ($this->links !== []) {
			$payload['links'] = $this->links;
		}

		if ($this->ocmResourceTypes !== []) {
			$payload['ocm'] = array_values(array_map(static fn (array $type): string => $type['name'], $this->ocmResourceTypes));
		}

		return $payload;
	}//end toPublicPayload()

	/**
	 * Validate one `standards[]` entry.
	 *
	 * @param mixed $raw The raw entry.
	 * @param string|null $problem Set to the reason when the entry is rejected.
	 *
	 * @return array<string, mixed>|null The normalised entry, or null when invalid.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One guard per field keeps each rejection reason explicit.
	 */
	private static function parseStandard(mixed $raw, ?string &$problem): ?array {
		$problem = null;
		if (is_array($raw) === false) {
			$problem = 'entry must be an object';
			return null;
		}

		$id = ($raw['id'] ?? null);
		if (is_string($id) === false || preg_match(self::SLUG, $id) !== 1) {
			$problem = 'id must be a lower-case slug such as "zgw-zaken" or "ori"';
			return null;
		}

		$name = ($raw['name'] ?? null);
		if (is_string($name) === false || trim($name) === '') {
			$problem = sprintf('%s: name is required', $id);
			return null;
		}

		$role = ($raw['role'] ?? null);
		if (in_array($role, self::ROLES, true) === false) {
			$problem = sprintf('%s: role must be one of %s', $id, implode(', ', self::ROLES));
			return null;
		}

		$access = ($raw['access'] ?? self::DEFAULT_ACCESS);
		if (in_array($access, self::ACCESS, true) === false) {
			$problem = sprintf('%s: access must be one of %s', $id, implode(', ', self::ACCESS));
			return null;
		}

		$entry = ['id' => $id, 'name' => trim($name), 'role' => $role, 'access' => $access];

		if (isset($raw['version']) === true) {
			if (is_string($raw['version']) === false || $raw['version'] === '') {
				$problem = sprintf('%s: version must be a non-empty string', $id);
				return null;
			}

			$entry['version'] = $raw['version'];
		}

		if (isset($raw['endpoint']) === true) {
			if (self::isLocalPath(value: $raw['endpoint']) === false) {
				$problem = sprintf('%s: endpoint must be a path starting with "/" on this instance', $id);
				return null;
			}

			$entry['endpoint'] = $raw['endpoint'];
		}

		if (isset($raw['specUrl']) === true) {
			if (is_string($raw['specUrl']) === false || str_starts_with($raw['specUrl'], 'https://') === false
				|| filter_var($raw['specUrl'], FILTER_VALIDATE_URL) === false
			) {
				$problem = sprintf('%s: specUrl must be an https URL', $id);
				return null;
			}

			$entry['specUrl'] = $raw['specUrl'];
		}

		return $entry;
	}//end parseStandard()

	/**
	 * Validate one `ocmResourceTypes[]` entry.
	 *
	 * @param mixed $raw The raw entry.
	 * @param string|null $problem Set to the reason when the entry is rejected.
	 *
	 * @return array<string, mixed>|null The normalised entry, or null when invalid.
	 */
	private static function parseOcmResourceType(mixed $raw, ?string &$problem): ?array {
		$problem = null;
		if (is_array($raw) === false) {
			$problem = 'entry must be an object';
			return null;
		}

		$name = ($raw['name'] ?? null);
		if (is_string($name) === false || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) !== 1) {
			$problem = 'name must be a lower-case slug such as "dossiq-case"';
			return null;
		}

		$shareTypes = array_values(array_filter(
			self::listOf(value: ($raw['shareTypes'] ?? [])),
			static fn (mixed $type): bool => is_string($type) && preg_match('/^[a-z]+$/', $type) === 1
		));
		if ($shareTypes === []) {
			$problem = sprintf('%s: shareTypes must list at least one recipient type, e.g. "user"', $name);
			return null;
		}

		$protocols = [];
		foreach (self::mapOf(value: ($raw['protocols'] ?? [])) as $protocol => $path) {
			if (is_string($protocol) === false || preg_match(self::SLUG, $protocol) !== 1 || self::isLocalPath(value: $path) === false) {
				$problem = sprintf('%s: protocols must map a slug to a path starting with "/"', $name);
				return null;
			}

			$protocols[$protocol] = $path;
		}

		if ($protocols === []) {
			$problem = sprintf('%s: protocols must declare at least one protocol', $name);
			return null;
		}

		return ['name' => $name, 'shareTypes' => $shareTypes, 'protocols' => $protocols];
	}//end parseOcmResourceType()

	/**
	 * Whether a value is a path on this instance (never a URL, never a traversal).
	 *
	 * Paths, not URLs: an app must not be able to make this instance advertise
	 * somebody else's host, and the caller already knows ours.
	 *
	 * @param mixed $value The candidate path.
	 *
	 * @return bool
	 */
	private static function isLocalPath(mixed $value): bool {
		return is_string($value)
			&& str_starts_with($value, '/')
			&& str_starts_with($value, '//') === false
			&& str_contains($value, '..') === false
			&& str_contains($value, '://') === false
			&& preg_match('/[\s\\\\]/', $value) !== 1;
	}//end isLocalPath()

	/**
	 * Coerce a manifest value to an object (map), dropping it when it is not one.
	 *
	 * @param mixed $value The candidate map.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function mapOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end mapOf()

	/**
	 * Coerce a manifest value to a list, dropping it when it is not one.
	 *
	 * @param mixed $value The candidate list.
	 *
	 * @return array<int, mixed>
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false || array_is_list($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()
}//end class
