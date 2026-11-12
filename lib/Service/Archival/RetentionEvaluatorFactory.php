<?php

/**
 * RetentionEvaluatorFactory: builds a RetentionEvaluator for callers outside the component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Psr\Log\LoggerInterface;

/**
 * Builds the retention evaluator, so a caller outside the component never names the class.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final class RetentionEvaluatorFactory {

	/**
	 * A retention evaluator that logs malformed conditions to the given logger.
	 *
	 * @param LoggerInterface $logger Logger for malformed-condition warnings
	 *
	 * @return RetentionEvaluatorInterface
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function create(LoggerInterface $logger): RetentionEvaluatorInterface {
		return new RetentionEvaluator(logger: $logger);
	}//end create()
}//end class
