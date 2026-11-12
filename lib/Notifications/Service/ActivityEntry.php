<?php

/**
 * One activity to publish.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service
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

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\Activity\IEvent;

/**
 * What happened to an entity, with the parameters and link the activity carries.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ActivityEntry {
	/**
	 * Constructor.
	 *
	 * @param ActivitySubject      $about      The entity.
	 * @param string               $action     What happened: `created`, `updated` or `deleted`.
	 * @param array<string, mixed> $parameters The subject parameters.
	 * @param string               $link       The link, empty for none.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly ActivitySubject $about,
		public readonly string $action,
		public readonly array $parameters,
		public readonly string $link,
	) {
	}//end __construct()

	/**
	 * The activity subject, `<kind>_<action>`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function subject(): string {
		return $this->about->kind . '_' . $this->action;
	}//end subject()

	/**
	 * The activity type, `openregister_<kind>s`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function type(): string {
		return 'openregister_' . $this->about->kind . 's';
	}//end type()

	/**
	 * Fill a new activity event with this activity, without its receiver.
	 *
	 * @param IEvent $event  The new event.
	 * @param string $author The acting user, empty in system context.
	 *
	 * @return IEvent
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function fill(IEvent $event, string $author): IEvent {
		$event->setApp(AppIdentity::APP_ID)
			->setType($this->type())
			->setAuthor($author)
			->setTimestamp(time())
			->setSubject($this->subject(), $this->parameters)
			->setObject($this->about->kind, (int)$this->about->id, $this->about->title);

		if ($this->link !== '') {
			$event->setLink($this->link);
		}

		return $event;
	}//end fill()
}//end class
