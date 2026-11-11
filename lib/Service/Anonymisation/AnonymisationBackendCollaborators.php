<?php

/**
 * The app collaborators the anonymisation backend selection reports through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use Psr\Log\LoggerInterface;

/**
 * The collaborator bundle of {@see AnonymisationBackendService}.
 *
 * @spec openspec/specs/pdf-anonymisation/spec.md
 */
final readonly class AnonymisationBackendCollaborators {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FileSettingsHandler $fileSettingsHandler Reads the stored entity-recognition settings.
	 * @param LoggerInterface     $logger              Logger.
	 * @param ConnectionReporter  $connectionReporter  Reports an OpenAnonymiser connection test.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function __construct(
		public FileSettingsHandler $fileSettingsHandler,
		public LoggerInterface $logger,
		public ConnectionReporter $connectionReporter,
	) {
	}//end __construct()
}//end class
