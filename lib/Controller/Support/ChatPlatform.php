<?php

/**
 * OpenRegister chat platform
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

use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the chat API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IDBConnection   $db     Database connection for direct queries
	 * @param LoggerInterface $logger Logger for error tracking
	 * @param IL10N           $l10n   Localization service for translations
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly LoggerInterface $logger,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
