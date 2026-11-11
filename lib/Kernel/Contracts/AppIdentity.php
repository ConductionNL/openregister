<?php

/**
 * AppIdentity: the app id OpenRegister registers under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The app id, for code that needs it without depending on the app's bootstrap class.
 *
 * Application::APP_ID refers to this constant, so both names give the same value.
 */
final class AppIdentity {

	/**
	 * The app id in appinfo/info.xml.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';
}//end class
