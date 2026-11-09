<?php

/**
 * Renders an engine task as a VCALENDAR document holding one VTODO.
 *
 * The property table of design D-5 (flow-task-inbox-projections): SUMMARY
 * from the display title, DUE from `due_at`, PRIORITY and STATUS through the
 * published mapping, `URL` deep-linking to the task, and the two identities
 * that make write-back addressable. A pure function of the task row; the
 * projector decides where the document goes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\Task;
use OCP\IURLGenerator;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;

/**
 * The VTODO renderer behind {@see TaskCalendarProjector::render()}.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
 */
final class TaskVtodoRenderer {

	/**
	 * Constructor.
	 *
	 * @param TaskInboxService $inbox Display titles, synthesized where needed.
	 * @param IURLGenerator $urlGenerator Builds the deep link.
	 */
	public function __construct(
		private readonly TaskInboxService $inbox,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Render the VTODO for a task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The VCALENDAR document.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-assigned-task-appears-in-the-assignees-own-calendar
	 */
	public function render(Task $task): string {
		$title = $this->inbox->displayTitle(task: $task, subject: null);
		$vcalendar = self::newCalendar();

		// No defaults: VObject would otherwise stamp its own UID and DTSTAMP
		// beside ours, and the UID is the task uuid by contract.
		$vtodo = $vcalendar->createComponent('VTODO', [], false);
		$vcalendar->add($vtodo);
		$vtodo->add('UID', (string)$task->getUuid());
		$vtodo->add('DTSTAMP', (new DateTime('now', new DateTimeZone('UTC'))));
		$vtodo->add('SUMMARY', $title);

		self::addSchedule(vtodo: $vtodo, task: $task);
		self::addCompletion(vtodo: $vtodo, task: $task);
		$this->addIdentity(vtodo: $vtodo, task: $task);
		self::addObjectLink(vtodo: $vtodo, task: $task, title: $title);

		return $vcalendar->serialize();
	}//end render()

	/**
	 * An empty calendar carrying this app's PRODID.
	 *
	 * @return VCalendar The calendar.
	 */
	private static function newCalendar(): VCalendar {
		$vcalendar = new VCalendar();
		$vcalendar->remove('PRODID');
		$vcalendar->add('PRODID', '-//OpenRegister//Tasks//EN');

		return $vcalendar;
	}//end newCalendar()

	/**
	 * DESCRIPTION, STATUS, PRIORITY and DUE, in that order.
	 *
	 * @param Component $vtodo The VTODO being built.
	 * @param Task $task The task.
	 *
	 * @return void
	 */
	private static function addSchedule(Component $vtodo, Task $task): void {
		$description = trim((string)$task->getDescription());
		if ($description !== '') {
			$vtodo->add('DESCRIPTION', $description);
		}

		$vtodo->add('STATUS', TaskVtodoStatusMapping::render(state: (string)$task->getState()));
		$vtodo->add('PRIORITY', (string)TaskVtodoStatusMapping::priority(priority: $task->getPriority()));

		$due = $task->getDueAt();
		if ($due instanceof DateTimeInterface) {
			$vtodo->add('DUE', DateTime::createFromInterface($due)->setTimezone(new DateTimeZone('UTC')));
		}
	}//end addSchedule()

	/**
	 * COMPLETED and PERCENT-COMPLETE, for a completed task only.
	 *
	 * @param Component $vtodo The VTODO being built.
	 * @param Task $task The task.
	 *
	 * @return void
	 */
	private static function addCompletion(Component $vtodo, Task $task): void {
		if ((string)$task->getState() !== Task::STATE_COMPLETED) {
			return;
		}

		$utc = new DateTimeZone('UTC');
		$completedAt = ($task->getCompletedAt() ?? new DateTime('now', $utc));
		$vtodo->add('COMPLETED', DateTime::createFromInterface($completedAt)->setTimezone($utc));
		$vtodo->add('PERCENT-COMPLETE', '100');
	}//end addCompletion()

	/**
	 * The deep link and the two identities that make write-back addressable.
	 *
	 * The deep link is a surface a person can act on, never the API; the
	 * assignee is a uid, never prose.
	 *
	 * @param Component $vtodo The VTODO being built.
	 * @param Task $task The task.
	 *
	 * @return void
	 */
	private function addIdentity(Component $vtodo, Task $task): void {
		$uuid = (string)$task->getUuid();
		$vtodo->add('URL', $this->urlGenerator->linkToRouteAbsolute(TaskCalendarProjector::OPEN_ROUTE, ['uuid' => $uuid]));
		$vtodo->add(TaskCalendarProjector::PROP_TASK, $uuid);
		$vtodo->add(TaskCalendarProjector::PROP_ASSIGNEE, (string)$task->getAssignee());
	}//end addIdentity()

	/**
	 * The subject object's address and a LINK to it, when the task has a located subject.
	 *
	 * @param Component $vtodo The VTODO being built.
	 * @param Task $task The task.
	 * @param string $title The display title, the link's label.
	 *
	 * @return void
	 */
	private static function addObjectLink(Component $vtodo, Task $task, string $title): void {
		$objectUuid = trim((string)$task->getObjectUuid());
		if ($objectUuid === '' || $task->getRegisterId() === null || $task->getSchemaId() === null) {
			return;
		}

		$vtodo->add('X-OPENREGISTER-REGISTER', (string)$task->getRegisterId());
		$vtodo->add('X-OPENREGISTER-SCHEMA', (string)$task->getSchemaId());
		$vtodo->add('X-OPENREGISTER-OBJECT', $objectUuid);
		$href = sprintf('/apps/openregister/api/objects/%d/%d/%s', $task->getRegisterId(), $task->getSchemaId(), $objectUuid);
		$vtodo->add('LINK', $href, self::linkParameters(title: $title));
	}//end addObjectLink()

	/**
	 * The parameters of the subject LINK.
	 *
	 * @param string $title The display title.
	 *
	 * @return array<string, string> The parameters.
	 */
	private static function linkParameters(string $title): array {
		return [
			'LINKREL' => 'related',
			'LABEL' => $title,
			'VALUE' => 'URI',
		];
	}//end linkParameters()
}//end class
