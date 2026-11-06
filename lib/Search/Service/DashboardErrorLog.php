<?php

/**
 * Logs dashboard errors under one prefix.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

/**
 * Error logging shared by the dashboard services; the using class provides `$logger`.
 */
trait DashboardErrorLog {
	/**
	 * Log one error under the dashboard's prefix.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function logError(string $message): void {
		$this->logger->error(
			message: '[DashboardService] ' . $message,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end logError()
}//end trait
