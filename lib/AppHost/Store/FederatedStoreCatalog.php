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
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
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
	 * @param ShareableConfigTypeRegistry $registry  Resolves a declared type id to its type.
	 * @param FederatedConfigService      $federated Discovery, fetch, trust and install.
	 * @param LoggerInterface             $logger    PSR logger, server-side detail only.
	 */
	public function __construct(
		private readonly ShareableConfigTypeRegistry $registry,
		private readonly FederatedConfigService $federated,
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
	 * @param string|null     $query      Optional free-text filter.
	 * @param string|null     $kind       Optional type-id filter, matching the surface's kind chips.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/store-over-federated-config/specs/apphost-store-plane/spec.md#scenario-a-configuration-set-reaches-the-store-surface
	 */
	public function search(StoreDescriptor $descriptor, ?string $query = null, ?string $kind = null): array {
		$cards = [];

		foreach ($descriptor->types as $typeId) {
			$type = $this->registry->get(id: $typeId);
			if ($type === null) {
				// A declared type nothing owns is a manifest error, not a
				// runtime failure: name it in the log and carry on, so one
				// stale id does not blank the whole store.
				$this->logger->warning(
					message: sprintf(
						'[AppHost\\Store] %s declares shareable type %s, which no app owns',
						$descriptor->appId,
						$typeId
					),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			if ($kind !== null && trim($kind) !== '' && trim($kind) !== $typeId) {
				continue;
			}

			try {
				$found = $this->federated->discover(topic: $type->getTopic());
			} catch (Throwable $e) {
				$this->logger->warning(
					message: sprintf('[AppHost\\Store] discovery failed for %s: %s', $typeId, $e->getMessage()),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			foreach ($found as $entry) {
				$card = $this->toCard(typeId: $typeId, displayName: $type->getDisplayName(), entry: $entry);
				if ($this->matches(card: $card, query: $query) === true) {
					$cards[] = $card;
				}
			}
		}//end foreach

		return ['outcome' => GenericStoreService::OUTCOME_OK, 'cards' => $cards];
	}//end search()

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

			try {
				$found = $this->federated->discover(topic: $type->getTopic());
			} catch (Throwable $e) {
				$this->logger->warning(
					message: sprintf('[AppHost\\Store] discovery failed for %s: %s', $typeId, $e->getMessage()),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			foreach ($found as $entry) {
				$repo = (string)($entry['repo'] ?? '');
				if ($repo === '' || self::slugFor(typeId: $typeId, repo: $repo) !== $slug) {
					continue;
				}

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
			}
		}//end foreach

		return null;
	}//end resolve()

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
			return [
				'success' => false,
				'components' => [
					[
						'schema' => (string)($ref['typeId'] ?? ''),
						'status' => 'refused',
						'message' => sprintf('This organisation does not trust %s as a store source.', $source),
					],
				],
			];
		}

		try {
			$result = $this->federated->install(
				typeId: (string)$ref['typeId'],
				bundle: (array)$ref['bundle'],
				source: $source
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: sprintf('[AppHost\\Store] installing %s failed: %s', (string)$ref['typeId'], $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [
				'success' => false,
				'components' => [
					[
						'schema' => (string)$ref['typeId'],
						'status' => 'error',
						'message' => 'The configuration could not be installed.',
					],
				],
			];
		}//end try

		$installed = ($result['installed'] ?? []);
		if (is_array($installed) === false) {
			$installed = [];
		}

		$components = [];
		foreach ($installed as $entry) {
			if (is_string($entry) === true) {
				$name = $entry;
			} else {
				$name = (string)($entry['type'] ?? $ref['typeId']);
			}

			$components[] = [
				'schema' => $name,
				'status' => 'installed',
				'message' => '',
			];
		}

		if ($components === []) {
			$components[] = ['schema' => (string)$ref['typeId'], 'status' => 'installed', 'message' => ''];
		}

		return ['success' => true, 'components' => $components];
	}//end install()

	/**
	 * Read a repository's bundle from the first conventional path that answers.
	 *
	 * @param string $repo The `owner/repo`.
	 *
	 * @return array<string, mixed>|null The decoded bundle, or null when no path answers.
	 */
	private function fetch(string $repo): ?array {
		foreach (self::BUNDLE_PATHS as $path) {
			try {
				$bundle = $this->federated->fetchBundle(repo: $repo, path: $path);
			} catch (Throwable $e) {
				continue;
			}

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
	 * Turn one discovered repository into a store card.
	 *
	 * @param string               $typeId      The shareable type id.
	 * @param string               $displayName The type's human name.
	 * @param array<string, mixed> $entry       One discovery result.
	 *
	 * @return array<string, mixed> The card.
	 */
	private function toCard(string $typeId, string $displayName, array $entry): array {
		$repo = (string)($entry['repo'] ?? '');
		$owner = explode('/', $repo)[0];

		return [
			'slug' => self::slugFor(typeId: $typeId, repo: $repo),
			'title' => (string)($entry['name'] ?? $repo),
			'description' => (string)($entry['description'] ?? ''),
			// The surface's kind chip is the type, because that is the honest
			// discriminator here: a set, a flow and a schema are what differ.
			'kind' => $typeId,
			'type' => $typeId,
			'typeName' => $displayName,
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
