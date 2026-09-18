<?php

/**
 * A BPMN import that produced no flow.
 *
 * Carries the mapping report when there is one, because a strict import that
 * failed still owes the author the list of what it refused — a refusal with no
 * list is a file they have to bisect by hand.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use OCA\OpenRegister\Service\Flow\Bpmn\BpmnMappingReport;
use RuntimeException;
use Throwable;

/**
 * Raised when a BPMN file could not be imported.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class BpmnImportRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string                 $message  Why.
	 * @param BpmnMappingReport|null $report   The report, when one was built.
	 * @param Throwable|null         $previous Previous exception.
	 */
	public function __construct(
		string $message,
		private readonly ?BpmnMappingReport $report = null,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 422, previous: $previous);
	}//end __construct()

	/**
	 * The report, when the refusal came after mapping.
	 *
	 * @return BpmnMappingReport|null The report.
	 */
	public function getReport(): ?BpmnMappingReport {
		return $this->report;
	}//end getReport()
}//end class
