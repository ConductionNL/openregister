<?php

/**
 * OpenRegister ObjectSlugClientValue
 *
 * How a client-sent slug is read (#4037), kept apart from the rules in
 * ObjectSlugHandler so each one stays small.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * A client slug as the candidate rule stores it, and its comparison with a stored slug.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
 */
class ObjectSlugClientValue {

	/**
	 * The width of the slug column.
	 */
	public const MAX_LENGTH = 255;

	/**
	 * Constructor.
	 *
	 * @param MetadataHydrationHandler $hydrator The slugify routine that produced every stored slug.
	 */
	public function __construct(
		private readonly MetadataHydrationHandler $hydrator,
	) {
	}//end __construct()

	/**
	 * A client slug as `candidate()` stores it.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 *
	 * @return string|null The slugified, column-width slug; null when nothing was sent
	 *                     or the value slugifies to nothing (it then counts as not sent).
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function normalise(?string $clientSlug): ?string {
		if ($clientSlug === null || trim($clientSlug) === '') {
			return null;
		}

		$normalised = $this->hydrator->createSlug(text: trim($clientSlug));
		if ($normalised === '') {
			return null;
		}

		return substr($normalised, 0, self::MAX_LENGTH);
	}//end normalise()

	/**
	 * Whether a client slug asks for something other than the stored slug.
	 *
	 * Compared after the same normalisation `candidate()` applies, so re-sending
	 * the slug the object was created with (in another case or format) is not a
	 * change request.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 * @param string      $stored     The stored slug.
	 *
	 * @return bool True when the client asked for a different slug.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function differsFromStored(?string $clientSlug, string $stored): bool {
		if ($clientSlug === null || $clientSlug === '' || $clientSlug === $stored) {
			return false;
		}

		$normalised = $this->normalise(clientSlug: $clientSlug);
		return $normalised !== null && $normalised !== $stored;
	}//end differsFromStored()
}//end class
