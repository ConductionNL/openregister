<?php

/**
 * OpenRegister Case Anchor
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

/**
 * The object a plan is anchored to: its uuid, and its register and schema when known.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
final readonly class CaseAnchor {

	/**
	 * The anchor.
	 *
	 * @param string $objectUuid The anchoring object.
	 * @param int|null $registerId Its register.
	 * @param int|null $schemaId Its schema.
	 */
	public function __construct(
		public string $objectUuid,
		public ?int $registerId,
		public ?int $schemaId,
	) {
	}//end __construct()
}//end class
