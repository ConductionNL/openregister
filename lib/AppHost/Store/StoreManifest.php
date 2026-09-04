<?php

/**
 * OpenRegister AppHost — Store Manifest
 *
 * The `store` block of a leaf app's `src/manifest.json`, parsed into a value
 * object. This is what makes a store surface DATA rather than code: an
 * adopting app declares which remote schema its registry serves, which of its
 * own schemas an install may write, and which items it ships itself. It writes
 * no controller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

/**
 * A leaf app's declarative store configuration.
 *
 * 🔴 `installable` IS A SECURITY BOUNDARY, NOT A CONVENIENCE.
 *
 * It is the allowlist of schema slugs a store item may write into this
 * instance. A registry is somebody else's server: without the allowlist, an
 * item could declare a component naming ANY schema the app owns and have it
 * written. dossiq's hand-written version of this refused every record schema
 * and permitted only case CONFIGURATION, which is the shape every app wants.
 *
 * An EMPTY allowlist therefore means "install nothing", never "install
 * anything". `isInstallable()` returns false for every slug when the list is
 * empty, so an app that declares a store and forgets the allowlist gets a
 * browsable store whose installs are all refused, rather than an open door.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
class StoreManifest {
	/**
	 * Card fields used when an app declares none. These are the field names
	 * the fleet's registries already publish (ADR-080 Decision 5).
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_CARD_FIELDS = [
		'slug' => 'slug',
		'title' => 'title',
		'description' => 'description',
		'kind' => 'kind',
		'category' => 'category',
		'version' => 'version',
		'publisher' => 'publisher',
	];

	/**
	 * Discovery sources a store may declare.
	 *
	 * `openregister` reads another OpenRegister's objects endpoint at an
	 * admin-configured URL, which is why that path is SSRF-guarded and refuses
	 * redirects. `github` searches the repository API by topic at a COMPILE-TIME
	 * host, so there is no URL for an app to influence and nothing to guard.
	 *
	 * An unknown value is malformed, never a fallback: defaulting to
	 * `openregister` would silently point an app at a registry it never asked
	 * for.
	 *
	 * @var array<int, string>
	 */
	public const SOURCES = ['openregister', 'github'];

	/**
	 * Install postures a store may declare.
	 *
	 * `admin` is an instance administrator. `authenticated` is any signed-in
	 * user — hermiq and buildiq both rely on it, hermiq because the install
	 * lands quarantined and the gate that matters is approval. `action:<name>`
	 * defers to ADR-023, which is integriq's posture.
	 *
	 * 🔴 THIS DECIDES WHO, NEVER WHAT. `installable` remains the security
	 * boundary: an `authenticated` install refuses every schema the allowlist
	 * omits, exactly as an admin install does. The two keys sit side by side and
	 * read like a pair; they are not one.
	 *
	 * @var array<int, string>
	 */
	public const INSTALL_AUTH = ['admin', 'authenticated'];

	/**
	 * Prefix of the ADR-023 action posture.
	 */
	public const INSTALL_AUTH_ACTION_PREFIX = 'action:';

	/**
	 * The posture assumed when a block declares none.
	 *
	 * Deliberately the STRICTEST of them. A block that says nothing gets the
	 * gate it had before the key existed.
	 */
	public const DEFAULT_INSTALL_AUTH = 'admin';

	/**
	 * The source assumed when a block declares none.
	 *
	 * Every store declared before the discriminator existed keeps its behaviour
	 * exactly, which is the only reason this default may exist at all.
	 */
	public const DEFAULT_SOURCE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param string                     $appId       The calling leaf app id.
	 * @param bool                       $enabled     Whether the app declared a store block at all.
	 * @param string                     $schema      Remote schema slug the registry serves.
	 * @param string                     $register      Default remote register slug.
	 * @param string                     $localRegister Local register an install writes into.
	 * @param array<int, string>         $installable Schema slugs an install may write.
	 * @param array<int, string>         $kinds       Kind quick-filters offered on the page.
	 * @param array<string, string>      $cardFields  Remote field to card field map.
	 * @param array<int, array<string, mixed>> $builtIn The app's own items, for the no-registry case.
	 * @param array<int, string>         $types       Shareable configuration type ids this store surfaces.
	 * @param string                     $source      Discovery source: one of self::SOURCES.
	 * @param array<int, string>         $topics      GitHub topics searched when $source is `github`.
	 * @param string                     $installAuth Who may install: see self::INSTALL_AUTH.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) This is a value object
	 * mirroring the manifest's `store` block one key to one parameter, so the
	 * count is the block's, not a design choice. Grouping them into sub-objects
	 * would put a translation layer between what an app declares and what the
	 * engine reads, which is exactly where a silently dropped key hides.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly bool $enabled,
		public readonly string $schema = '',
		public readonly string $register = '',
		public readonly string $localRegister = '',
		public readonly array $installable = [],
		public readonly array $kinds = [],
		public readonly array $cardFields = self::DEFAULT_CARD_FIELDS,
		public readonly array $builtIn = [],
		public readonly array $types = [],
		public readonly string $source = self::DEFAULT_SOURCE,
		public readonly array $topics = [],
		public readonly string $installAuth = self::DEFAULT_INSTALL_AUTH,
	) {
	}//end __construct()

	/**
	 * Parse the `store` block out of a leaf app's manifest.
	 *
	 * An absent or malformed block yields a DISABLED manifest rather than a
	 * default-enabled one. A store that nobody declared must not appear: the
	 * word Store promises a registry (ADR-080 Decision 4), and inventing one
	 * from defaults would promise it on an app's behalf.
	 *
	 * @param string               $appId    The calling leaf app id.
	 * @param array<string, mixed> $manifest The decoded src/manifest.json.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public static function fromManifest(string $appId, array $manifest): self {
		$block = ($manifest['store'] ?? null);
		if (is_array($block) === false) {
			return new self(appId: $appId, enabled: false);
		}

		// An unknown source is MALFORMED, not a fallback. Reporting it as
		// `openregister` would point the app at a registry it never declared, and
		// the store would then answer `not_configured` for a reason that has
		// nothing to do with what the app got wrong.
		$source = (string)($block['source'] ?? self::DEFAULT_SOURCE);
		if (in_array(needle: $source, haystack: self::SOURCES, strict: true) === false) {
			return new self(appId: $appId, enabled: false);
		}

		// A github store is configured by its TOPICS, not by a registry URL, so
		// declaring none leaves it with nothing to search. That is a malformed
		// block rather than an empty store: an empty store is a store that found
		// nothing, which is a different thing to report.
		// An unrecognised posture DISABLES the block. Falling back to `admin`
		// would silently remove a capability from an app that asked for a weaker
		// gate: the store still works, for fewer people, for no stated reason,
		// which is harder to notice than an outright refusal.
		$installAuth = (string)($block['installAuth'] ?? self::DEFAULT_INSTALL_AUTH);
		if (self::isKnownInstallAuth(value: $installAuth) === false) {
			return new self(appId: $appId, enabled: false);
		}

		$topics = self::stringList(value: ($block['topics'] ?? []));
		if ($source === 'github' && $topics === []) {
			return new self(appId: $appId, enabled: false);
		}

		$cardFields = ($block['cardFields'] ?? null);
		if (is_array($cardFields) === false || $cardFields === []) {
			$cardFields = self::DEFAULT_CARD_FIELDS;
		}

		return new self(
			appId: $appId,
			enabled: true,
			schema: (string)($block['schema'] ?? ''),
			register: (string)($block['register'] ?? ''),
			// 🔴 SCOPE THE LOCAL WRITE BY REGISTER. A schema slug is NOT unique
			// across the fleet — `case`, `task` and `document` exist in several
			// apps at once — so resolving an install target by slug alone can
			// land in another app's register. Defaults to the app id, which is
			// the fleet's register-slug convention, and an app whose register
			// slug differs from its app id says so here.
			localRegister: (string)($block['localRegister'] ?? $appId),
			installable: self::stringList(value: ($block['installable'] ?? [])),
			// Declared ONCE, beside the allowlist, and served to the page in the
			// search response. An app that names its kinds here and nowhere else
			// must get them: a schema key nothing reads is the silent-no-op this
			// whole plane is built to avoid.
			kinds: self::stringList(value: ($block['kinds'] ?? [])),
			cardFields: array_map(callback: static fn ($v): string => (string)$v, array: $cardFields),
			builtIn: self::objectList(value: ($block['builtIn'] ?? [])),
			source: $source,
			topics: $topics,
			installAuth: $installAuth,
			// Shareable configuration type ids (store-over-federated-config).
			// Declaring these selects federated discovery, where an item is a
			// configuration set, a flow or a schema that marked itself
			// shareable. An app that declares none keeps the remote objects
			// API it has today, so nothing that ships now changes.
			types: self::stringList(value: ($block['types'] ?? [])),
		);
	}//end fromManifest()

	/**
	 * The shareable configuration type ids this store surfaces.
	 *
	 * @return array<int, string> The declared type ids, in declaration order.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function declaredTypes(): array {
		return $this->types;
	}//end declaredTypes()

	/**
	 * Whether this store exchanges configuration rather than objects.
	 *
	 * The two paths are selected by declaration, never by a runtime probe: an
	 * app that declares types gets federated discovery, and one that declares
	 * none never makes a discovery call at all.
	 *
	 * @return bool True when the app declared at least one shareable type.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
	 */
	public function isFederated(): bool {
		return $this->types !== [];
	}//end isFederated()

	/**
	 * Whether an install may write into this schema slug.
	 *
	 * @param string $slug The schema slug a store component names.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-an-install-must-refuse-every-schema-the-manifest-does-not-allow
	 */
	public function isInstallable(string $slug): bool {
		return $slug !== '' && in_array($slug, $this->installable, true) === true;
	}//end isInstallable()

	/**
	 * Coerce a declared value to a list of non-empty strings.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, string>
	 */
	private static function stringList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$out[] = $entry;
			}
		}

		return array_values(array_unique($out));
	}//end stringList()

	/**
	 * Coerce a declared value to a list of associative arrays.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function objectList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn ($e): bool => is_array($e) === true));
	}//end objectList()
	/**
	 * Whether a declared install posture is one this engine understands.
	 *
	 * The `action:` arm is recognised here but NOT yet enforceable: resolving
	 * an ADR-023 action needs a resolver that lives in the leaf apps. Until
	 * that lands, isInstallAuthEnforceable() reports it as unenforceable and
	 * the controller refuses the install rather than quietly downgrading it to
	 * `authenticated`.
	 *
	 * @param string $value The declared posture.
	 *
	 * @return bool
	 */
	private static function isKnownInstallAuth(string $value): bool {
		if (in_array(needle: $value, haystack: self::INSTALL_AUTH, strict: true) === true) {
			return true;
		}

		return str_starts_with($value, self::INSTALL_AUTH_ACTION_PREFIX) === true
			&& strlen($value) > strlen(self::INSTALL_AUTH_ACTION_PREFIX);
	}//end isKnownInstallAuth()

	/**
	 * Whether this engine can enforce the declared posture today.
	 *
	 * @return bool
	 */
	public function isInstallAuthEnforceable(): bool {
		return in_array(needle: $this->installAuth, haystack: self::INSTALL_AUTH, strict: true);
	}//end isInstallAuthEnforceable()

	/**
	 * Whether an install by this user is permitted by the declared posture.
	 *
	 * 🔴 ANONYMOUS IS REFUSED WHATEVER THE POSTURE. `authenticated` is the
	 * weakest gate the vocabulary offers, and it still means signed in.
	 *
	 * @param bool $isSignedIn Whether a user is signed in.
	 * @param bool $isAdmin    Whether that user is an instance administrator.
	 *
	 * @return bool
	 */
	public function permitsInstall(bool $isSignedIn, bool $isAdmin): bool {
		if ($isSignedIn === false) {
			return false;
		}

		if ($this->installAuth === 'authenticated') {
			return true;
		}

		return $isAdmin;
	}//end permitsInstall()

}//end class
