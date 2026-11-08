<?php

/**
 * OpenRegister reveal recording
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use Psr\Log\LoggerInterface;

/**
 * Where an explicit reveal is recorded: the PSR log and the per-request collector.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
final class RevealRecording {
	/**
	 * Bundle the collaborators.
	 *
	 * @param LoggerInterface  $logger  The logger.
	 * @param ?RevealCollector $reveals Records a reveal; absent, nothing is recorded.
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ?RevealCollector $reveals = null,
	) {
	}//end __construct()
}//end class
