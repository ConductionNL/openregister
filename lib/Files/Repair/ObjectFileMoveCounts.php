<?php

/**
 * OpenRegister ObjectFileMoveCounts
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Files\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Repair;

/**
 * The sentence the repair step and the occ command report an object file move's counts in.
 *
 * @spec exclude reporting plumbing shared by the repair step and the command, not a capability of its own
 */
final class ObjectFileMoveCounts {

	/**
	 * The counts of a move tally.
	 *
	 * @param array{foldersMoved: int, filesMoved: int, sharesReowned: int, foldersLeft: int} $tally The tally.
	 *
	 * @return string The sentence.
	 *
	 * @spec exclude reporting plumbing, not a capability of its own
	 */
	public function sentence(array $tally): string {
		return sprintf(
			'%d folders moved, %d files moved, %d published links re-owned, %d left where they are',
			$tally['foldersMoved'],
			$tally['filesMoved'],
			$tally['sharesReowned'],
			$tally['foldersLeft']
		);
	}//end sentence()
}//end class
