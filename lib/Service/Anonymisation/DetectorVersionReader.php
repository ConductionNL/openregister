<?php

/**
 * OpenRegister Anonymisation Detector Version Reader
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCP\App\IAppManager;
use Throwable;

/**
 * Reads what each detection backend is built from, as far as it says.
 *
 * The pattern backend knows its pattern set's version and change day. The
 * OpenAnonymiser ExApp reports the version it is installed at. Presidio's
 * health check and the deferred LLM backend expose nothing, so those values
 * read `unknown`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */
final class DetectorVersionReader {

	/**
	 * The engine name of the built-in pattern backend.
	 */
	public const PATTERN_ENGINE = 'openregister-patterns';

	/**
	 * Build the reader.
	 *
	 * @param IAppManager $appManager Reports installed app versions.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The version record of one backend.
	 *
	 * @param string      $method  The backend method.
	 * @param string|null $exAppId The OpenAnonymiser ExApp in use, if any.
	 *
	 * @return DetectorVersion
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
	 */
	public function forMethod(string $method, ?string $exAppId=null): DetectorVersion {
		return match ($method) {
			BackendState::METHOD_REGEX => new DetectorVersion(
				fields: [
					'engine' => self::PATTERN_ENGINE,
					'engineVersion' => $this->appVersion(appId: 'openregister'),
					'patternSetVersion' => NlPatternSet::VERSION,
					'changedAt' => NlPatternSet::CHANGED_AT,
				]
			),
			BackendState::METHOD_OPENANONYMISER => new DetectorVersion(
				fields: [
					'engine' => ($exAppId ?? BackendState::METHOD_OPENANONYMISER),
					'engineVersion' => $this->appVersion(appId: $exAppId),
				]
			),
			BackendState::METHOD_HYBRID => new DetectorVersion(
				fields: ['engine' => BackendState::METHOD_HYBRID, 'patternSetVersion' => NlPatternSet::VERSION]
			),
			default => new DetectorVersion(fields: ['engine' => $method]),
		};
	}//end forMethod()

	/**
	 * The installed version of an app, or `unknown`.
	 *
	 * @param string|null $appId The app.
	 *
	 * @return string
	 */
	private function appVersion(?string $appId): string {
		if ($appId === null) {
			return DetectorVersion::UNKNOWN;
		}

		try {
			$version = $this->appManager->getAppVersion($appId);
		} catch (Throwable) {
			return DetectorVersion::UNKNOWN;
		}

		// Nextcloud answers '0' for an app it has no version of.
		if ($version === '' || $version === '0') {
			return DetectorVersion::UNKNOWN;
		}

		return $version;
	}//end appVersion()
}//end class
