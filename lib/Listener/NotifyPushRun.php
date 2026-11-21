<?php

/**
 * OpenRegister NotifyPushRun
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
 * @spec openspec/specs/realtime-updates/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One object's change being pushed to its readers.
 *
 * Built by {@see NotifyPushListener} once the queue resolved; it never leaves
 * that class (ADR-011 Rule 3, modelled on CascadeRun). The readers are the
 * object's own, resolved once, so every channel of one push reaches the same
 * audience.
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */
final class NotifyPushRun {

	/**
	 * Resolved IQueue instance.
	 *
	 * @var object|null
	 */
	public ?object $queue = null;

	/**
	 * Action verb (`create`, `update`, `delete`, `presence`).
	 *
	 * @var string
	 */
	public string $action = '';

	/**
	 * The affected object entity.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The push body.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * Recipients: the object's readers.
	 *
	 * @var array<int, string>
	 */
	public array $readers = [];
}//end class
