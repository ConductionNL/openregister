<?php

/**
 * OpenRegister Object Reference Provider
 *
 * Provides Smart Picker integration for OpenRegister objects. Allows users
 * to search for and insert rich references to register objects in Mail,
 * Text, Talk, Collectives, and any Nextcloud app supporting the Smart Picker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Reference
 * @package  OCA\OpenRegister\Reference
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

namespace OCA\OpenRegister\Reference;

use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IPublicReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Reference provider for OpenRegister objects.
 *
 * Resolves OpenRegister object URLs into rich preview cards for the Smart Picker.
 * Supports hash-routed UI URLs, API object URLs, and direct object routes.
 *
 * URL parsing and rich-preview formatting are delegated to
 * {@see ObjectPreviewFormatter} (shared with the schema-scoped
 * `AbstractSchemaReferenceProvider`); this class only owns the parts that
 * are specific to the generic, fleet-wide `openregister-ref-objects`
 * provider identity (id, title, order, icon, cache key).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectReferenceProvider extends ADiscoverableReferenceProvider implements
	ISearchableReferenceProvider,
	IPublicReferenceProvider {

	/**
	 * The URL generator service
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * The localization service
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Shared preview-formatting/URL-parsing service.
	 *
	 * @var ObjectPreviewFormatter
	 */
	private readonly ObjectPreviewFormatter $formatter;

	/**
	 * The current user ID (nullable for public/anonymous access)
	 *
	 * @var string|null
	 */
	private readonly ?string $userId;

	/**
	 * Constructor for ObjectReferenceProvider.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator
	 * @param IL10N $l10n The localization service
	 * @param ObjectPreviewFormatter $formatter Shared preview-formatting service
	 * @param string|null $userId Current user ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function __construct(
		IURLGenerator $urlGenerator,
		IL10N $l10n,
		ObjectPreviewFormatter $formatter,
		?string $userId,
	) {
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
		$this->formatter = $formatter;
		$this->userId = $userId;
	}//end __construct()

	/**
	 * Returns the unique identifier for this reference provider.
	 *
	 * @return string Provider ID
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getId(): string {
		return 'openregister-ref-objects';
	}//end getId()

	/**
	 * Returns the display title for the Smart Picker entry.
	 *
	 * @return string Translated title
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getTitle(): string {
		return $this->l10n->t('Register Objects');
	}//end getTitle()

	/**
	 * Returns the order/priority for Smart Picker sorting.
	 *
	 * @return int Order value (lower = higher priority)
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getOrder(): int {
		return 10;
	}//end getOrder()

	/**
	 * Returns the icon URL for the Smart Picker entry.
	 *
	 * @return string URL to the app icon
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getIconUrl(): string {
		return $this->urlGenerator->imagePath('openregister', 'app-dark.svg');
	}//end getIconUrl()

	/**
	 * Returns the supported search provider IDs for the Smart Picker.
	 *
	 * @return string[] List of search provider IDs
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getSupportedSearchProviderIds(): array {
		return ['openregister_objects'];
	}//end getSupportedSearchProviderIds()

	/**
	 * Check if a URL matches an OpenRegister object reference.
	 *
	 * Supports three URL patterns:
	 * 1. Hash-routed UI: /apps/openregister/#/registers/{id}/schemas/{id}/objects/{uuid}
	 * 2. API endpoint:   /apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}
	 * 3. Direct route:   /apps/openregister/objects/{registerId}/{schemaId}/{uuid}
	 *
	 * All patterns support optional /index.php/ prefix.
	 *
	 * @param string $referenceText The URL to check
	 *
	 * @return bool True if the URL matches an OpenRegister object reference
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function matchReference(string $referenceText): bool {
		return $this->formatter->parseReference(referenceText: $referenceText) !== null;
	}//end matchReference()

	/**
	 * Resolve a matched URL into a rich reference object.
	 *
	 * Fetches the object data, schema/register names, and deep link URL to
	 * build a rich preview card for the Smart Picker widget.
	 *
	 * @param string $referenceText The matched URL
	 *
	 * @return IReference|null The reference object or null on failure
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function resolveReference(string $referenceText): ?IReference {
		return $this->formatter->buildReference(referenceText: $referenceText);
	}//end resolveReference()

	/**
	 * Returns the cache prefix for a reference URL.
	 *
	 * @param string $referenceId The reference URL
	 *
	 * @return string Cache prefix based on register/schema/uuid
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getCachePrefix(string $referenceId): string {
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
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function getCacheKey(string $referenceId): ?string {
		return $this->userId ?? '';
	}//end getCacheKey()

	/**
	 * Resolve a reference for an anonymous/public-share viewer.
	 *
	 * Delegates to the same {@see self::resolveReference()} logic: the
	 * class is already constructed with a nullable `$userId` (`null` for an
	 * anonymous request), so `ObjectPreviewFormatter::buildReference()`'s
	 * call into `ObjectService::find()` already runs the same RBAC decision
	 * an authenticated anonymous read would. `$sharingToken` identifies the
	 * public share context, not an object-level permission — no additional
	 * check is performed beyond what `resolveReference()` already performs.
	 *
	 * @param string $referenceText The matched URL
	 * @param string $sharingToken The public share token
	 *
	 * @return IReference|null The reference object or null on failure
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d5
	 */
	public function resolveReferencePublic(string $referenceText, string $sharingToken): ?IReference {
		return $this->resolveReference(referenceText: $referenceText);
	}//end resolveReferencePublic()

	/**
	 * Returns the cache key for a publicly-resolved reference.
	 *
	 * Different public shares can expose different objects/permissions for
	 * the same underlying reference text, so the cache MUST vary by token —
	 * never collapse to a single anonymous-wide cache entry the way
	 * {@see self::getCacheKey()} collapses to `''`.
	 *
	 * @param string $referenceId The reference URL
	 * @param string $sharingToken The public share token
	 *
	 * @return string|null The sharing token, used as the cache key
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d5
	 */
	public function getCacheKeyPublic(string $referenceId, string $sharingToken): ?string {
		return $sharingToken;
	}//end getCacheKeyPublic()
}//end class
