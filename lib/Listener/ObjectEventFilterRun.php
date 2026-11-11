<?php

/**
 * OpenRegister ObjectEventFilterRun
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
 * One declared filter (registers or schemas) being compared against the
 * written object's register or schema id.
 *
 * Built by {@see ObjectEventProxyListener} per filter it checks; it never
 * leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ObjectEventFilterRun {

	/**
	 * The written object's register or schema id, null when it carries none.
	 *
	 * @var string|null
	 */
	public ?string $id = null;

	/**
	 * True when this is the schema filter, false for the register filter.
	 *
	 * @var boolean
	 */
	public bool $isSchema = false;
}//end class
