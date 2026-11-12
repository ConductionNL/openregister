<?php

/**
 * PublicTimelineInterface: the public surface of PublicTimeline, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
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

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of PublicTimeline. Callers depend on this interface, not on the class.
 */
interface PublicTimelineInterface {

	/**
	 * The public entries on one object, newest first, projected.
	 *
	 * The filter is fixed at `public` and is not a parameter, so no caller can
	 * ask this class for the internal half.
	 *
	 * @param ObjectEntity $object The object the timeline hangs on.
	 * @param integer      $limit  How many entries at most.
	 *
	 * @return array<int, array<string, mixed>> The entries, each with exactly the keys in {@see PublicTimeline::KEYS}.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function forObject(ObjectEntity $object, int $limit = 50): array;
}//end interface
