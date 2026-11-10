<?php

/**
 * Where a form keeps what it holds between requests, and the clock that ages it: upload tokens and remembered submit answers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;

/**
 * Where a form keeps what it holds between requests, and the clock that ages it: upload tokens and remembered submit answers.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-upload-tokens-must-hold-bytes-only-and-expire
 */
final class FormFileStorage {
	/**
	 * Bundle the services.
	 *
	 * @param IAppData          $appData  The app's data store.
	 * @param ITimeFactory      $time     The clock.
	 */
	public function __construct(
		public readonly IAppData $appData,
		public readonly ITimeFactory $time,
	) {
	}//end __construct()
}//end class
