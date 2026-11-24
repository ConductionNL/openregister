<?php
/**
 * ObjectPreviewInterface: the reference card of an object, from parsing its link to building its preview.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCP\Collaboration\Reference\IReference;

/**
 * Parses an object link and builds its reference card.
 *
 * The schema-scoped reference providers of leaf apps (Integrations) ask; the
 * object preview formatter (Configuration) answers and is bound to this
 * contract. ObjectPreviewFormatterInterface extends it.
 *
 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
 */
interface ObjectPreviewInterface {

	/**
	 * Parse a reference URL into its component parts.
	 *
	 * @param string $referenceText The URL to parse
	 *
	 * @return array{registerId: int, schemaId: int, uuid: string}|null Parsed parts or null
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function parseReference(string $referenceText): ?array;

	/**
	 * The cache prefix ("{registerId}/{schemaId}/{uuid}") of a reference URL.
	 *
	 * @param string $referenceText The reference URL
	 *
	 * @return string Cache prefix, or the original text when it does not parse
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function resolveCachePrefix(string $referenceText): string;

	/**
	 * Resolve a matched reference URL into a rich `IReference`.
	 *
	 * @param string $referenceText The matched URL
	 *
	 * @return IReference|null The reference object or null on failure
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function buildReference(string $referenceText): ?IReference;

	/**
	 * The MDI icon URL of a schema, when its icon is in the curated set.
	 *
	 * @param int $schemaId The schema ID
	 *
	 * @return string|null URL through the `openregister.icon.mdi` route, or null
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function resolveMdiIconUrl(int $schemaId): ?string;

	/**
	 * The generic OpenRegister app icon.
	 *
	 * @return string URL to the app icon
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function getAppIconUrl(): string;
}//end interface
