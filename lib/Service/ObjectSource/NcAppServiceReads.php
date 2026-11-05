<?php

/**
 * NcAppServiceReads: the installed-app check and the defensive service and getter reads of a provider over another Nextcloud app.
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
 * Reads into another Nextcloud app that may be missing or change shape.
 *
 * The using provider declares `REQUIRED_APP` and provides `$this->appManager`
 * (IAppManager), `$this->container` (ContainerInterface), `$this->logger`
 * (LoggerInterface) and `getId()`.
 */
trait NcAppServiceReads {

	/**
	 * Whether the backing app is installed; false when the check itself fails.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function isEnabled(): bool {
		try {
			return $this->appManager->isInstalled(self::REQUIRED_APP);
		} catch (Throwable $e) {
			return false;
		}
	}//end isEnabled()

	/**
	 * Resolve one of the app's service classes from the container, or null when
	 * the app is not installed or its service cannot be resolved.
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
			$this->logger->warning('[ObjectSource:' . $this->getId() . '] could not resolve ' . $class . ': ' . $e->getMessage());
		}

		return null;
	}//end resolveService()

	/**
	 * Call an int-valued getter defensively.
	 *
	 * @param object $entity The entity.
	 * @param string $getter The getter name.
	 *
	 * @return int|null The value, or null when it is null or the getter fails.
	 */
	private function intGetter(object $entity, string $getter): ?int {
		try {
			$value = $entity->$getter();
			if ($value === null) {
				return null;
			}

			return (int)$value;
		} catch (Throwable $e) {
			return null;
		}
	}//end intGetter()

	/**
	 * Call a string-valued getter defensively.
	 *
	 * @param object $entity The entity.
	 * @param string $getter The getter name.
	 *
	 * @return string The value, or '' when the getter fails.
	 */
	private function stringGetter(object $entity, string $getter): string {
		try {
			return (string)$entity->$getter();
		} catch (Throwable $e) {
			return '';
		}
	}//end stringGetter()
}//end trait
