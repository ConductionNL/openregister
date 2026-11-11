<?php

/**
 * DecisionTableLocation: where the stored decision tables live.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
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

namespace OCA\OpenRegister\Service\Dmn;

/**
 * The register and schema slugs of the stored decision tables.
 *
 * DecisionTableStore reads them from here; callers outside the Service component
 * read them from here too, so they need not name the store.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
final class DecisionTableLocation {

	/**
	 * The register the tables live in.
	 */
	public const REGISTER = 'decision-tables';

	/**
	 * The schema of one table.
	 */
	public const SCHEMA = 'decision-table';
}//end class
