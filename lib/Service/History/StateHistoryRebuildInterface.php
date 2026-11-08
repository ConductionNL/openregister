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

/**
 * Rebuilding one object's state-history line, as the rebuild job does. Only the method that caller calls.
 */
interface StateHistoryRebuildInterface {

	/**
	 * Rebuild one object's line.
	 *
	 * @param string $objectUuid The object.
	 * @param string $property   The declared lifecycle property.
	 * @param string $register   The register slug.
	 * @param string $schema     The schema slug.
	 *
	 * @return int Intervals written.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function rebuildObject(string $objectUuid, string $property, string $register, string $schema): int;
}//end interface
