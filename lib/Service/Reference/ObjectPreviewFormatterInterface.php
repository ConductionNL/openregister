<?php

/**
 * The reference-provider side of ObjectPreviewFormatter.
 *
 * Reference providers outside lib/Service (lib/Reference, lib/AppHost/Reference) type
 * against this interface; ContractBindings aliases it to ObjectPreviewFormatter, so
 * they share the one formatter instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reference;

use OCP\Collaboration\Reference\IReference;

/**
 * The reference-provider side of ObjectPreviewFormatter.
 */
interface ObjectPreviewFormatterInterface {

	/**
	 * Parse a reference URL into its component parts.
	 *
	 * @param string $referenceText The URL to parse
	 *
	 * @return array{registerId: int, schemaId: int, uuid: string}|null Parsed parts or null
	 */
	public function parseReference(string $referenceText): ?array;

	/**
	 * The cache prefix ("{registerId}/{schemaId}/{uuid}") of a reference URL.
	 *
	 * @param string $referenceText The reference URL
	 *
	 * @return string Cache prefix, or the original text when it does not parse
	 */
	public function resolveCachePrefix(string $referenceText): string;

	/**
	 * Resolve a matched reference URL into a rich `IReference`.
	 *
	 * @param string $referenceText The matched URL
	 *
	 * @return IReference|null The reference object or null on failure
	 */
	public function buildReference(string $referenceText): ?IReference;

	/**
	 * The MDI icon URL of a schema, when its icon is in the curated set.
	 *
	 * @param int $schemaId The schema ID
	 *
	 * @return string|null URL through the `openregister.icon.mdi` route, or null
	 */
	public function resolveMdiIconUrl(int $schemaId): ?string;

	/**
	 * The generic OpenRegister app icon.
	 *
	 * @return string URL to the app icon
	 */
	public function getAppIconUrl(): string;
}//end interface
