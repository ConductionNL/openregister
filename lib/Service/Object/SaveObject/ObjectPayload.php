<?php

/**
 * What a caller sends to be saved: the object's properties and its `@self` block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The sent object: its property data and the `@self` metadata that came with it.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ObjectPayload {
	/**
	 * The payload.
	 *
	 * @param array<string, mixed> $data     The object's property data.
	 * @param array<string, mixed> $selfData The `@self` metadata.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly array $data,
		public readonly array $selfData = [],
	) {
	}//end __construct()

	/**
	 * The slug the client sent: `@self.slug`, else a top-level `slug` property.
	 *
	 * Keep in step with BulkObjectSlugs::apply(), which reads the same two places for a bulk row.
	 *
	 * @return string|null The non-empty string, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function clientSlug(): ?string {
		$slug = $this->selfData['slug'] ?? $this->data['slug'] ?? null;
		if (is_string($slug) === false || $slug === '') {
			return null;
		}

		return $slug;
	}//end clientSlug()
}//end class
