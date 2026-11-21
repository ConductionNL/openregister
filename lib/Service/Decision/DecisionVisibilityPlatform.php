<?php

/**
 * OpenRegister decision visibility platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Asks the app that owns a subject, and records a listener that failed.
 *
 * @spec openspec/specs/entity-relation-grondslagen/spec.md
 */
final class DecisionVisibilityPlatform {
	/**
	 * Bundle the platform services.
	 *
	 * @param IEventDispatcher $eventDispatcher Asks the app that owns the subject (REQ-DEC-003).
	 * @param LoggerInterface  $logger          Records a listener that failed.
	 *
	 * @spec openspec/specs/entity-relation-grondslagen/spec.md
	 */
	public function __construct(
		public readonly IEventDispatcher $eventDispatcher,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
