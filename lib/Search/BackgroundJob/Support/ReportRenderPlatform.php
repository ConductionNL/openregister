<?php

/**
 * OpenRegister report render platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
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

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The kill switch reader and logger of the scheduled render pass.
 *
 * @spec openspec/specs/rapportage-bi-export/spec.md
 */
final class ReportRenderPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig      $appConfig App-config reader.
	 * @param LoggerInterface $logger    Logger.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
