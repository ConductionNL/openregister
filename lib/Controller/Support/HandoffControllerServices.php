<?php

/**
 * OpenRegister handoff controller services
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

use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the handoff API.
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */
final class HandoffControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param HandoffServiceInterface $handoffService The handoff engine.
	 * @param LoggerInterface         $logger         Structured logging.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 */
	public function __construct(
		public readonly HandoffServiceInterface $handoffService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
