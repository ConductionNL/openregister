<?php

/**
 * ContainerServiceLookup: a service of another Nextcloud app, resolved from the container or null.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Throwable;

/**
 * resolveService() for a class that reads another app through its services.
 *
 * The using class declares `SOURCE_LABEL` (the label its warnings carry) and
 * provides `$this->container` (ContainerInterface) and `$this->logger` (LoggerInterface).
 */
trait ContainerServiceLookup {

	/**
	 * Resolve one of the backing app's service classes from the container, or null
	 * when the app is not installed or its service cannot be resolved.
	 *
	 * @param string $class The fully-qualified service class name.
	 *
	 * @return object|null The service, or null.
	 */
	private function resolveService(string $class): ?object {
		if (class_exists($class) === false) {
			return null;
		}

		try {
			$service = $this->container->get($class);
			if (is_object($service) === true) {
				return $service;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ObjectSource:' . self::SOURCE_LABEL . '] could not resolve ' . $class . ': ' . $e->getMessage());
		}

		return null;
	}//end resolveService()
}//end trait
