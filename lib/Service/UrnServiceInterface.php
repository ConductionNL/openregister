<?php

/**
 * UrnServiceInterface: what callers outside the Service component use of UrnService.
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

/**
 * The URN parsing and resolution the URN endpoint and capability call on UrnService.
 */
interface UrnServiceInterface {

	/**
	 * Default URN namespace identifier (NID).
	 *
	 * Informal NID per RFC 8141 §5.1. Replace with a registered NID when
	 * formal IANA registration lands.
	 */
	public const DEFAULT_NID = 'nl-or';

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
	public function getInstanceSlug(): string;

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
	public function parse(string $urn): ?array;

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
	public function resolveBulk(array $urns): array;

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
	public function resolveUrl(string $urn): ?string;

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
	public function urnFromUrl(string $url): ?string;
}//end interface
