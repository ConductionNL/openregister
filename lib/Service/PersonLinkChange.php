<?php

/**
 * OpenRegister PersonLinkChange
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A change to the role, validity or note of a person's link on an object.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-can-be-updated-and-removed-per-role
 */
final class PersonLinkChange {

	/**
	 * Constructor.
	 *
	 * @param PersonLinkRef        $ref      The object, the person, and which of their links (the current role) when they hold several.
	 * @param int|null             $schemaId The schema id, for the vocabulary.
	 * @param array<string, mixed> $changes  `role`, `validFrom`, `validUntil`, `note`; a key absent is left alone, null clears.
	 */
	public function __construct(
		public readonly PersonLinkRef $ref,
		public readonly ?int $schemaId,
		public readonly array $changes,
	) {

	}//end __construct()
}//end class
