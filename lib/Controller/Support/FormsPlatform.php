<?php

/**
 * OpenRegister forms platform
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

use OCP\IL10N;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the forms API.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */
final class FormsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession The signed-in subject, if any.
	 * @param IThrottler      $throttler   Counts guesses at form ids.
	 * @param IL10N           $l10n        Translations.
	 * @param LoggerInterface $logger      Logs a throttler failure.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IThrottler $throttler,
		public readonly IL10N $l10n,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
