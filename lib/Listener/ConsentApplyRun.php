<?php

/**
 * OpenRegister ConsentApplyRun
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
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Listener\Support\ConsentSaveContext;

/**
 * One write's pass over its consent-shaped properties, property by property.
 *
 * Built by {@see ConsentEnvelopeOnSaveListener} once the write has consent
 * properties; it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */
final class ConsentApplyRun {

	/**
	 * The save: its event, the consent properties, the submitted and the persisted payload.
	 *
	 * @var ConsentSaveContext|null
	 */
	public ?ConsentSaveContext $context = null;

	/**
	 * The incoming payload, rewritten as properties are evaluated.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The name of the consent property being evaluated.
	 *
	 * @var string
	 */
	public string $property = '';
}//end class
