<?php

/**
 * OpenRegister SharedSchemaPlanRun
 *
 * The registers and owner overrides one inspect() run plans against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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

use OCA\OpenRegister\Db\Register;

/**
 * The state of one inspect() run inside SharedSchemaDedupeService.
 *
 * Built by the entry point and never handed outside the service (ADR-011 Rule 3).
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SharedSchemaPlanRun {

	/**
	 * Constructor.
	 *
	 * @param array<int, Register>      $registers Every register, keyed by id.
	 * @param array<array-key, mixed> $keep      Operator owner overrides (schemaId => registerId).
	 */
	public function __construct(
		public readonly array $registers,
		public readonly array $keep,
	) {
	}//end __construct()
}//end class
