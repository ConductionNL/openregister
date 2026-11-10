<?php

/**
 * One build of the configuration ImportHandler by the registrar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The container the ImportHandler is built from, where an absent optional
 * service is noted, and the app data path it imports into.
 *
 * Built by ConfigurationServiceRegistrar::buildImportHandler() and never
 * leaves that class.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class ImportHandlerBuildRun {

	/**
	 * The container the handler and its services come from.
	 *
	 * @var ContainerInterface
	 */
	public ContainerInterface $container;

	/**
	 * Where an absent optional service is noted.
	 *
	 * @var LoggerInterface
	 */
	public LoggerInterface $logger;

	/**
	 * The app data directory the handler imports into.
	 *
	 * @var string
	 */
	public string $appDataPath = '';
}//end class
