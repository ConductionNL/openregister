<?php

/**
 * OpenRegister GeneratedIdentifierFreezeRun
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
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * One update being checked for a changed generated identifier.
 *
 * Built by {@see GeneratedIdentifierListener} when an update has an old
 * object; it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
 */
final class GeneratedIdentifierFreezeRun {

	/**
	 * The update being prepared; it carries a refusal.
	 *
	 * @var ObjectUpdatingEvent|null
	 */
	public ?ObjectUpdatingEvent $event = null;

	/**
	 * The data before the update.
	 *
	 * @var array<string, mixed>
	 */
	public array $before = [];

	/**
	 * The data the update writes.
	 *
	 * @var array<string, mixed>
	 */
	public array $after = [];
}//end class
