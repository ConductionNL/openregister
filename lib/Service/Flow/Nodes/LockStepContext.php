<?php

/**
 * What the lock and unlock steps read from their run before touching a lock.
 *
 * Both steps refuse to act until they know three things, and they work them
 * out the same way: the run the lock belongs to, the user the run acts as —
 * who must exist and be enabled, because a lock must be attributable — and
 * the objects the received items name. Those checks are kept here once, so the
 * two steps cannot drift apart on who may take or release a lock. Each step
 * still words its own refusals and runs the checks before any lock call.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use RuntimeException;

/**
 * Run, owner and target resolution shared by LockObjectNode and UnlockObjectNode.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class LockStepContext {
	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves the run's acting identity.
	 * @param IL10N        $l10n        Translations for the shared refusals.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The run this step belongs to, which the lock is held for.
	 *
	 * @param array  $context The run context.
	 * @param string $refusal The translated refusal when the run did not identify itself.
	 *
	 * @return string The run UUID.
	 *
	 * @throws RuntimeException When the context names no run.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function runUuid(array $context, string $refusal): string {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			throw new RuntimeException($refusal);
		}

		return $runUuid;
	}//end runUuid()

	/**
	 * The user the run acts as: named, existing and enabled.
	 *
	 * @param array  $context The run context.
	 * @param string $refusal The translated refusal when the run names no acting identity.
	 *
	 * @return IUser The acting user.
	 *
	 * @throws RuntimeException When the identity is missing, unknown or disabled.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function owner(array $context, string $refusal): IUser {
		$uid = ($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException($refusal);
		}

		$user = $this->userManager->get(trim($uid));
		if ($user === null) {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as "%s", which is not a user account.', [trim($uid)])
			);
		}

		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				$this->l10n->t('This flow run acts as "%s", whose account is disabled.', [trim($uid)])
			);
		}

		return $user;
	}//end owner()

	/**
	 * The distinct objects the items name: the configured `uuid` template when
	 * set, otherwise each item's own `uuid`.
	 *
	 * @param array  $items   The input items.
	 * @param array  $config  The step configuration.
	 * @param string $refusal The translated refusal when an item names no object.
	 *
	 * @return array<int, string> The target UUIDs, first occurrence first.
	 *
	 * @throws RuntimeException When an item resolves to no object.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function targets(array $items, array $config, string $refusal): array {
		$targets = [];
		foreach ($items as $item) {
			$uuid = $this->targetOf(item: $item, configured: ($config['uuid'] ?? null));
			if ($uuid === '') {
				throw new RuntimeException($refusal);
			}

			$targets[$uuid] = $uuid;
		}

		return array_values($targets);
	}//end targets()

	/**
	 * The object one item names, or an empty string when it names none.
	 *
	 * @param mixed $item       The item.
	 * @param mixed $configured The configured `uuid` template, if any.
	 *
	 * @return string The target UUID, empty when the item resolves to no object.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function targetOf(mixed $item, mixed $configured): string {
		$json = ($item[FlowItems::JSON] ?? []);
		if (is_array($json) === false) {
			$json = [];
		}

		$uuid = trim((string)($json['uuid'] ?? ''));
		if (is_string($configured) === true && trim($configured) !== '') {
			$uuid = trim((string)FlowValueTemplate::render($configured, $json));
		}

		return $uuid;
	}//end targetOf()
}//end class
