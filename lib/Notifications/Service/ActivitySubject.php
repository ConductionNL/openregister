<?php

/**
 * The entity an activity is about.
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

/**
 * The kind, id, title and owner of the entity an activity is about.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ActivitySubject {
	/**
	 * Constructor.
	 *
	 * @param string      $kind  The entity kind: `object`, `register` or `schema`.
	 * @param string      $id    The entity id.
	 * @param string      $title The title the activity shows.
	 * @param string|null $owner The entity's owner.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $id,
		public readonly string $title,
		public readonly ?string $owner,
	) {
	}//end __construct()
}//end class
