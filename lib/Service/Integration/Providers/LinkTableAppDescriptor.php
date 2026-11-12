<?php

/**
 * LinkTableAppDescriptor: the descriptor of a provider whose links live in an OpenRegister link table.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Required app, storage strategy and enabled state of a link-table provider.
 *
 * The using provider declares `REQUIRED_APP` and provides `$this->appManager` (IAppManager).
 */
trait LinkTableAppDescriptor {

	/**
	 * Nextcloud app that must be installed for this integration to function.
	 *
	 * @return string|null
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22): links live in an OpenRegister link table.
	 *
	 * @return string
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	/**
	 * Whether the backing app is installed.
	 *
	 * @return bool
	 *
	 * @spec exclude Provider descriptor shared by the link-table providers; each provider spec owns the values.
	 */
	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()
}//end trait
