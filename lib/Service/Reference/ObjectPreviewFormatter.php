<?php

/**
 * OpenRegister Object Preview Formatter
 *
 * Shared logic behind every OpenRegister Smart Picker reference provider:
 * recognising OpenRegister object URLs, building the canonical URLs for a
 * given object, and formatting the rich `IReference` preview card. Both the
 * generic `ObjectReferenceProvider` and the schema-scoped
 * `AbstractSchemaReferenceProvider` delegate to this service so the URL
 * shapes and the preview shape are defined exactly once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reference;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\MdiIconRenderer;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Builds and recognises OpenRegister object references for the Smart Picker.
 *
 * Owns the single source of truth for OpenRegister's canonical object
 * reference URL shapes (see {@see self::PATTERNS}) and the rich-preview
 * shape resolved from an object. `parseReference()` (recognising) and
 * `buildCanonicalUrls()` (building, for cache invalidation) both iterate
 * the same pattern list, so they cannot drift out of sync — see
 * design.md D4 of the schema-scoped-smart-picker change.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class ObjectPreviewFormatter implements ObjectPreviewFormatterInterface {

	/**
	 * Internal fields to exclude from preview properties.
	 *
	 * @var string[]
	 */
	private const INTERNAL_FIELDS = [
		'@self',
		'_translationMeta',
		'_schema',
		'_register',
		'_id',
		'_uuid',
		'_created',
		'_updated',
		'_owner',
		'_organisation',
		'id',
		'uuid',
	];

	/**
	 * Maximum number of preview properties to display.
	 *
	 * @var int
	 */
	private const MAX_PREVIEW_PROPERTIES = 4;

	/**
	 * Maximum length for description text.
	 *
	 * @var int
	 */
	private const MAX_DESCRIPTION_LENGTH = 200;

	/**
	 * Single source of truth for OpenRegister's canonical object-reference
	 * URL shapes. `parseReference()` and `buildCanonicalUrls()` both iterate
	 * this array — there is no second, independently maintained pattern
	 * list. `pathTemplate` builds a URL (via `strtr()`); `pathPattern` is a
	 * `sprintf()` template for the equivalent recognising regex, with `%s`
	 * standing in for the UUID sub-pattern. PHP class constants cannot hold
	 * closures, so both directions are expressed as data (templates)
	 * consumed by `parseReference()`/`buildCanonicalUrls()` rather than as
	 * callables — the guarantee (one list, both directions) is the same.
	 *
	 * @var array<int, array{name: string, pathTemplate: string, pathPattern: string}>
	 */
	private const PATTERNS = [
		[
			'name' => 'hash-routed',
			'pathTemplate' => '/apps/openregister/#/registers/{registerId}/schemas/{schemaId}/objects/{uuid}',
			'pathPattern' => '\/apps\/openregister\/#\/registers\/(\d+)\/schemas\/(\d+)\/objects\/(%s)',
		],
		[
			'name' => 'api',
			'pathTemplate' => '/apps/openregister/api/objects/{registerId}/{schemaId}/{uuid}',
			'pathPattern' => '\/apps\/openregister\/api\/objects\/(\d+)\/(\d+)\/(%s)',
		],
		[
			'name' => 'direct-route',
			'pathTemplate' => '/apps/openregister/objects/{registerId}/{schemaId}/{uuid}',
			'pathPattern' => '\/apps\/openregister\/objects\/(\d+)\/(\d+)\/(%s)',
		],
	];

	/**
	 * Constructor for ObjectPreviewFormatter.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator
	 * @param IL10N $l10n The localization service
	 * @param ObjectPreviewSources $sources The object service, deep link registry and schema/register mappers
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly ObjectPreviewSources $sources,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Parse a reference URL into its component parts.
	 *
	 * Iterates {@see self::PATTERNS} — the single source of truth for the
	 * three URL shapes OpenRegister recognises as an object reference.
	 *
	 * @param string $referenceText The URL to parse
	 *
	 * @return array{registerId: int, schemaId: int, uuid: string}|null Parsed parts or null
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d4
	 */
	public function parseReference(string $referenceText): ?array {
		$escapedBase = preg_quote($this->baseUrl(), '/');

		// UUID pattern (standard v4 format).
		$uuidPattern = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

		foreach (self::PATTERNS as $pattern) {
			$regex = sprintf(
				'/^%s(?:\/index\.php)?%s$/i',
				$escapedBase,
				sprintf($pattern['pathPattern'], $uuidPattern)
			);

			if (preg_match($regex, $referenceText, $matches) === 1) {
				return [
					'registerId' => (int)$matches[1],
					'schemaId' => (int)$matches[2],
					'uuid' => $matches[3],
				];
			}
		}//end foreach

		return null;
	}//end parseReference()

	/**
	 * Forward-build every canonical URL for one object.
	 *
	 * Iterates the same {@see self::PATTERNS} array `parseReference()` uses,
	 * so a pattern this class can recognise is always a pattern it can also
	 * build — there is no separately maintained "build" list to drift out of
	 * sync. Used by the cache-invalidation hook (design.md D4) to invalidate
	 * every URL shape a cached preview of this object might be keyed under.
	 *
	 * @param ObjectRef $ref The register and schema database ids and the object UUID.
	 *
	 * @return string[] Every canonical URL for this object, one per pattern
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d4
	 */
	public function buildCanonicalUrls(ObjectRef $ref): array {
		return $this->canonicalUrls(
			placeholders: ['{registerId}' => (string)$ref->register, '{schemaId}' => (string)$ref->schema, '{uuid}' => $ref->id]
		);
	}//end buildCanonicalUrls()

	/**
	 * Every canonical URL shape with its placeholders filled in.
	 *
	 * @param array<string, string> $placeholders The register id, schema id and uuid, keyed by placeholder.
	 *
	 * @return array<int, string>
	 */
	private function canonicalUrls(array $placeholders): array {
		$baseUrl = $this->baseUrl();

		$urls = [];
		foreach (self::PATTERNS as $pattern) {
			$urls[] = $baseUrl . strtr($pattern['pathTemplate'], $placeholders);
		}

		return $urls;
	}//end canonicalUrls()

	/**
	 * Resolve the cache prefix for a reference URL: parse-and-collapse, or
	 * pass the text through unchanged when it does not match a known shape.
	 *
	 * The single place that decides what cache prefix a reference text
	 * hashes under — `getCachePrefix()` on both reference providers AND the
	 * cache-invalidation hook all delegate here, so no code path hand-types
	 * the `"{registerId}/{schemaId}/{uuid}"` format independently.
	 *
	 * @param string $referenceText The reference URL
	 *
	 * @return string Cache prefix based on register/schema/uuid, or the
	 *                original text when it does not parse
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d4
	 */
	public function resolveCachePrefix(string $referenceText): string {
		$parsed = $this->parseReference(referenceText: $referenceText);
		if ($parsed === null) {
			return $referenceText;
		}

		return $parsed['registerId'] . '/' . $parsed['schemaId'] . '/' . $parsed['uuid'];
	}//end resolveCachePrefix()

	/**
	 * Resolve a matched reference URL into a rich `IReference`.
	 *
	 * Fetches the object data, schema/register names, and deep link URL to
	 * build a rich preview card for the Smart Picker widget. Returns `null`
	 * on any failure, including an RBAC denial, without leaking metadata.
	 *
	 * @param string $referenceText The matched URL
	 *
	 * @return IReference|null The reference object or null on failure
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	public function buildReference(string $referenceText): ?IReference {
		$parsed = $this->parseReference(referenceText: $referenceText);
		if ($parsed === null) {
			return null;
		}

		try {
			// Fetch the object using ObjectService.
			$object = WriteCause::asLookup(fn () => $this->sources->objectService->find(
				id: $parsed['uuid'],
				options: new ObjectFindOptions(register: $parsed['registerId'], schema: $parsed['schemaId'])
			));

			if ($object === null) {
				return null;
			}

			return $this->composeReference(
				referenceText: $referenceText,
				richData: $this->richData(parsed: $parsed, objectData: $object->jsonSerialize())
			);
		} catch (\Exception $exception) {
			// Catch all exceptions including authorization errors.
			// Return null to prevent metadata leakage on RBAC failures.
			$this->logger->debug(
				'[ObjectPreviewFormatter] Failed to resolve reference: {error}',
				[
					'error' => $exception->getMessage(),
					'reference' => $referenceText,
				]
			);
			return null;
		}//end try
	}//end buildReference()

	/**
	 * Build the reference for a found object from its rich data.
	 *
	 * @param string               $referenceText The original URL.
	 * @param array<string, mixed> $richData      The `openregister-object` rich object; see richData().
	 *
	 * @return IReference
	 */
	private function composeReference(string $referenceText, array $richData): IReference {
		$reference = new Reference($referenceText);
		$reference->setTitle($richData['title']);
		$reference->setDescription($richData['description']);
		$reference->setImageUrl($richData['icon_url']);
		$reference->setUrl($richData['url']);
		$reference->setRichObject('openregister-object', $richData);

		return $reference;
	}//end composeReference()

	/**
	 * The `openregister-object` rich object of a found object, from its serialised data.
	 *
	 * The metadata lives in `@self` and nowhere else: `jsonSerialize()` always emits it, so it
	 * is read without a fallback, and `updated` is read from it alone.
	 *
	 * @param array{registerId: int, schemaId: int, uuid: string} $parsed     The parsed reference.
	 * @param array<string, mixed>                                $objectData The serialised object.
	 *
	 * @return array<string, mixed>
	 */
	private function richData(array $parsed, array $objectData): array {
		$selfData = $objectData['@self'];

		return [
			'id' => $parsed['uuid'],
			'title' => $this->extractTitle(objectData: $objectData, selfData: $selfData),
			'description' => $this->extractDescription(objectData: $objectData),
			'schema' => ['id' => $parsed['schemaId'], 'title' => $this->resolveSchemaName(schemaId: $parsed['schemaId'])],
			'register' => ['id' => $parsed['registerId'], 'title' => $this->resolveRegisterName(registerId: $parsed['registerId'])],
			'url' => $this->resolveObjectUrl(parsed: $parsed, selfData: $selfData),
			'icon_url' => $this->resolveDeepLinkOrDefaultIconUrl(registerId: $parsed['registerId'], schemaId: $parsed['schemaId']),
			'updated' => $selfData['updated'] ?? '',
			'properties' => $this->extractPreviewProperties(objectData: $objectData),
		];
	}//end richData()

	/**
	 * The absolute object URL: the registered deep link, else the show route.
	 *
	 * @param array{registerId: int, schemaId: int, uuid: string} $parsed   The parsed reference.
	 * @param mixed                                               $selfData The object's `@self` metadata.
	 *
	 * @return string
	 */
	private function resolveObjectUrl(array $parsed, mixed $selfData): string {
		$registerId = $parsed['registerId'];
		$schemaId = $parsed['schemaId'];
		$uuid = $parsed['uuid'];

		$selfArray = [];
		if (is_array($selfData) === true) {
			$selfArray = $selfData;
		}

		$flatData = array_merge(
			$selfArray,
			['uuid' => $uuid, 'register' => $registerId, 'schema' => $schemaId]
		);

		$objectUrl = $this->sources->deepLinkRegistry->resolveUrl(
			registerId: $registerId,
			schemaId: $schemaId,
			objectData: $flatData
		);

		if ($objectUrl === null) {
			$objectUrl = $this->urlGenerator->linkToRoute(
				'openregister.objects.show',
				['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid]
			);
		}

		return $this->urlGenerator->getAbsoluteURL($objectUrl);
	}//end resolveObjectUrl()

	/**
	 * Resolve a schema ID to its display title.
	 *
	 * @param int $schemaId The schema ID
	 *
	 * @return string The schema title or fallback
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function resolveSchemaName(int $schemaId): string {
		try {
			$schema = $this->sources->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);
			$title = $schema->getTitle();
			if ($title !== null && $title !== '') {
				return $title;
			}
		} catch (\Exception $e) {
			// Fall through to default.
			$this->logger->debug(
				message: '[ObjectPreviewFormatter] Schema name not resolved',
				context: [
					'schemaId' => $schemaId,
					'exception' => $e->getMessage(),
				]
			);
		}

		return $this->l10n->t('Unknown Schema');
	}//end resolveSchemaName()

	/**
	 * Resolve a register ID to its display title.
	 *
	 * @param int $registerId The register ID
	 *
	 * @return string The register title or fallback
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function resolveRegisterName(int $registerId): string {
		try {
			$register = $this->sources->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
			$title = $register->getTitle();
			if ($title !== null && $title !== '') {
				return $title;
			}
		} catch (\Exception $e) {
			// Fall through to default.
			$this->logger->debug(
				message: '[ObjectPreviewFormatter] Register name not resolved',
				context: [
					'registerId' => $registerId,
					'exception' => $e->getMessage(),
				]
			);
		}

		return $this->l10n->t('Unknown Register');
	}//end resolveRegisterName()

	/**
	 * Resolve a schema ID to its configured MDI icon reference (e.g. "Dog").
	 *
	 * Tenancy/RBAC bypassed: this answers "what icon is configured", not an
	 * access-controlled question — the caller already resolved the object
	 * (or is only building a picker entry's static metadata).
	 *
	 * @param int $schemaId The schema ID
	 *
	 * @return string|null The schema's icon reference, or null when unset/unknown
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	public function resolveSchemaIconName(int $schemaId): ?string {
		try {
			$icon = $this->sources->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->getIcon();
			if ($icon !== null && $icon !== '') {
				return $icon;
			}
		} catch (\Exception $e) {
			// Fall through to null.
			$this->logger->debug(
				message: '[ObjectPreviewFormatter] Schema icon not resolved',
				context: [
					'schemaId' => $schemaId,
					'exception' => $e->getMessage(),
				]
			);
		}

		return null;
	}//end resolveSchemaIconName()

	/**
	 * Resolve a schema's configured MDI icon to a same-origin renderable URL.
	 *
	 * Same resolution `ObjectsProvider::resolveSchemaIcon()` already
	 * performed inline: only icons in the curated {@see MdiIconRenderer} set
	 * are renderable; the caller falls back to another icon source when this
	 * returns `null` (e.g. the schema has no icon, or an unrecognised one).
	 *
	 * @param int $schemaId The schema ID
	 *
	 * @return string|null URL through the `openregister.icon.mdi` route, or null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) MdiIconRenderer::has() is a stateless
	 *   lookup over a curated const map — the same pattern ObjectsProvider used
	 *   inline before this was extracted.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2
	 */
	public function resolveMdiIconUrl(int $schemaId): ?string {
		$iconName = $this->resolveSchemaIconName(schemaId: $schemaId);
		if ($iconName === null || MdiIconRenderer::has(icon: $iconName) === false) {
			return null;
		}

		return $this->urlGenerator->linkToRoute(
			'openregister.icon.mdi',
			['name' => $iconName]
		);
	}//end resolveMdiIconUrl()

	/**
	 * Resolve the icon URL used by reference-provider previews: the deep
	 * link registry's icon for the (register, schema) pair, falling back to
	 * the generic OpenRegister app icon.
	 *
	 * @param int $registerId The register database ID
	 * @param int $schemaId The schema database ID
	 *
	 * @return string The resolved icon URL
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function resolveDeepLinkOrDefaultIconUrl(int $registerId, int $schemaId): string {
		$iconUrl = $this->sources->deepLinkRegistry->resolveIcon(
			registerId: $registerId,
			schemaId: $schemaId
		);

		if ($iconUrl === null) {
			return $this->getAppIconUrl();
		}

		return $iconUrl;
	}//end resolveDeepLinkOrDefaultIconUrl()

	/**
	 * The generic OpenRegister app icon, used as the final fallback whenever
	 * no more specific icon (schema MDI icon, deep-link icon) is available.
	 *
	 * @return string URL to the app icon
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	public function getAppIconUrl(): string {
		return $this->urlGenerator->imagePath('openregister', 'app-dark.svg');
	}//end getAppIconUrl()

	/**
	 * Extract the display title from object data.
	 *
	 * @param array $objectData The full object data
	 * @param array $selfData The @self metadata
	 *
	 * @return string The object title
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	private function extractTitle(array $objectData, array $selfData): string {
		// Try @self.name first, then the title property, then the name property.
		foreach ([($selfData['name'] ?? null), ($objectData['title'] ?? null), ($objectData['name'] ?? null)] as $candidate) {
			if ($this->isFilledString(value: $candidate) === true) {
				return $candidate;
			}
		}

		// Fall back to UUID.
		$uuid = $selfData['id'] ?? $objectData['id'] ?? '';
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return $this->l10n->t('Unknown Object');
	}//end extractTitle()

	/**
	 * Extract a description from object data.
	 *
	 * @param array $objectData The full object data
	 *
	 * @return string Truncated description (max 200 chars)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	private function extractDescription(array $objectData): string {
		// Try summary first.
		$summary = ($objectData['summary'] ?? null);
		if ($this->isFilledString(value: $summary) === true) {
			return mb_substr($summary, 0, self::MAX_DESCRIPTION_LENGTH);
		}

		// Try description.
		$description = ($objectData['description'] ?? null);
		if ($this->isFilledString(value: $description) === false) {
			return '';
		}

		$desc = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			$desc .= '...';
		}

		return $desc;
	}//end extractDescription()

	/**
	 * Whether a value is a string that `empty()` does not treat as empty.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true non-empty-string $value
	 */
	private function isFilledString(mixed $value): bool {
		return empty($value) === false && is_string($value) === true;
	}//end isFilledString()

	/**
	 * Extract up to 4 preview properties from object data.
	 *
	 * Skips internal fields and non-scalar values.
	 *
	 * @param array $objectData The full object data
	 *
	 * @return array<int, array{label: string, value: string}> Preview properties
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d1
	 */
	private function extractPreviewProperties(array $objectData): array {
		$properties = [];
		$count = 0;

		foreach ($objectData as $key => $value) {
			if ($count >= self::MAX_PREVIEW_PROPERTIES) {
				break;
			}

			$property = $this->previewProperty(key: $key, value: $value);
			if ($property !== null) {
				$properties[] = $property;
				$count++;
			}
		}

		return $properties;
	}//end extractPreviewProperties()

	/**
	 * The preview row for one top-level field, or null when it is not shown.
	 *
	 * Internal fields and fields starting with `_` or `@` are skipped; only
	 * non-empty strings (cut at 100 characters) and numbers are shown.
	 *
	 * @param int|string $key   The field name.
	 * @param mixed      $value The field value.
	 *
	 * @return array{label: string, value: string}|null
	 */
	private function previewProperty(int|string $key, mixed $value): ?array {
		if ($this->isPreviewKey(key: $key) === false) {
			return null;
		}

		$shown = $this->previewValue(value: $value);
		if ($shown === '') {
			return null;
		}

		return ['label' => ucfirst($key), 'value' => $shown];
	}//end previewProperty()

	/**
	 * Whether a field may appear in the preview: not internal, not `_` / `@` prefixed.
	 *
	 * @param int|string $key The field name.
	 *
	 * @return bool
	 */
	private function isPreviewKey(int|string $key): bool {
		if (in_array($key, self::INTERNAL_FIELDS, true) === true) {
			return false;
		}

		return strpos($key, '_') !== 0 && strpos($key, '@') !== 0;
	}//end isPreviewKey()

	/**
	 * The shown text of a field value: a string cut at 100 characters, a
	 * number as text, otherwise '' (not shown; an empty string is not shown either).
	 *
	 * @param mixed $value The field value.
	 *
	 * @return string
	 */
	private function previewValue(mixed $value): string {
		if (is_string($value) === true) {
			return mb_substr($value, 0, 100);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return '';
	}//end previewValue()

	/**
	 * The absolute base URL of this Nextcloud instance, right-trimmed.
	 *
	 * @return string The base URL, e.g. `https://cloud.example.com`
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d4
	 */
	private function baseUrl(): string {
		return rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
	}//end baseUrl()
}//end class
