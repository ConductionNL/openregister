<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Nextcloud collaborators every integration link service uses: which apps are
 * enabled, who is asking, and where to log. Wired by DI autowiring.
 *
 * Not final: the builtin factory table resolves it from the container, and the
 * registration tests double every entry that table names.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
class LinkServiceRuntime {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppManager     $appManager  Whether the integrated app is installed and enabled.
	 * @param IUserSession    $userSession The current user.
	 * @param LoggerInterface $logger      The logger.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The app manager.
	 *
	 * @return IAppManager Whether the integrated app is installed and enabled.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function appManager(): IAppManager {
		return $this->appManager;
	}//end appManager()

	/**
	 * The user session.
	 *
	 * @return IUserSession The current user.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function userSession(): IUserSession {
		return $this->userSession;
	}//end userSession()

	/**
	 * The logger.
	 *
	 * @return LoggerInterface The logger.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function logger(): LoggerInterface {
		return $this->logger;
	}//end logger()
}//end class
