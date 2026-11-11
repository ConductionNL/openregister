<?php

/**
 * OpenRegister Subject Write Label
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
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

namespace OCA\OpenRegister\Service\Gdpr\Subject;

/**
 * How a failed subject write is named in the log: the operation and the object it was for.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
 */
final class SubjectWriteLabel {

	/**
	 * Constructor.
	 *
	 * @param string $op         Operation label for logging.
	 * @param string $identifier Identifier for logging.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $op,
		public readonly string $identifier,
	) {

	}//end __construct()
}//end class
