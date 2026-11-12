<?php

/**
 * OpenRegister settings runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the settings API.
 *
 * @spec openspec/specs/production-observability/spec.md
 */
final class SettingsRuntime {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface $logger The logger.
	 * @param ?IL10N          $l10n   The localization service.
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ?IL10N $l10n = null,
	) {
	}//end __construct()
}//end class
