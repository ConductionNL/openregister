<?php

/**
 * ConfigurationMapperBindings: the map of the Configuration area's mapper interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\Core\TranslationRowStatusInterface;

/**
 * Interface => mapper map of the Configuration area, behind ConfigurationMapperRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class ConfigurationMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ApplicationMapperInterface::class                 => ApplicationMapper::class,
		ConfigurationBindingMapperInterface::class        => ConfigurationBindingMapper::class,
		ConfigurationDeploymentMapperInterface::class     => ConfigurationDeploymentMapper::class,
		ConfigurationDraftMapperInterface::class          => ConfigurationDraftMapper::class,
		ConfigurationDraftSetMapperInterface::class       => ConfigurationDraftSetMapper::class,
		ConfigurationMapperInterface::class               => ConfigurationMapper::class,
		ExportProfileMapperInterface::class               => ExportProfileMapper::class,
		ImportPreviewFinderInterface::class               => ImportPreviewMapper::class,
		ImportPreviewMapperInterface::class               => ImportPreviewMapper::class,
		ImportPreviewRowMapperInterface::class            => ImportPreviewRowMapper::class,
		JobRunMapperInterface::class                      => JobRunMapper::class,
		MigrationPackMapperInterface::class               => MigrationPackMapper::class,
		RecordTemplateMapperInterface::class              => RecordTemplateMapper::class,
		TranslationRowStatusInterface::class              => TranslationMapper::class,
	];
}//end class
