<?php

/**
 * A lifecycle transition, or an edit, was refused by the flow's own state.
 *
 * 🔑 THE REASON IS MACHINE-READABLE, not just prose. The controller turns this
 * into a 409 whose body carries `reason` and `lifecycleStatus`, because the
 * two refusals a client must tell apart — "this version is published, make a
 * draft" and "this flow has no published version, publish one" — want opposite
 * actions from the author. A single human sentence would leave the editor
 * guessing which button to offer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use RuntimeException;

/**
 * A refusal carrying the reason a lifecycle rule rejected the request.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowLifecycleRefused extends RuntimeException {
	/**
	 * The definition of a published or deprecated version may not be changed.
	 *
	 * @var string
	 */
	public const REASON_IMMUTABLE = 'version-immutable';

	/**
	 * Only a draft may be published.
	 *
	 * @var string
	 */
	public const REASON_NOT_A_DRAFT = 'not-a-draft';

	/**
	 * Only a published version may be deprecated.
	 *
	 * @var string
	 */
	public const REASON_NOT_PUBLISHED = 'not-published';

	/**
	 * The flow has no published version, so nothing may be queued against it.
	 *
	 * @var string
	 */
	public const REASON_NO_PUBLISHED_VERSION = 'no-published-version';

	/**
	 * The graph being published has a node its token cannot leave.
	 *
	 * @var string
	 */
	public const REASON_DEAD_END = 'dead-end';

	/**
	 * A version with a run still pinned to it may not be removed.
	 *
	 * @var string
	 */
	public const REASON_VERSION_IN_USE = 'version-in-use';

	/**
	 * Constructor.
	 *
	 * @param string      $reason  One of the REASON_* constants.
	 * @param string      $flowId  The flow the refusal is about.
	 * @param string|null $state   The lifecycle state that caused it, when there is one.
	 * @param string|null $detail  Extra human detail appended to the message.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function __construct(
		private readonly string $reason,
		private readonly string $flowId,
		private readonly ?string $state = null,
		?string $detail = null,
	) {
		$messages = [
			self::REASON_IMMUTABLE => 'This version of the flow is published and cannot be edited. '
				. 'Create a draft version to make changes; the published version keeps serving until '
				. 'you publish the draft.',
			self::REASON_NOT_A_DRAFT => 'Only a draft version can be published.',
			self::REASON_NOT_PUBLISHED => 'Only a published version can be deprecated.',
			self::REASON_NO_PUBLISHED_VERSION => 'This flow has no published version, so it cannot back '
				. 'a run. Publish a version first.',
			self::REASON_DEAD_END => 'This version cannot be published while it has a node a token '
				. 'cannot leave.',
			self::REASON_VERSION_IN_USE => 'This version cannot be removed while a run is still pinned '
				. 'to it.',
		];

		$where = ' (flow ' . $flowId;
		if ($state !== null) {
			$where .= ', state ' . $state;
		}

		$where .= ')';

		$message = ($messages[$reason] ?? 'This flow lifecycle transition was refused.') . $where;

		if ($detail !== null && trim($detail) !== '') {
			$message .= ' ' . $detail;
		}

		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The machine-readable reason.
	 *
	 * @return string One of the REASON_* constants.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()

	/**
	 * The flow the refusal is about.
	 *
	 * @return string The flow uuid.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function getFlowId(): string {
		return $this->flowId;

	}//end getFlowId()

	/**
	 * The lifecycle state that caused the refusal.
	 *
	 * @return string|null The state, or null when the refusal is not about one.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function getState(): ?string {
		return $this->state;

	}//end getState()
}//end class
