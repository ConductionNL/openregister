<?php

/**
 * OpenRegister UniqueConstraintRun
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
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * One write being checked against a schema's named uniqueness constraints.
 *
 * Built by {@see UniqueConstraintListener} once the constraints resolved;
 * it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class UniqueConstraintRun {

	/**
	 * The write event.
	 *
	 * @var ObjectCreatingEvent|ObjectUpdatingEvent|null
	 */
	public ObjectCreatingEvent|ObjectUpdatingEvent|null $event = null;

	/**
	 * The object the write carries.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The schema being written against.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The object's data.
	 *
	 * @var array<string,mixed>
	 */
	public array $data = [];
}//end class
