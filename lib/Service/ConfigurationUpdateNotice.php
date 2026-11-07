<?php

/**
 * OpenRegister Configuration Update Notice
 *
 * What one configuration-update notification says: which configuration, and
 * from which version to which.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The content of one configuration-update notification.
 */
final class ConfigurationUpdateNotice {
	/**
	 * @param string      $configurationTitle The configuration title
	 * @param int         $configurationId    The configuration ID
	 * @param string|null $currentVersion     The current/local version, when known
	 * @param string|null $newVersion         The new/remote version, when known
	 */
	public function __construct(
		public readonly string $configurationTitle,
		public readonly int $configurationId,
		public readonly ?string $currentVersion,
		public readonly ?string $newVersion,
	) {
	}//end __construct()

	/**
	 * The notification subject parameters; an unknown version reads `unknown`.
	 *
	 * @return array{configurationTitle: string, configurationId: int, currentVersion: string, newVersion: string}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function subjectParameters(): array {
		return [
			'configurationTitle' => $this->configurationTitle,
			'configurationId' => $this->configurationId,
			'currentVersion' => $this->currentVersion ?? 'unknown',
			'newVersion' => $this->newVersion ?? 'unknown',
		];
	}//end subjectParameters()
}//end class
