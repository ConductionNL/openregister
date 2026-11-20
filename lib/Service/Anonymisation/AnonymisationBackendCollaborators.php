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

use OCA\OpenRegister\Kernel\Contracts\Compliance\ConnectionStatusReporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\DetectionPatternsVersionInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileSettingsReaderInterface;
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
	 * @param FileSettingsReaderInterface       $fileSettingsHandler Reads the stored entity-recognition settings.
	 * @param LoggerInterface                   $logger              Logger.
	 * @param ConnectionStatusReporterInterface $connectionReporter  Reports an OpenAnonymiser connection test.
	 * @param DetectionPatternsVersionInterface $patterns            The version of the pattern backend's patterns.
	 *
	 * @spec openspec/specs/pdf-anonymisation/spec.md
	 */
	public function __construct(
		public FileSettingsReaderInterface $fileSettingsHandler,
		public LoggerInterface $logger,
		public ConnectionStatusReporterInterface $connectionReporter,
		public DetectionPatternsVersionInterface $patterns,
	) {
	}//end __construct()
}//end class
