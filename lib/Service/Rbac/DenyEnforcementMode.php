<?php

/**
 * The switch that decides whether a deny refuses anything yet.
 *
 * Every other rule in this authorization layer ADDS a right. A register default
 * grants, a schema rule grants, a role grants, a per-object grant grants. The
 * worst a mistake in one of them can do is hand somebody a right they should not
 * have had, and an audit finds that.
 *
 * A deny inverts the failure. A mistake in a deny locks a case worker out of the
 * dossier they are paid to handle, at nine in the morning, and the only evidence
 * on their screen is an absence. So the deny does not ship enforcing. It ships
 * evaluated and RECORDED, and an administrator reaches enforcement on purpose
 * (decision D15, 2026-09-14).
 *
 * THREE STATES, one app-config value, `openregister.deny_enforcement`:
 *
 *  - `off`       The deny pass is skipped. Nothing changes, at any cost. This
 *                exists for the incident, not for the rollout: when a deny is
 *                refusing people it should not, an administrator needs one value
 *                to set, with no rule editing under pressure and no deploy.
 *  - `staging`   The deny is resolved and recorded, and the verdict is NOT
 *                applied. The grant stands and the provenance names the rule
 *                that would have removed it. This is the default.
 *  - `enforcing` The deny is resolved and applied. The verb is gone and the
 *                provenance says which rule took it.
 *
 * WHY THE DEFAULT IS `staging` AND NOT `off`. Staging costs an instance that
 * writes no deny exactly one array lookup, because there is nothing to resolve.
 * An instance that does write one gets a week of reading what its rules would do
 * before a single user is refused. `off` would buy the same safety and teach
 * nobody anything, which is how a dry run becomes a switch nobody dares flip.
 *
 * WHY STAGING STILL RESOLVES. A staging mode that skipped the resolution would
 * record nothing, and a dry run that records nothing is a dry run in name only.
 * The resolution runs; only the verdict is dropped.
 *
 * WHAT IS NOT STAGED. The save-time refusals in {@see AuthorizationDenyValidator}
 * apply in every mode. A block that grants and denies one verb at one level, and
 * a deny that would leave nobody holding `manage`, are contradictions in the
 * rules rather than effects on a user. Writing one and discovering it a month
 * later at the switch is the outcome staging exists to prevent.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads the deny enforcement mode, and records what a staged deny would refuse.
 */
class DenyEnforcementMode {

	/**
	 * App identifier used for `IAppConfig` lookups.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The app-config key carrying the mode.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'deny_enforcement';

	/**
	 * The deny pass is skipped entirely.
	 *
	 * @var string
	 */
	public const MODE_OFF = 'off';

	/**
	 * The deny is resolved and recorded, and the verdict is not applied.
	 *
	 * @var string
	 */
	public const MODE_STAGING = 'staging';

	/**
	 * The deny is resolved and applied.
	 *
	 * @var string
	 */
	public const MODE_ENFORCING = 'enforcing';

	/**
	 * The mode an instance that has never set the value runs in.
	 *
	 * @var string
	 */
	public const DEFAULT_MODE = self::MODE_STAGING;

	/**
	 * Every value the config key accepts.
	 *
	 * @var array<int, string>
	 */
	public const MODES = [self::MODE_OFF, self::MODE_STAGING, self::MODE_ENFORCING];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The store holding the mode.
	 * @param LoggerInterface $logger    Where a staged denial is recorded.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The mode this instance runs in.
	 *
	 * An unreadable or unrecognised value answers `staging`, which is the
	 * fail-safe reading in both directions: it never refuses a caller the rules
	 * did not refuse before, and it never quietly stops recording.
	 *
	 * @return string One of {@see MODES}.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function current(): string {
		try {
			$value = $this->appConfig->getValueString(
				app: self::APP_ID,
				key: self::CONFIG_KEY,
				default: self::DEFAULT_MODE
			);
		} catch (\Throwable $e) {
			return self::DEFAULT_MODE;
		}

		$value = strtolower(trim($value));
		if (in_array(needle: $value, haystack: self::MODES, strict: true) === false) {
			return self::DEFAULT_MODE;
		}

		return $value;
	}//end current()

	/**
	 * Whether a resolved denial is allowed to change the answer.
	 *
	 * @return bool True only in `enforcing`.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function enforces(): bool {
		return $this->current() === self::MODE_ENFORCING;
	}//end enforces()

	/**
	 * Whether the deny pass runs at all.
	 *
	 * False only in `off`. Staging resolves, because the record is the point.
	 *
	 * @return bool True in `staging` and `enforcing`.
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function resolves(): bool {
		return $this->current() !== self::MODE_OFF;
	}//end resolves()

	/**
	 * Record a denial that was resolved but not applied.
	 *
	 * One structured warning per staged denial, carrying the rule, the principal
	 * it names, the verb and the caller. Warning rather than info on purpose: an
	 * administrator reading the log for "what will change when I flip the
	 * switch" should not have to raise the level to see the answer.
	 *
	 * @param array<string, mixed> $denial  The denial as the resolver returned it.
	 * @param string               $action  The verb that would have been removed.
	 * @param string|null          $userId  The caller it would have been removed from.
	 * @param array<string, mixed> $context Where it happened: schema, object, path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
	 */
	public function record(array $denial, string $action, ?string $userId, array $context = []): void {
		$this->logger->warning(
			message: '[DenyEnforcementMode] A deny rule would refuse this action; staging, so it was allowed',
			context: array_merge(
				[
					'file' => __FILE__,
					'line' => __LINE__,
					'mode' => self::MODE_STAGING,
					'action' => $action,
					'userId' => $userId,
					'principal' => ($denial['principal'] ?? null),
					'rule' => ($denial['rule'] ?? null),
					'conditional' => ($denial['conditional'] ?? false),
				],
				$context
			)
		);
	}//end record()
}//end class
