<?php

/**
 * One configuration export by ExportHandler.
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

use OCA\OpenRegister\Db\Configuration;

/**
 * The export document being built, the configuration it reads from, and the
 * two choices the caller made for it.
 *
 * Built by ExportHandler::exportConfig() and never leaves that class.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationExportRun {

	/**
	 * The OpenAPI document built so far.
	 *
	 * @var array<string, mixed>
	 */
	public array $openApiSpec = [];

	/**
	 * The configuration the registers and mappings come from, or null for a register export.
	 *
	 * @var Configuration|null
	 */
	public ?Configuration $configuration = null;

	/**
	 * Whether each register's objects are exported too.
	 *
	 * @var boolean
	 */
	public bool $includeObjects;

	/**
	 * The OpenConnector service whose register export the document is merged over, if any.
	 *
	 * @var object|null
	 */
	public ?object $openConnectorService;

	/**
	 * Start an export.
	 *
	 * @param bool        $includeObjects       Whether each register's objects are exported too.
	 * @param object|null $openConnectorService The OpenConnector service, if any.
	 *
	 * @return void
	 */
	public function __construct(bool $includeObjects, ?object $openConnectorService) {
		$this->includeObjects       = $includeObjects;
		$this->openConnectorService = $openConnectorService;
	}//end __construct()
}//end class
