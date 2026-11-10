<?php

/**
 * StateHistoryRebuildInterface: what callers outside the Service component ask of StateHistoryRebuild.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\History
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

namespace OCA\OpenRegister\Service\History;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * Rebuilding one object's state-history line, as the rebuild job does. Only the method that caller calls.
 */
interface StateHistoryRebuildInterface {

	/**
	 * Rebuild one object's line.
	 *
	 * @param ObjectRef $ref      The object, with its register and schema slugs.
	 * @param string    $property The declared lifecycle property.
	 *
	 * @return int Intervals written.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function rebuild(ObjectRef $ref, string $property): int;
}//end interface
