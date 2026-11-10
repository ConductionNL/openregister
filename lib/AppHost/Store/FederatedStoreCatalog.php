<?php

/**
 * OpenRegister AppHost — Federated Store Catalog
 *
 * The store's catalogue when an app exchanges CONFIGURATION rather than rows.
 *
 * A store item here is a configuration set, a flow, or a schema that marked
 * itself shareable: whatever the app's declared shareable types own. Discovery,
 * signature checking, trust and install all stay in FederatedConfigService, so
 * this class only translates between the store surface's card shape and the
 * federated engine's repo/bundle shape.
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
 *
 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreSearch;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Browse and install published configuration through the store surface.
 *
 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-store-must-be-able-to-offer-configuration-not-only-objects
 */
class FederatedStoreCatalog {
	/**
	 * Where a published repository carries its bundle.
	 *
	 * Discovery returns repositories, and a repository is not a bundle, so
	 * something has to say where the bundle lives. `publish()` takes a
	 * caller-supplied path, which is fine for a link the publisher hands you
	 * and useless for a browsable store: a card with no path cannot be
	 * installed. This is that convention, tried in order.
	 *
	 * @var array<int, string>
	 */
	public const BUNDLE_PATHS = [
		'openregister.json',
		'.openregister/config.json',
	];

	/**
	 * Constructor.
	 *
	 * @param ShareableConfigTypeRegistryInterface $registry  Resolves a declared type id to its type.
	 * @param FederatedConfigServiceInterface      $federated Discovery, fetch, trust and install.
	 * @param LoggerInterface                      $logger    PSR logger, server-side detail only.
	 */
	public function __construct(
		private readonly ShareableConfigTypeRegistryInterface $registry,
		private readonly FederatedConfigServiceInterface $federated,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stable card slug for a published repository of one type.
	 *
	 * A card slug travels through a URL path segment, so it must satisfy the
	 * controller's slug pattern: lowercase alphanumerics and hyphens. The
	 * encoding is deterministic rather than reversible, and `resolve()`
	 * recomputes every candidate's slug and compares, exactly as the objects
	 * path compares the slug the registry actually returned.
	 *
	 * @param string $typeId The shareable type id.
	 * @param string $repo   The `owner/repo`.
	 *
	 * @return string The card slug.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-configuration-install-must-run-through-its-owning-type
	 */
	public static function slugFor(string $typeId, string $repo): string {
		$raw = strtolower($typeId . '-' . $repo);
		$slug = preg_replace('/[^a-z0-9]+/', '-', $raw);
		return trim((string)$slug, '-');
	}//end slugFor()

	/**
	 * Browse every declared type's published configuration.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param StoreSearch     $search     Optional free-text filter, and optional type-id filter matching the
	 *                                    surface's kind chips.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#scenario-a-configuration-set-reaches-the-store-surface
	 */
	public function search(StoreDescriptor $descriptor, StoreSearch $search = new StoreSearch()): array {
		$cards = [];

		foreach ($descriptor->types as $typeId) {
			$declared = $this->ownedType(descriptor: $descriptor, typeId: $typeId);
			if ($declared !== null && $this->kindExcludes(kind: $search->kind, typeId: $typeId) === false) {
				$cards = [...$cards, ...$this->matchingCards(declared: $declared, query: $search->query)];
			}
		}

		return ['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => $cards];
	}//end search()

	/**
	 * The registered type behind a declared type id, or null with a warning.
	 *
	 * A declared type nothing owns is a manifest error, not a runtime
	 * failure: name it in the log and carry on, so one stale id does not
	 * blank the whole store.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $typeId     The declared type id.
	 *
	 * @return DeclaredShareableType|null The type, or null when no app owns it.
	 */
	private function ownedType(StoreDescriptor $descriptor, string $typeId): ?DeclaredShareableType {
		$type = $this->registry->get(id: $typeId);
		if ($type === null) {
			$this->logger->warning(
				message: sprintf('[AppHost\\Store] %s declares shareable type %s, which no app owns', $descriptor->appId, $typeId),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return new DeclaredShareableType(typeId: $typeId, type: $type);
	}//end ownedType()

	/**
	 * Whether a kind filter leaves this type out.
	 *
	 * @param string|null $kind   The optional type-id filter.
	 * @param string      $typeId The type id.
	 *
	 * @return bool True when the filter is set and names another type.
	 */
	private function kindExcludes(?string $kind, string $typeId): bool {
		return $kind !== null && trim($kind) !== '' && trim($kind) !== $typeId;
	}//end kindExcludes()

	/**
	 * The cards of one type that match the free-text query.
	 *
	 * @param DeclaredShareableType $declared The declared type.
	 * @param string|null           $query    Optional free-text filter.
	 *
	 * @return array<int, array<string, mixed>> The matching cards.
	 */
	private function matchingCards(DeclaredShareableType $declared, ?string $query): array {
		$cards = [];
		foreach ($this->discoverType(declared: $declared) as $entry) {
			$card = $this->toCard(declared: $declared, entry: $entry);
			if ($this->matches(card: $card, query: $query) === true) {
				$cards[] = $card;
			}
		}

		return $cards;
	}//end matchingCards()

	/**
	 * The repositories discovered for a type; a failed discovery is logged
	 * and yields none, so one unreachable topic does not blank the store.
	 *
	 * @param DeclaredShareableType $declared The declared type.
	 *
	 * @return array<int, array<string, mixed>> The discovered entries.
	 */
	private function discoverType(DeclaredShareableType $declared): array {
		try {
			return $this->federated->discover(topic: $declared->type->getTopic());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[AppHost\\Store] discovery failed for %s: %s', $declared->typeId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}
	}//end discoverType()

	/**
	 * Resolve a card slug back to the bundle it names.
	 *
	 * @param StoreDescriptor $descriptor The calling app's store parameters.
	 * @param string          $slug       The card slug.
	 *
	 * @return array{typeId: string, repo: string, source: string, bundle: array<string, mixed>}|null
	 *         The resolved bundle, or null when nothing matches.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#requirement-a-configuration-install-must-run-through-its-owning-type
	 */
	public function resolve(StoreDescriptor $descriptor, string $slug): ?array {
		foreach ($descriptor->types as $typeId) {
			$type = $this->registry->get(id: $typeId);
			if ($type === null) {
				continue;
			}

			$entry = $this->entryFor(declared: new DeclaredShareableType(typeId: $typeId, type: $type), slug: $slug);
			if ($entry !== null) {
				return $this->resolvedBundle(typeId: $typeId, entry: $entry);
			}
		}

		return null;
	}//end resolve()

	/**
	 * The first discovered entry of a type whose slug is the given one.
	 *
	 * @param DeclaredShareableType $declared The declared type.
	 * @param string                $slug     The card slug.
	 *
	 * @return array<string, mixed>|null The entry, or null when none matches.
	 */
	private function entryFor(DeclaredShareableType $declared, string $slug): ?array {
		foreach ($this->discoverType(declared: $declared) as $entry) {
			$repo = (string)($entry['repo'] ?? '');
			if ($repo !== '' && self::slugFor(typeId: $declared->typeId, repo: $repo) === $slug) {
				return $entry;
			}
		}

		return null;
	}//end entryFor()

	/**
	 * Fetch the bundle of a matched entry.
	 *
	 * @param string               $typeId The type id.
	 * @param array<string, mixed> $entry  The matched entry.
	 *
	 * @return array{typeId: string, repo: string, source: string, bundle: array<string, mixed>}|null
	 *         The resolved bundle, or null when the repository holds none.
	 */
	private function resolvedBundle(string $typeId, array $entry): ?array {
		$repo   = (string)$entry['repo'];
		$bundle = $this->fetch(repo: $repo);
		if ($bundle === null) {
			return null;
		}

		return [
			'typeId' => $typeId,
			'repo' => $repo,
			'source' => (string)($entry['url'] ?? $repo),
			'bundle' => $bundle,
		];
	}//end resolvedBundle()

	/**
	 * Install a resolved bundle through the type that owns it.
	 *
	 * The source check runs BEFORE the install rather than inside it. A bundle
	 * from a publisher this organisation has not trusted is refused whole: a
	 * half-applied configuration set is worse than none, because nothing then
	 * says which half arrived.
	 *
	 * @param array $ref The resolved bundle from {@see self::resolve()}, shaped
	 *                   `{typeId: string, repo: string, source: string, bundle: array}`.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>} The install report.
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#scenario-an-untrusted-publisher-is-refused
	 */
	public function install(array $ref): array {
		$source = (string)($ref['source'] ?? '');

		if ($this->federated->isSourceAllowed(source: $source) === false) {
			return $this->refusedReport(ref: $ref, source: $source);
		}

		try {
			$result = $this->installThroughType(ref: $ref, source: $source);
		} catch (Throwable $e) {
			return $this->failedReport(typeId: (string)$ref['typeId'], error: $e);
		}

		return ['success' => true, 'components' => $this->installedComponents(result: $result, typeId: (string)$ref['typeId'])];
	}//end install()

	/**
	 * The report of a bundle refused because its source is not trusted.
	 *
	 * @param array  $ref    The resolved bundle.
	 * @param string $source The bundle's source.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 */
	private function refusedReport(array $ref, string $source): array {
		return $this->failureReport(
			component: [
				'schema' => (string)($ref['typeId'] ?? ''),
				'status' => 'refused',
				'message' => sprintf('This organisation does not trust %s as a store source.', $source),
			]
		);
	}//end refusedReport()

	/**
	 * An install report of one component that did not install.
	 *
	 * @param array<string, string> $component The component's report line.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 */
	private function failureReport(array $component): array {
		return [
			'success' => false,
			'components' => [$component],
		];
	}//end failureReport()

	/**
	 * Install the bundle through the shareable type that owns it.
	 *
	 * @param array  $ref    The resolved bundle.
	 * @param string $source The bundle's (trusted) source.
	 *
	 * @return array The type's install result.
	 */
	private function installThroughType(array $ref, string $source): array {
		return $this->federated->install(
			typeId: (string)$ref['typeId'],
			bundle: (array)$ref['bundle'],
			source: $source
		);
	}//end installThroughType()

	/**
	 * Log a failed install and report it without the exception's detail.
	 *
	 * @param string    $typeId The type the bundle was installed through.
	 * @param Throwable $error  The failure.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 */
	private function failedReport(string $typeId, Throwable $error): array {
		$this->logger->error(
			message: sprintf('[AppHost\\Store] installing %s failed: %s', $typeId, $error->getMessage()),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $this->failureReport(
			component: [
				'schema' => $typeId,
				'status' => 'error',
				'message' => 'The configuration could not be installed.',
			]
		);
	}//end failedReport()

	/**
	 * The components a successful install reports, the type itself when it named none.
	 *
	 * @param array  $result The type's install result.
	 * @param string $typeId The type the bundle was installed through.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function installedComponents(array $result, string $typeId): array {
		$components = [];
		foreach ($this->installedEntries(result: $result) as $entry) {
			$components[] = [
				'schema' => $this->componentName(entry: $entry, fallback: $typeId),
				'status' => 'installed',
				'message' => '',
			];
		}

		if ($components === []) {
			$components[] = ['schema' => $typeId, 'status' => 'installed', 'message' => ''];
		}

		return $components;
	}//end installedComponents()

	/**
	 * The entries a type's install result lists as installed, none when it lists nothing readable.
	 *
	 * @param array $result The type's install result.
	 *
	 * @return array
	 */
	private function installedEntries(array $result): array {
		$installed = ($result['installed'] ?? []);
		if (is_array($installed) === false) {
			$installed = [];
		}

		return $installed;
	}//end installedEntries()


	/**
	 * Name one installed component for the report.
	 *
	 * A type reports what it installed in its own terms: some return plain
	 * names, others return descriptors. Both are read here rather than
	 * assuming one shape.
	 *
	 * @param mixed  $entry    One entry from the type's install result.
	 * @param string $fallback The type id, used when the entry names nothing.
	 *
	 * @return string The component name.
	 */
	private function componentName(mixed $entry, string $fallback): string {
		if (is_string($entry) === true) {
			return $entry;
		}

		if (is_array($entry) === true) {
			return (string)($entry['type'] ?? $fallback);
		}

		return $fallback;
	}//end componentName()

	/**
	 * Read a repository's bundle from the first conventional path that answers.
	 *
	 * @param string $repo The `owner/repo`.
	 *
	 * @return array<string, mixed>|null The decoded bundle, or null when no path answers.
	 */
	private function fetch(string $repo): ?array {
		foreach (self::BUNDLE_PATHS as $path) {
			$bundle = $this->fetchAt(repo: $repo, path: $path);
			if ($bundle !== []) {
				return $bundle;
			}
		}

		$this->logger->warning(
			message: sprintf('[AppHost\\Store] %s carries no bundle at a conventional path', $repo),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return null;
	}//end fetch()

	/**
	 * Read a repository's bundle at one path; empty when the path does not answer.
	 *
	 * @param string $repo The `owner/repo`.
	 * @param string $path The path to read.
	 *
	 * @return array<string, mixed> The decoded bundle, empty on failure.
	 */
	private function fetchAt(string $repo, string $path): array {
		try {
			return $this->federated->fetchBundle(repo: $repo, path: $path);
		} catch (Throwable $e) {
			return [];
		}
	}//end fetchAt()

	/**
	 * Turn one discovered repository into a store card.
	 *
	 * @param DeclaredShareableType $declared The declared type.
	 * @param array<string, mixed>  $entry    One discovery result.
	 *
	 * @return array<string, mixed> The card.
	 */
	private function toCard(DeclaredShareableType $declared, array $entry): array {
		$repo = (string)($entry['repo'] ?? '');
		$owner = explode('/', $repo)[0];
		$typeId = $declared->typeId;

		return [
			'slug' => self::slugFor(typeId: $typeId, repo: $repo),
			'title' => (string)($entry['name'] ?? $repo),
			'description' => (string)($entry['description'] ?? ''),
			// The surface's kind chip is the type, because that is the honest
			// discriminator here: a set, a flow and a schema are what differ.
			'kind' => $typeId,
			'type' => $typeId,
			'typeName' => $declared->type->getDisplayName(),
			'publisher' => $owner,
			'source' => (string)($entry['url'] ?? ''),
			'updated' => (string)($entry['updated'] ?? ''),
			'category' => '',
			'version' => '',
		];
	}//end toCard()

	/**
	 * Whether a card survives the free-text filter.
	 *
	 * @param array<string, mixed> $card  The card.
	 * @param string|null          $query The filter, or null for no filter.
	 *
	 * @return bool
	 */
	private function matches(array $card, ?string $query): bool {
		if ($query === null || trim($query) === '') {
			return true;
		}

		$needle = mb_strtolower(trim($query));
		$hay = mb_strtolower(
			(string)$card['title'] . ' ' . (string)$card['description'] . ' ' . (string)$card['publisher']
		);

		return str_contains($hay, $needle);
	}//end matches()
}//end class
