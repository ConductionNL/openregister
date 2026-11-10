<?php

/**
 * OpenRegister CreateCandidate
 *
 * A body about to be created, checked against its schema's duplicate rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * The register and schema a body is created in, the body, and whether the caller asked to save through a match.
 *
 * @spec openspec/specs/duplicate-detection/spec.md
 */
final class CreateCandidate {

	/**
	 * Constructor.
	 *
	 * @param int|string|null      $register          Register reference: the boundary the schema resolves inside.
	 * @param int|string|null      $schema            Schema reference.
	 * @param array<string, mixed> $data              The candidate body, override flag already removed.
	 * @param bool                 $overrideRequested Whether the caller asked to save anyway.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function __construct(
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly array $data,
		public readonly bool $overrideRequested,
	) {
	}//end __construct()
}//end class
