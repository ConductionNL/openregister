<?php

/**
 * DestructionListCreatorInterface: what callers outside the component use of DestructionListCreator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use InvalidArgumentException;

/**
 * The destruction list the archival endpoints create for a nomination.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DestructionListCreatorInterface {

	/**
	 * Create a destruction list for the eligible objects among these uuids.
	 *
	 * @param array<int, string> $uuids The objects the caller wants destroyed.
	 *
	 * @return array{list: array<string, mixed>|null, refused: array<int, array{uuid: string, reason: string}>}
	 *         The stored list (with its `uuid`), or null when nothing was eligible, and every refused uuid.
	 *
	 * @throws InvalidArgumentException When no destruction-list register and schema are configured.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function createFor(array $uuids): array;
}//end interface
