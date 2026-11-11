<?php

/**
 * OpenRegister UniqueHintCandidate
 *
 * The object a uniqueness hint is checked for: where it is saved, its data, and its own uuid when it already exists.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * The scope, the incoming data and the object's own uuid of one uniqueness-hint check.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
final class UniqueHintCandidate {

	/**
	 * Constructor.
	 *
	 * @param UniqueHintScope      $scope    The register and schema the object is saved into.
	 * @param array<string, mixed> $data     The incoming object data.
	 * @param string|null          $selfUuid The object's own uuid on update, excluded from the matches.
	 */
	public function __construct(
		public readonly UniqueHintScope $scope,
		public readonly array $data,
		public readonly ?string $selfUuid,
	) {
	}//end __construct()
}//end class
