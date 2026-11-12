<?php

/**
 * DefensiveGetters: getters of another app's entities, called without trusting their shape.
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
 * intGetter() and stringGetter() over an entity whose getters may be missing or throw.
 */
trait DefensiveGetters {

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
