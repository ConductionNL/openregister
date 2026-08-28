<?php

/**
 * OpenRegister AppHost — Abstract Schema Reference Provider
 *
 * Engine-owned base class that lets a consuming app expose a single
 * (register, schema) pair as its own discoverable, searchable Smart Picker
 * entry with a single thin subclass declaring only the two slugs. The
 * provider id, supported search-provider id, title, and icon are computed
 * deterministically by this class — a subclass cannot override them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Reference
 * @package  OCA\OpenRegister\AppHost\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Reference;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use Psr\Log\LoggerInterface;

/**
 * Base class for a schema-scoped Smart Picker reference provider.
 *
 * A concrete subclass implements only {@see self::getRegisterSlug()} and
 * {@see self::getSchemaSlug()} — no id, title, icon, or constructor
 * boilerplate. Everything else (URL parsing, RBAC-safe resolution, rich
 * preview formatting) is reused from {@see ObjectPreviewFormatter}, the
 * same service the generic `ObjectReferenceProvider` delegates to.
 *
 * Gated by the schema's `smartPickerEnabled` flag: when `false`,
 * `matchReference()`/`resolveReference()`/`getCachePrefix()` are all
 * functionally inert for this schema, even though the provider class
 * itself remains registered and listed in the Smart Picker's "Select
 * provider" list — see design.md D2a's "Known limitation".
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractSchemaReferenceProvider extends ADiscoverableReferenceProvider implements
	ISearchableReferenceProvider {

	/**
	 * Memoized resolved register database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $registerId = null;

	/**
	 * Memoized resolved schema database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $schemaId = null;

	/**
	 * Constructor for AbstractSchemaReferenceProvider.
	 *
	 * @param ObjectPreviewFormatter $formatter Shared preview-formatting/URL-parsing service
	 * @param RegisterMapper $registerMapper Register mapper for slug-to-id resolution
	 * @param SchemaMapper $schemaMapper Schema mapper for slug-to-id resolution and the `smartPickerEnabled` flag
	 * @param LoggerInterface $logger Logger
	 * @param string|null $userId Current user ID (nullable for public/anonymous access)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	public function __construct(
		private readonly ObjectPreviewFormatter $formatter,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
		private readonly ?string $userId,
	) {
	}//end __construct()

	/**
	 * The register slug this provider is scoped to.
	 *
	 * @return string The register slug
	 */
	abstract public function getRegisterSlug(): string;

	/**
	 * The schema slug this provider is scoped to.
	 *
	 * @return string The schema slug
	 */
	abstract public function getSchemaSlug(): string;

	/**
	 * Computed id: `openregister-ref-{registerSlug}-{schemaSlug}`, matching
	 * the naming convention of the existing generic `openregister-ref-objects`
	 * id. Declared `final` so a subclass cannot pick a colliding or
	 * inconsistent id.
	 *
	 * @return string Provider ID
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getId(): string {
		return 'openregister-ref-' . $this->getRegisterSlug() . '-' . $this->getSchemaSlug();
	}//end getId()

	/**
	 * Computed search-provider id this reference provider pairs with:
	 * `openregister_objects_{registerSlug}_{schemaSlug}`, matching the
	 * underscore-style naming of the existing generic `openregister_objects`
	 * search provider. Declared `final` for the same reason as {@see self::getId()}.
	 *
	 * @return string[] List of search provider IDs
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getSupportedSearchProviderIds(): array {
		return ['openregister_objects_' . $this->getRegisterSlug() . '_' . $this->getSchemaSlug()];
	}//end getSupportedSearchProviderIds()

	/**
	 * Read live from `SchemaMapper` — never cached beyond request scope, so
	 * a title edit in the Schema settings UI shows up immediately. Falls
	 * back to the raw schema slug when the schema cannot be resolved.
	 * Declared `final`.
	 *
	 * @return string The schema's current title, or its slug as fallback
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getTitle(): string {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return $this->getSchemaSlug();
		}

		try {
			$title = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->getTitle();
			if ($title !== null && $title !== '') {
				return $title;
			}
		} catch (\Exception $e) {
			// Fall through to slug fallback.
		}

		return $this->getSchemaSlug();
	}//end getTitle()

	/**
	 * Returns the order/priority for Smart Picker sorting, matching the
	 * generic provider's order.
	 *
	 * @return int Order value (lower = higher priority)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function getOrder(): int {
		return 10;
	}//end getOrder()

	/**
	 * Resolves the schema's own configured MDI icon via the same
	 * `openregister.icon.mdi` route (`MdiIconRenderer`) resolution
	 * `ObjectsProvider` already performs, falling back to the generic
	 * OpenRegister app icon. Declared `final`.
	 *
	 * @return string URL to the resolved icon
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	final public function getIconUrl(): string {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId !== false) {
			$mdiIconUrl = $this->formatter->resolveMdiIconUrl(schemaId: $schemaId);
			if ($mdiIconUrl !== null) {
				return $mdiIconUrl;
			}
		}

		return $this->formatter->getAppIconUrl();
	}//end getIconUrl()

	/**
	 * Check whether a URL matches an OpenRegister object reference belonging
	 * to this provider's configured (register, schema) pair.
	 *
	 * Reuses the shared URL-parsing logic; a syntactically valid OpenRegister
	 * object URL for a DIFFERENT schema/register still returns `false`.
	 * Short-circuits to `false` when this schema's `smartPickerEnabled` flag
	 * is off.
	 *
	 * @param string $referenceText The URL to check
	 *
	 * @return bool True only when the URL matches this provider's own schema
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function matchReference(string $referenceText): bool {
		if ($this->isSmartPickerEnabled() === false) {
			return false;
		}

		$parsed = $this->formatter->parseReference(referenceText: $referenceText);
		if ($parsed === null) {
			return false;
		}

		return $parsed['registerId'] === $this->resolveRegisterId()
			&& $parsed['schemaId'] === $this->resolveSchemaId();
	}//end matchReference()

	/**
	 * Resolve a matched URL into a rich reference object, scoped to this
	 * provider's configured schema.
	 *
	 * @param string $referenceText The matched URL
	 *
	 * @return IReference|null The reference object, or null when the URL
	 *                         does not match this provider's schema, the
	 *                         flag is off, or resolution otherwise fails
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function resolveReference(string $referenceText): ?IReference {
		if ($this->matchReference(referenceText: $referenceText) === false) {
			return null;
		}

		return $this->formatter->buildReference(referenceText: $referenceText);
	}//end resolveReference()

	/**
	 * Returns the cache prefix for a reference URL, scoped the same way the
	 * generic provider's cache prefix is computed. When the flag is off (or
	 * the URL does not match this provider's schema) the raw reference text
	 * is used as the prefix, matching the "no match" cache behavior.
	 *
	 * @param string $referenceId The reference URL
	 *
	 * @return string Cache prefix based on register/schema/uuid, or the
	 *                original text when it does not match this provider
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function getCachePrefix(string $referenceId): string {
		if ($this->matchReference(referenceText: $referenceId) === false) {
			return $referenceId;
		}

		return $this->formatter->resolveCachePrefix(referenceText: $referenceId);
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $this->userId ?? '';
	}//end getCacheKey()

	/**
	 * Whether this provider's configured schema has opted in to its own
	 * Smart Picker entry's functionality. `false` for an unresolvable
	 * schema slug, matching the fail-closed default.
	 *
	 * @return bool True when the schema's `smartPickerEnabled` flag is set
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	protected function isSmartPickerEnabled(): bool {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return false;
		}

		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->isSmartPickerEnabled();
		} catch (\Exception $e) {
			return false;
		}
	}//end isSmartPickerEnabled()

	/**
	 * Lazily resolve {@see self::getRegisterSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request — mirroring how
	 * `DeepLinkRegistryService` resolves slugs lazily.
	 *
	 * @return int|false The resolved register ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	private function resolveRegisterId(): int|false {
		if ($this->registerId === null) {
			try {
				$this->registerId = (int)$this->registerMapper->find(
					$this->getRegisterSlug(),
					_rbac: false,
					_multitenancy: false
				)->getId();
			} catch (\Exception $e) {
				$this->logger->debug(
					'[AbstractSchemaReferenceProvider] Failed to resolve register slug "{slug}": {error}',
					['slug' => $this->getRegisterSlug(), 'error' => $e->getMessage()]
				);
				$this->registerId = false;
			}
		}

		return $this->registerId;
	}//end resolveRegisterId()

	/**
	 * Lazily resolve {@see self::getSchemaSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request.
	 *
	 * @return int|false The resolved schema ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	private function resolveSchemaId(): int|false {
		if ($this->schemaId === null) {
			try {
				$this->schemaId = (int)$this->schemaMapper->find(
					$this->getSchemaSlug(),
					_multitenancy: false,
					_rbac: false
				)->getId();
			} catch (\Exception $e) {
				$this->logger->debug(
					'[AbstractSchemaReferenceProvider] Failed to resolve schema slug "{slug}": {error}',
					['slug' => $this->getSchemaSlug(), 'error' => $e->getMessage()]
				);
				$this->schemaId = false;
			}
		}

		return $this->schemaId;
	}//end resolveSchemaId()
}//end class
