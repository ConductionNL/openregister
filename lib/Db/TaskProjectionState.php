<?php

/**
 * What a projection last rendered for one task, and where it put it.
 *
 * Written by the projector ONLY, read by no lifecycle or authorization rule
 * (flow-task-inbox-projections, design D-2 rules 6 and 8). It holds a hash
 * of the rendered content and the calendar coordinates of the VTODO, so
 * idempotency ("did anything change?"), echo suppression ("is this write my
 * own?") and drift detection ("is what is there what I rendered?") are
 * comparisons rather than guesses. If this row is wrong the worst outcome is
 * one redundant re-render.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One projection surface's rendered state for one task.
 *
 * @method string|null getTaskUuid()
 * @method void setTaskUuid(?string $taskUuid)
 * @method string|null getSurface()
 * @method void setSurface(?string $surface)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method int|null getCalendarId()
 * @method void setCalendarId(?int $calendarId)
 * @method string|null getObjectUri()
 * @method void setObjectUri(?string $objectUri)
 * @method string|null getRenderedHash()
 * @method void setRenderedHash(?string $renderedHash)
 * @method DateTime|null getRenderedAt()
 * @method void setRenderedAt(?DateTime $renderedAt)
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */
class TaskProjectionState extends Entity implements JsonSerializable {

	/**
	 * The CalDAV VTODO surface.
	 */
	public const SURFACE_CALDAV = 'caldav';

	/**
	 * The task this row projects.
	 *
	 * @var string|null
	 */
	protected ?string $taskUuid = null;

	/**
	 * Which surface (SURFACE_*).
	 *
	 * @var string|null
	 */
	protected ?string $surface = null;

	/**
	 * Whose calendar the VTODO was rendered into.
	 *
	 * @var string|null
	 */
	protected ?string $assignee = null;

	/**
	 * The CalDAV calendar id holding the VTODO.
	 *
	 * @var int|null
	 */
	protected ?int $calendarId = null;

	/**
	 * The VTODO object uri inside that calendar.
	 *
	 * @var string|null
	 */
	protected ?string $objectUri = null;

	/**
	 * Hash of the rendered, engine-owned content.
	 *
	 * @var string|null
	 */
	protected ?string $renderedHash = null;

	/**
	 * When it was rendered.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $renderedAt = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'taskUuid', type: 'string');
		$this->addType(fieldName: 'surface', type: 'string');
		$this->addType(fieldName: 'assignee', type: 'string');
		$this->addType(fieldName: 'calendarId', type: 'integer');
		$this->addType(fieldName: 'objectUri', type: 'string');
		$this->addType(fieldName: 'renderedHash', type: 'string');
		$this->addType(fieldName: 'renderedAt', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for diagnostics.
	 *
	 * @return array<string, mixed> The row as plain data.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'taskUuid' => $this->taskUuid,
			'surface' => $this->surface,
			'assignee' => $this->assignee,
			'calendarId' => $this->calendarId,
			'objectUri' => $this->objectUri,
			'renderedHash' => $this->renderedHash,
			'renderedAt' => $this->renderedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
