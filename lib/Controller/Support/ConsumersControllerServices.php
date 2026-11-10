<?php

/**
 * OpenRegister ConsumersController services
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

use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCP\IL10N;

/**
 * The collaborators of ConsumersController, wired by DI autowiring.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class ConsumersControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConsumerMapperInterface $consumerMapper The consumer database mapper
	 * @param IL10N                   $l10n           The localization service
	 */
	public function __construct(
		public readonly ConsumerMapperInterface $consumerMapper,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
