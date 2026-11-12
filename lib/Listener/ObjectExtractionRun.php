<?php

/**
 * OpenRegister ObjectExtractionRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

/**
 * One changed object being handed to text extraction in the configured mode.
 *
 * Built by {@see ObjectChangeListener} once the mode was read and the object
 * has an id; it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ObjectExtractionRun {

	/**
	 * Extraction mode (immediate, background, cron, manual, or unknown).
	 *
	 * @var string
	 */
	public string $mode = '';

	/**
	 * Object ID to process.
	 *
	 * @var integer
	 */
	public int $objectId = 0;

	/**
	 * Object UUID for logging.
	 *
	 * @var string
	 */
	public string $objectUuid = '';
}//end class
