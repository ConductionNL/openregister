<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ContactLink;
use OCP\EventDispatcher\Event;

/**
 * A person's link on an object was removed.
 *
 * Dispatched after the write by PersonLinkService, with the link as it is
 * stored. An app that projects people on its objects (dossiq's ZGW rol)
 * listens here; nothing is dispatched on the list path.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
 */
class PersonUnlinkedEvent extends Event {

	/**
	 * @param ContactLink $link The link, a user or a contact on an object.
	 */
	public function __construct(
		private readonly ContactLink $link,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The link.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-link-changes-are-events
	 */
	public function getLink(): ContactLink {
		return $this->link;
	}//end getLink()
}//end class
