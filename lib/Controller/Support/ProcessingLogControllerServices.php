<?php

/**
 * OpenRegister processing log controller services
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

use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCP\IAppConfig;

/**
 * The processing log store and the app config its retention policy is read from.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final readonly class ProcessingLogControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ProcessingLogMapperInterface $logMapper Processing log store.
	 * @param IAppConfig                   $appConfig App config the policy reads.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public ProcessingLogMapperInterface $logMapper,
		public IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
