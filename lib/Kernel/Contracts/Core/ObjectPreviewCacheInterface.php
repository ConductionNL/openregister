<?php
/**
 * ObjectPreviewCacheInterface: where an object's reference previews are cached, as a save clears them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Where the Smart Picker and reference-provider previews of an object are cached.
 *
 * The object save path (Core) clears them after a save; the reference provider keeps
 * the canonical URL patterns and binds its answer in its own wiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
interface ObjectPreviewCacheInterface {

	/**
	 * Every canonical URL of an object, one per pattern the reference providers match.
	 *
	 * @param ObjectRef $ref The object's register, schema and uuid.
	 *
	 * @return string[] The canonical URLs, in pattern order.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function buildCanonicalUrls(ObjectRef $ref): array;

	/**
	 * The cache prefix a reference URL is cached under; text that is no object URL is its own prefix.
	 *
	 * @param string $referenceText The reference URL.
	 *
	 * @return string The cache prefix.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resolveCachePrefix(string $referenceText): string;
}//end interface
