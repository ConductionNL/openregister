<?php

/**
 * ConfigurationCollaborators: the configuration helpers the register endpoint builds or looks up.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Container\ContainerInterface;

/**
 * Does for RegistersController what it did itself with two configuration helpers.
 *
 * It built RegisterBundleBinding with `new` and looked AppImportJobRecorder up
 * in the container by its class id. This class makes exactly those two calls,
 * the lookup under the same id and handed back as the container returns it,
 * so the controller names neither class.
 */
final class ConfigurationCollaborators {

	/**
	 * A fresh register/bundle binding check over the given schema mapper.
	 *
	 * @param SchemaMapperInterface $schemaMapper Reads the schemas of the register.
	 *
	 * @return RegisterBundleBindingInterface
	 */
	public static function registerBundleBinding(SchemaMapperInterface $schemaMapper): RegisterBundleBindingInterface {
		return new RegisterBundleBinding(schemaMapper: $schemaMapper);
	}//end registerBundleBinding()

	/**
	 * The shared app import job recorder, exactly as the container returns it.
	 *
	 * @param ContainerInterface $container The container the caller holds.
	 *
	 * @return mixed The shared AppImportJobRecorder instance.
	 */
	public static function appImportJobsFrom(ContainerInterface $container): mixed {
		return $container->get(AppImportJobRecorder::class);
	}//end appImportJobsFrom()
}//end class
