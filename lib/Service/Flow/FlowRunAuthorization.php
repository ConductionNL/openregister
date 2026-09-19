<?php

/**
 * May this principal run THIS flow — asked in one place, by every run path.
 *
 * 🔴 THE CONTROL WAS DECLARED WHERE NOTHING READS IT. `flow_register.json`
 * declares `scope: private` on the `flow` schema, and a reader takes from that
 * what `ObjectScopeResolver` means: owner, administrators and invited
 * principals only. But flows live in the native `openregister_flows` table —
 * `MigrateRegisterFlowsToTable` drained the register into it precisely because
 * "every subsystem reads the table; nothing reads the register" — so the
 * declaration governs a store the run path never touches. A control that is
 * declared where nothing reads it is worse than an absent one, because it
 * reports success.
 *
 * What was actually protecting a run: `flow.run`, seeded `@authenticated`, plus
 * an organisation check. On the single-organisation instance that is the common
 * case, that is **any signed-in user running any flow**. `FlowRunController`'s
 * own docblock says so in those words (or#3643) and answers it for `test()`
 * alone; `retry()` and the MCP tool required neither.
 *
 * 🔑 `flow.update` IS THE BAR, NOT `flow.run` (D-4). `flow.run` says only that a
 * caller may trigger flows at all. `flow.update` is the right already required
 * for every other editing verb on a flow, it is NARROWABLE by an administrator,
 * and `test()` already picked it for exactly this reason — using a different
 * bar here would give two answers to one question.
 *
 * 🔴 AND AN UNOWNED FLOW IS REFUSED TO EVERYONE. `Flow::canDispatch()` already
 * returns false for one: an imported flow arrives inert on purpose and adoption
 * is the deliberate act that makes it somebody's. A run request against it can
 * only fail — except on `test()`, which executes synchronously and would run
 * it. Refusing at the door makes every path agree with the engine.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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
 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use Throwable;

/**
 * The one per-flow run decision.
 *
 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
 */
class FlowRunAuthorization {

	/**
	 * The right that lets a caller run a flow that is not theirs.
	 *
	 * @var string
	 */
	public const RIGHT = 'flow.update';

	/**
	 * Refused: there is nobody to attribute the run to.
	 *
	 * @var string
	 */
	public const NO_SESSION = 'no-session';

	/**
	 * Refused: the flow belongs to nobody, so the engine would not dispatch it.
	 *
	 * @var string
	 */
	public const NO_OWNER = 'no-owner';

	/**
	 * Refused: the caller neither owns it nor may edit flows.
	 *
	 * @var string
	 */
	public const NOT_YOURS = 'not-yours';

	/**
	 * Refused: the decision could not be made at all.
	 *
	 * @var string
	 */
	public const UNDECIDABLE = 'undecidable';

	/**
	 * Allowed.
	 *
	 * @var string
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Constructor.
	 *
	 * @param FlowAccess|null $access The rights matrix; absent means undecidable.
	 */
	public function __construct(
		private readonly ?FlowAccess $access = null,
	) {
	}//end __construct()

	/**
	 * Why this caller may not run this flow, or {@see self::ALLOWED}.
	 *
	 * Returns a REASON rather than a boolean, because the four refusals want
	 * four different messages: "sign in", "nobody owns this flow yet", "this is
	 * not yours", and "this instance cannot decide". Collapsing them to false
	 * would send three of those callers to the wrong place.
	 *
	 * @param Flow|null $flow The flow being run.
	 *
	 * @return string The verdict.
	 *
	 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
	 */
	public function verdictFor(?Flow $flow): string {
		if ($this->access === null || $flow === null) {
			// No way to decide is a refusal, never an allow. Same posture as
			// the existing guards on this subsystem.
			return self::UNDECIDABLE;
		}

		try {
			$user = $this->access->currentUser();
		} catch (Throwable $e) {
			return self::UNDECIDABLE;
		}

		if ($user === null) {
			return self::NO_SESSION;
		}

		// 🔴 THE UNOWNED CHECK COMES BEFORE THE ADMIN BYPASS, and the order is
		// the whole of it. An unowned flow must be refused even to an
		// administrator, because the engine will not dispatch one either —
		// letting an admin through would give them a run that can only fail,
		// and on `test()`, which executes synchronously, a run of a flow
		// nobody has taken responsibility for. It also stops an empty owner
		// string matching an empty uid further down.
		$owner = trim((string)$flow->getOwner());
		if ($owner === '') {
			return self::NO_OWNER;
		}

		try {
			if ($this->access->callerIsAdmin() === true) {
				return self::ALLOWED;
			}
		} catch (Throwable $e) {
			return self::UNDECIDABLE;
		}

		if ($owner === $user->getUID()) {
			return self::ALLOWED;
		}

		try {
			if ($this->access->may(user: $user, action: self::RIGHT) === true) {
				return self::ALLOWED;
			}
		} catch (Throwable $e) {
			return self::UNDECIDABLE;
		}

		return self::NOT_YOURS;
	}//end verdictFor()

	/**
	 * Whether this caller may run this flow.
	 *
	 * @param Flow|null $flow The flow.
	 *
	 * @return bool True when they may.
	 *
	 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
	 */
	public function mayRun(?Flow $flow): bool {
		return ($this->verdictFor(flow: $flow) === self::ALLOWED);
	}//end mayRun()

	/**
	 * The sentence a refused caller reads.
	 *
	 * @param string $verdict The verdict.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/flow-runs-honour-their-declaration/specs/flow-engine/spec.md
	 */
	public function messageFor(string $verdict): string {
		if ($verdict === self::NO_SESSION) {
			return 'Running a flow needs a signed-in user.';
		}

		if ($verdict === self::NO_OWNER) {
			return 'This flow has no owner, so it cannot run. Adopt it first.';
		}

		if ($verdict === self::NOT_YOURS) {
			return 'You do not own this flow and do not have the "' . self::RIGHT . '" right.';
		}

		if ($verdict === self::UNDECIDABLE) {
			return 'Flow authorization is unavailable, so the run is refused.';
		}

		return '';
	}//end messageFor()
}//end class
