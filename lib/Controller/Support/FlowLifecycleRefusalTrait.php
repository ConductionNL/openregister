<?php

/**
 * OpenRegister flow lifecycle refusal answer
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

use OCA\OpenRegister\Service\Flow\FlowLifecycleRefusedInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The 409 every flow controller answers when a flow's lifecycle state refuses a request.
 */
trait FlowLifecycleRefusalTrait {
	/**
	 * A lifecycle refusal as a 409 the editor can act on.
	 *
	 * 🔑 THE REASON IS A FIELD, NOT PROSE. "This version is published, create a
	 * draft" and "this flow has no published version, publish one" want
	 * opposite buttons from the author; parsing an English sentence to tell
	 * them apart is how a UI ends up offering the wrong one.
	 *
	 * @param FlowLifecycleRefusedInterface $refusal The refusal.
	 *
	 * @return JSONResponse The 409.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	private function lifecycleRefusal(FlowLifecycleRefusedInterface $refusal): JSONResponse {
		return new JSONResponse(
			[
				'error' => $refusal->getMessage(),
				'reason' => $refusal->getReason(),
				'lifecycleStatus' => $refusal->getState(),
				'flowId' => $refusal->getFlowId(),
			],
			Http::STATUS_CONFLICT
		);
	}//end lifecycleRefusal()
}//end trait
