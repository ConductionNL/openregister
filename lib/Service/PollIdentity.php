<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The NC Polls poll a link points to: its id, title and type.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class PollIdentity {
	/**
	 * Constructor.
	 *
	 * @param int    $id    Polls poll id.
	 * @param string $title Poll title.
	 * @param string $type  Poll type.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $type,
	) {
	}//end __construct()
}//end class
