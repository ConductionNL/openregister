<?php

/**
 * OpenRegister approval chain sequences
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Db\TaskSequenceMapperInterface;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;

/**
 * The task sequences an approval chain is stored as, the service that advances them and the installer of the chain annotation.
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-007
 */
final readonly class ApprovalChainSequences {
	/**
	 * Bundle the services.
	 *
	 * @param TaskSequenceMapperInterface      $sequenceMapper  Task sequence store.
	 * @param TaskSequenceServiceInterface     $sequenceService Advances a sequence.
	 * @param ApprovalChainAnnotationInstaller $installer       Installs the chain annotation.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	public function __construct(
		public TaskSequenceMapperInterface $sequenceMapper,
		public TaskSequenceServiceInterface $sequenceService,
		public ApprovalChainAnnotationInstaller $installer,
	) {
	}//end __construct()
}//end class
