<?php

/**
 * OpenRegister DetectionMethod.
 *
 * Recognised values for `EntityRelation::detectionMethod`. The column
 * is a free-form string so this class is documentation + type-safety
 * only; it does NOT enforce values at the DB level.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Detection-method tags written to `EntityRelation::detectionMethod`.
 *
 * Final + abstract — constants-only utility, never instantiated.
 */
final class DetectionMethod {
	/**
	 * Presidio NER detection (default Python OpenAnonymiser backend).
	 */
	public const PRESIDIO = 'presidio';

	/**
	 * OpenAnonymiser-side custom recognisers.
	 */
	public const OPENANONYMISER = 'openanonymiser';

	/**
	 * Regex / pattern-based recogniser.
	 */
	public const PATTERN = 'pattern';

	/**
	 * Operator-supplied via the `POST /api/files/{fileId}/manual-entities`
	 * endpoint. The catalogue + relation rows are structurally identical
	 * to detection-derived rows; only this tag distinguishes them.
	 */
	public const MANUAL = 'manual';

	/**
	 * No public constructor — pure constants.
	 *
	 * @codeCoverageIgnore
	 */
	private function __construct() {

	}//end __construct()
}//end class
