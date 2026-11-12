<?php

/**
 * LifecycleErrorBuilding: the shape of a lifecycle validation error.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Builds one lifecycle validation error.
 */
trait LifecycleErrorBuilding {

	/**
	 * One validation error.
	 *
	 * @param string $code    The error code.
	 * @param string $message The message.
	 *
	 * @return array{code: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end trait
