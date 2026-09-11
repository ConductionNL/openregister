<?php

/**
 * OpenRegister AutoTransitionJob
 *
 * Actor-forwarded background job that applies an `async` automatic lifecycle
 * transition off-request, under the identity whose write decided it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a queued automatic transition, if the object has not moved on.
 *
 * DECIDE NOW, APPLY LATER IF NOTHING CHANGED. The move was decided at drain
 * time with the full four-key document. This job does NOT re-evaluate the
 * `autoWhen`, and cannot: `previous` does not fit in a job argument capped at
 * 4000 characters, and re-evaluating against a different `previous` would make
 * an async rule mean something other than the same rule written sync. Instead
 * it reconciles against current state, which is the at-least-once contract
 * `ActorForwardedJob` already documents: the move is applied only while the
 * stored version and `updated` stamp still match the decision. When they do
 * not, a newer write has made its own decision and this one is dropped.
 *
 * THE PASS TRAVELS WITH THE MOVE. The entry carries the hop count and the
 * states already visited, and the job resumes that lineage before applying, so
 * a loop cannot escape the cap by crossing into a background job — which is
 * exactly what a request-scoped counter, reset in every worker, would allow.
 *
 * IDENTITY. `ActorForwardedJob` restores the captured user and refuses one that
 * no longer resolves. This job adds the disabled-account check locally, as
 * `FlowRunAsScope` does: an automatic move must never run as an account someone
 * has deliberately switched off. Moving the base class onto ADR-099's
 * `setVolatileActiveUser()` touches five other jobs and is a follow-up.
 */
class AutoTransitionJob extends ActorForwardedJob {

	/**
	 * Wire the application collaborators on top of the actor plumbing.
	 *
	 * @param ITimeFactory $time Time factory for the parent job class.
	 * @param IUserSession $userSession Session to impersonate on / restore.
	 * @param IUserManager $userManager Resolver for the captured user id, and the disabled check.
	 * @param OrganisationService $organisation Active-organisation resolver.
	 * @param LoggerInterface $logger PSR logger.
	 * @param DeferredEntryObjectResolver $resolver Stale-safe entry re-fetch.
	 * @param TransitionEngine $engine The one path every automatic move goes through.
	 * @param AutoTransitionPass $pass The pass, resumed with the entry's carried lineage.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		private readonly IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly DeferredEntryObjectResolver $resolver,
		private readonly TransitionEngine $engine,
		private readonly AutoTransitionPass $pass,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $this->userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Apply every entry whose object is unchanged since the decision.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		if ($this->actorIsUsable(userId: $context->getUserId()) === false) {
			return;
		}

		foreach ($context->getEntries() as $entry) {
			$object = $this->resolver->resolve(entry: $entry);
			if ($object === null || $this->isUnchanged(object: $object, entry: $entry) === false) {
				continue;
			}

			$this->applyEntry(entry: $entry);
		}
	}//end runDeferred()

	/**
	 * Whether the captured actor may still act.
	 *
	 * A uid of null is a session-less origin, which `ActorForwardedJob` already
	 * runs without impersonation; there is no account to check. A uid that
	 * resolves to a DISABLED account is refused here: the base class does not
	 * check, and an automatic move must not run as an account someone switched
	 * off.
	 *
	 * @param string|null $userId The captured acting user id.
	 *
	 * @return bool True when the queued moves may be applied.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function actorIsUsable(?string $userId): bool {
		if ($userId === null) {
			return true;
		}

		$user = $this->userManager->get($userId);
		if ($user !== null && $user->isEnabled() === true) {
			return true;
		}

		$this->logger->warning(
			message: '[AutoTransitionJob] The captured user is gone or disabled — queued automatic '
				. 'transitions skipped rather than applied under another identity',
			context: ['file' => __FILE__, 'line' => __LINE__, 'userId' => $userId]
		);

		return false;
	}//end actorIsUsable()

	/**
	 * Whether the object still stands as it did when the move was decided.
	 *
	 * @param ObjectEntity $object The object as stored now.
	 * @param array<string, mixed> $entry The queued entry.
	 *
	 * @return bool True when version and `updated` both still match.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function isUnchanged(ObjectEntity $object, array $entry): bool {
		if ($object->getVersion() !== ($entry['version'] ?? null)) {
			return false;
		}

		$updated = $object->getUpdated();
		$stored = null;
		if ($updated instanceof DateTimeInterface === true) {
			$stored = $updated->format(DATE_ATOM);
		}

		return $stored === ($entry['updated'] ?? null);
	}//end isUnchanged()

	/**
	 * Resume the entry's pass and apply its move through the engine.
	 *
	 * The move itself is not re-checked against the cap: it was counted when it
	 * was decided. What the resumed lineage bounds is what follows it, so a
	 * queued move applied at hop ten can chain no further.
	 *
	 * @param array<string, mixed> $entry The queued entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function applyEntry(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$action = (string)($entry['action'] ?? '');
		if ($uuid === '' || $action === '') {
			return;
		}

		$visited = ($entry['visited'] ?? []);
		$visitedStates = [];
		if (is_array($visited) === true) {
			$visitedStates = $visited;
		}

		$this->pass->resume(
			uuid: $uuid,
			moves: (int)($entry['moves'] ?? 0),
			visited: $visitedStates
		);

		try {
			$this->engine->transition(objectId: $uuid, action: $action);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AutoTransitionJob] A queued automatic transition was refused',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'action' => $action,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end applyEntry()
}//end class
