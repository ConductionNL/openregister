<?php

/**
 * OpenRegister object task
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One task of an object, as a write names it: the object, the task URI and its calendar.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class ObjectTask {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object     The object.
	 * @param string       $taskId     The task URI.
	 * @param mixed        $calendarId The task's calendar, or null when unknown.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $taskId,
		public readonly mixed $calendarId,
	) {
	}//end __construct()
}//end class
