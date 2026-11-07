<?php

/**
 * What changed when a note was rewritten.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Notifications\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

/**
 * A rewritten note: the comment, and the parts that changed.
 */
class NoteRewrite {

	/**
	 * Constructor.
	 *
	 * @param integer     $commentId  The comment that was rewritten.
	 * @param string|null $message    The new text, or null when it did not change.
	 * @param string|null $visibility The new flag, or null when it did not change.
	 */
	public function __construct(
		public readonly int $commentId,
		public readonly ?string $message = null,
		public readonly ?string $visibility = null,
	) {
	}//end __construct()
}//end class
