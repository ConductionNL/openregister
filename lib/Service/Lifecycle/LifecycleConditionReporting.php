<?php

/**
 * How a lifecycle condition reports what it refused: the engine's translated fallback message and the diagnostic log.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * How a lifecycle condition reports what it refused: the engine's translated fallback message and the diagnostic log.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleConditionReporting {
	/**
	 * Bundle the services.
	 *
	 * @param IL10N                $l10n    Translation layer for the engine's own refusal message.
	 * @param LoggerInterface      $logger  Logs refusals so a bad rule is diagnosable.
	 */
	public function __construct(
		public readonly IL10N $l10n,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
