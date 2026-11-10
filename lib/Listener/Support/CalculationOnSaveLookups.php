<?php

/**
 * OpenRegister CalculationOnSaveLookups
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\SequenceServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * What the save-time calculation pass looks up: the schema, the register a
 * running number is scoped to, the counter itself, and the log it warns in.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationOnSaveLookups {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface    $schemaMapper   Schema lookup mapper.
	 * @param RegisterMapperInterface  $registerMapper Register lookup mapper (for the sequence scope id).
	 * @param SequenceServiceInterface $sequences      Atomic running-number reservation service.
	 * @param LoggerInterface          $logger         PSR logger for warnings.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SequenceServiceInterface $sequences,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
