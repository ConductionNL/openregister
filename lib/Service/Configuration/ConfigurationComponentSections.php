<?php

/**
 * The component sections a configuration document shares with OpenConnector.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

namespace OCA\OpenRegister\Service\Configuration;

/**
 * The empty OpenConnector component sections, in output order, that both an
 * export and a preview of a configuration start with.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationComponentSections {

	/**
	 * The OpenConnector sections, empty, in output order.
	 *
	 * @var array<string, array>
	 */
	public const CONNECTOR_SECTIONS = [
		'endpoints' => [],
		'sources' => [],
		'mappings' => [],
		'jobs' => [],
		'synchronizations' => [],
		'rules' => [],
	];
}//end class
