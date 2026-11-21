<?php

/**
 * The one place an app-shipped descriptor meets a diverged instance.
 *
 * ADR-022: the guard belongs to the import machinery, so every app that ships a
 * descriptor gets it without writing a repair step of its own. ADR-012: this
 * generalises `specs/schema-import`'s existing requirement — "Imported schemas
 * MUST record provenance and support guarded update-from-source", which already
 * specifies the diff preview, the preserved local additions and the reported
 * conflicts for STANDARDS-imported schemas — to the app-shipped path, rather
 * than writing a second answer to the same question.
 *
 * 🔴 THE FIRST IMPORT AFTER THIS SHIPS CHANGES NOTHING. No instance has a
 * baseline yet, and inventing one from the incoming descriptor would declare
 * every local edit ever made to be upstream, then overwrite it on the next
 * release — the exact failure, arriving through the fix. So a subject with no
 * baseline is imported the way it is imported today and the baseline is
 * recorded for next time. The guard starts guarding one release later, and it
 * says so rather than appearing to work immediately.
 *
 * 🔴 AND IT NEVER THROWS. `occ upgrade` runs unattended; a guard that can fail
 * an upgrade is worse than the problem it solves.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Guards an app-shipped schema update against local changes.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One guard for every shipped definition kind: baseline read, merge,
 * audit of each authorization change and the fail-closed fallback share one definition of what "shipped" means.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class ShippedConfigurationGuard {

	/**
	 * The audit action a deliberate reset carries.
	 *
	 * @var string
	 */
	public const ACTION_RESET = 'configuration.baseline.reset';

	/**
	 * The audit action an accepted conflict carries.
	 *
	 * @var string
	 */
	public const ACTION_DECIDED = 'configuration.conflict.decided';

	/**
	 * The divergence answer for a schema with no shipped baseline recorded.
	 *
	 * @var array{baseline: false, app: '', appVersion: '', recordedAt: '', divergences: array{}}
	 */
	private const NO_BASELINE_DIVERGENCE = [
		'baseline' => false,
		'app' => '',
		'appVersion' => '',
		'recordedAt' => '',
		'divergences' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param ShippedBaselineStore   $baselines  Where the shipped definitions are kept.
	 * @param GuardedDescriptorMerge $merge      The per-part merge.
	 * @param DivergenceComparator   $comparator The four states.
	 * @param AuditTrailMapperInterface       $audit      The hash-chained trail.
	 * @param IUserSession           $session    Who is acting.
	 * @param LoggerInterface        $logger     The logger.
	 */
	public function __construct(
		private readonly ShippedBaselineStore $baselines,
		private readonly GuardedDescriptorMerge $merge,
		private readonly DivergenceComparator $comparator,
		private readonly AuditTrailMapperInterface $audit,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What an upgrade should write for one schema, and what it could not decide.
	 *
	 * With `record: false` in the options the baseline is left unrecorded: the
	 * caller records `baseline` with recordShippedSchema() once the definition is
	 * actually written. A baseline recorded for a write that then fails makes
	 * the next import read the old live definition as a local edit and keep it.
	 *
	 * @param SchemaUpgrade        $upgrade The schema, what the instance runs and what the app now ships.
	 * @param SchemaUpgradeOptions $options The administrator's decisions and whether to record the baseline.
	 *
	 * @return array{
	 *     definition: array<string, mixed>,
	 *     baseline: array<string, mixed>|null,
	 *     guarded: bool,
	 *     applied: array<int, string>,
	 *     preserved: array<int, string>,
	 *     conflicts: array<int, array<string, mixed>>
	 * } What to write, the baseline that goes with it (null: record nothing), and what happened.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function guardUpgrade(SchemaUpgrade $upgrade, SchemaUpgradeOptions $options=new SchemaUpgradeOptions()): array {
		$run = new SchemaUpgradeRun(upgrade: $upgrade, options: $options);
		try {
			$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $upgrade->slug));
			if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
				// Nothing to compare against: import as today, and record what
				// the app shipped so the NEXT release can be guarded.
				return $this->firstRelease(run: $run);
			}

			return $this->guardedRelease(run: $run, baseline: $baseline['definition']);
		} catch (Throwable $e) {
			// An upgrade must finish, so the import still goes ahead. But a
			// guard that cannot run must not hand the app's authorization over
			// what an administrator set here: that is the one thing the guard
			// exists to prevent, and failing towards the shipped policy is
			// failing open. The instance's own authorization blocks are kept.
			return $this->unguardedRelease(upgrade: $upgrade, error: $e);
		}
	}//end guardUpgrade()

	/**
	 * The answer for a schema with no baseline yet: the shipped definition as it is, recorded as the baseline.
	 *
	 * @param SchemaUpgradeRun $run The upgrade in progress.
	 *
	 * @return array<string, mixed> The guard's answer, unguarded.
	 */
	private function firstRelease(SchemaUpgradeRun $run): array {
		$incoming = $run->upgrade->incoming->definition;
		$this->recordBaseline(run: $run, definition: $incoming);

		return [
			'definition' => $incoming,
			'baseline' => $incoming,
			'guarded' => false,
			'applied' => [],
			'preserved' => [],
			'conflicts' => [],
		];
	}//end firstRelease()

	/**
	 * The merged answer against a recorded baseline, with every decision put on the trail.
	 *
	 * @param SchemaUpgradeRun     $run      The upgrade in progress.
	 * @param array<string, mixed> $baseline The recorded shipped definition.
	 *
	 * @return array<string, mixed> The guard's answer, guarded.
	 */
	private function guardedRelease(SchemaUpgradeRun $run, array $baseline): array {
		$upgrade = $run->upgrade;
		// While an administrator's re-import is in effect, the merge takes
		// every authorization block from the file (REQ-LCA-003).
		$baseline = (new GuardedAnnotations())->seededBaseline(baseline: $baseline, live: $upgrade->live);
		$revisions = new DescriptorRevisions(baseline: $baseline, live: $upgrade->live, incoming: $upgrade->incoming->definition);
		$result = $this->merge->mergeRevisions(revisions: $revisions, decisions: $run->options->decisions);
		$this->recordReplaced(upgrade: $upgrade, roots: $result['replacedLocal']);
		$this->recordAppliedAuthorization(run: $run, result: $result);
		$this->recordBaseline(run: $run, definition: $result['baseline']);
		$this->logKeptLocal(slug: $upgrade->slug, conflicts: $result['conflicts']);
		foreach ($run->options->decisions as $path) {
			$this->recordDecision(upgrade: $upgrade, decision: new UpstreamDecision(path: (string)$path));
		}

		return [
			'definition' => $result['merged'],
			'baseline' => $result['baseline'],
			'guarded' => true,
			'applied' => $result['applied'],
			'preserved' => $result['preserved'],
			'conflicts' => $result['conflicts'],
		];
	}//end guardedRelease()

	/**
	 * The answer when the guard could not run: the shipped definition with the instance's own authorization kept.
	 *
	 * @param SchemaUpgrade $upgrade The upgrade.
	 * @param Throwable     $error   Why the guard could not run.
	 *
	 * @return array<string, mixed> The guard's answer, unguarded and recording nothing.
	 */
	private function unguardedRelease(SchemaUpgrade $upgrade, Throwable $error): array {
		$this->logger->error(
			sprintf(
				'[ShippedConfigurationGuard] %s: guard skipped, importing unguarded but keeping the instance authorization: %s',
				$upgrade->slug,
				$error->getMessage()
			)
		);

		return [
			'definition' => $this->keepingLiveAuthorization(live: $upgrade->live, incoming: $upgrade->incoming->definition),
			'baseline' => null,
			'guarded' => false,
			'applied' => [],
			'preserved' => [],
			'conflicts' => [],
		];
	}//end unguardedRelease()

	/**
	 * Record a baseline for the upgrade's release, unless the options leave that to the caller.
	 *
	 * @param SchemaUpgradeRun     $run        The upgrade in progress.
	 * @param array<string, mixed> $definition The baseline to record.
	 *
	 * @return void
	 */
	private function recordBaseline(SchemaUpgradeRun $run, array $definition): void {
		if ($run->options->record === false) {
			return;
		}

		$incoming = $run->upgrade->incoming;
		$shipped = new ShippedDefinition(definition: $definition, app: $incoming->app, appVersion: $incoming->appVersion);
		$this->baselines->record(subject: $this->baselines->schemaSubject(slug: $run->upgrade->slug), shipped: $shipped);
	}//end recordBaseline()

	/**
	 * Log, at INFO, the parts that changed on both sides and were left alone.
	 *
	 * INFO, because this is the decision somebody re-reads the upgrade log to
	 * find. The upgrade itself completes either way.
	 *
	 * @param string                           $slug      The schema slug.
	 * @param array<int, array<string, mixed>> $conflicts The parts kept local.
	 *
	 * @return void
	 */
	private function logKeptLocal(string $slug, array $conflicts): void {
		if ($conflicts === []) {
			return;
		}

		$this->logger->info(
			sprintf('[ShippedConfigurationGuard] %s: %d part(s) changed on both sides, kept local and reported', $slug, count($conflicts))
		);
	}//end logKeptLocal()

	/**
	 * The incoming definition with the instance's own authorization blocks put back.
	 *
	 * For an import the guard could not judge: the schema's `authorization` and
	 * each existing property's `authorization` stay as the instance has them
	 * (a block the instance does not have is not added); a property the
	 * instance does not hold yet brings its shipped block.
	 *
	 * @param array<string, mixed> $live     What the instance runs.
	 * @param array<string, mixed> $incoming What the app now ships.
	 *
	 * @return array<string, mixed> The definition to write.
	 */
	private function keepingLiveAuthorization(array $live, array $incoming): array {
		$definition = $this->withAuthorizationOf(target: $incoming, live: $live);

		$liveProperties = ($live['properties'] ?? null);
		if (is_array($liveProperties) === false || is_array($definition['properties'] ?? null) === false) {
			return $definition;
		}

		foreach ($definition['properties'] as $name => $property) {
			if (is_array($property) === false || is_array($liveProperties[$name] ?? null) === false) {
				continue;
			}

			$definition['properties'][$name] = $this->withAuthorizationOf(target: $property, live: $liveProperties[$name]);
		}

		return $definition;
	}//end keepingLiveAuthorization()

	/**
	 * A definition part carrying the `authorization` block of the instance's own part, or none when the instance has none.
	 *
	 * @param array<string, mixed> $target The part from the incoming definition.
	 * @param array<string, mixed> $live   The same part as the instance has it.
	 *
	 * @return array<string, mixed> The part with the instance's authorization.
	 */
	private function withAuthorizationOf(array $target, array $live): array {
		if (array_key_exists('authorization', $live) === false) {
			unset($target['authorization']);
			return $target;
		}

		$target['authorization'] = $live['authorization'];

		return $target;
	}//end withAuthorizationOf()

	/**
	 * Record, on the audit trail, the authorization blocks an upgrade took from the app because only the app changed them.
	 *
	 * An explicit decision is recorded by recordReplaced(); this is the other
	 * way a block changes under an instance, and who widened access is what
	 * the trail has to be able to answer. Roots already on the trail (replaced
	 * locally, or an administrator's decision) are not recorded twice.
	 *
	 * @param SchemaUpgradeRun     $run    The upgrade in progress.
	 * @param array<string, mixed> $result The merge result: `applied` and `replacedLocal`.
	 *
	 * @return void
	 */
	private function recordAppliedAuthorization(SchemaUpgradeRun $run, array $result): void {
		$recorded = array_merge($result['replacedLocal'], $run->options->decisions);
		foreach ($this->authorizationRoots(applied: $result['applied']) as $root) {
			if (in_array($root, $recorded, true) === false) {
				$this->recordDecision(upgrade: $run->upgrade, decision: new UpstreamDecision(path: $root, kind: 'applied-shipped'));
			}
		}
	}//end recordAppliedAuthorization()

	/**
	 * The authorization block roots (schema or property level) the applied paths fall under.
	 *
	 * @param array<int, string> $applied The paths the merge took from upstream.
	 *
	 * @return array<int, string> Each root once, in first-seen order.
	 */
	private function authorizationRoots(array $applied): array {
		$roots = [];
		$sep = preg_quote(DescriptorParts::SEPARATOR, '/');
		$pattern = '/^(authorization|properties' . $sep . '[^' . $sep . ']+' . $sep . 'authorization)(?:' . $sep . '|$)/';
		foreach ($applied as $path) {
			if (preg_match($pattern, (string)$path, $matches) === 1) {
				$roots[$matches[1]] = true;
			}
		}

		return array_map('strval', array_keys($roots));
	}//end authorizationRoots()

	/**
	 * Record what an app shipped for a schema, with the release that shipped it.
	 *
	 * @param string            $slug    The schema slug.
	 * @param ShippedDefinition $shipped What the app ships, and which release.
	 *
	 * @return bool True when it was recorded.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function recordShippedSchema(string $slug, ShippedDefinition $shipped): bool {
		return $this->baselines->record(subject: $this->baselines->schemaSubject(slug: $slug), shipped: $shipped);
	}//end recordShippedSchema()

	/**
	 * Where one schema differs from what was shipped.
	 *
	 * 🔑 IT DOES NOT NAME THE ACTOR YET, and says so rather than returning a
	 * null that reads as "nobody". Task 2.2 wants the actor and the moment of
	 * each local change; a schema is an ENTITY, not an object, and entity edits
	 * do not reach the object audit trail, so there is nowhere to read it from
	 * today. Reported in the PR body as the finding it is.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $live What the instance runs.
	 *
	 * @return array{
	 *     baseline: bool,
	 *     app: string,
	 *     appVersion: string,
	 *     recordedAt: string,
	 *     divergences: array<int, array<string, mixed>>
	 * } The report.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function divergenceFor(string $slug, array $live): array {
		$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $slug));

		if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
			return self::NO_BASELINE_DIVERGENCE;
		}

		return [
			'baseline' => true,
			'app' => $baseline['app'],
			'appVersion' => $baseline['appVersion'],
			'recordedAt' => $baseline['recordedAt'],
			'divergences' => $this->comparator->report(
				baseline: (new GuardedAnnotations())->seededBaseline(baseline: $baseline['definition'], live: $live),
				live: $live
			),
		];
	}//end divergenceFor()

	/**
	 * What resetting one part to the shipped baseline would change.
	 *
	 * Reads nothing back into the schema: a reset shows its effect before it is
	 * confirmed, which is the spec's second scenario, and a preview that wrote
	 * would make the confirmation decorative.
	 *
	 * @param ResetRequest $request The schema, what it runs and the part to reset.
	 *
	 * @return array{applicable: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>} The preview.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function previewPartReset(ResetRequest $request): array {
		$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $request->slug));

		if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
			return [
				'applicable' => false,
				'reason' => 'no shipped baseline is recorded for this schema, so there is nothing to go back to',
				'from' => null,
				'to' => null,
				'definition' => $request->live,
			];
		}

		$parts = new DescriptorParts();
		$flat = [
			'shipped' => $parts->flatten(descriptor: (new GuardedAnnotations())->seededBaseline(baseline: $baseline['definition'], live: $request->live)),
			'live' => $parts->flatten(descriptor: $request->live),
		];

		return ($this->resetRefusal(request: $request, flat: $flat) ?? $this->resetPreview(path: $request->path, flat: $flat, parts: $parts));
	}//end previewPartReset()

	/**
	 * Why one part cannot be reset, or null when it can.
	 *
	 * Both answers carry `applicable: false` AND a reason. A reset that simply
	 * did nothing would look from the outside exactly like one that worked.
	 *
	 * @param ResetRequest                            $request The schema, what it runs and the part to reset.
	 * @param array<string, array<string|int, mixed>> $flat    The flattened shipped and live parts.
	 *
	 * @return array{applicable: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>}|null The refusal, or null.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function resetRefusal(ResetRequest $request, array $flat): ?array {
		$path = $request->path;
		$live = $request->live;
		$hasShipped = array_key_exists($path, $flat['shipped']);
		$hasLive = array_key_exists($path, $flat['live']);

		if ($hasShipped === false && $hasLive === false) {
			return [
				'applicable' => false,
				'reason' => sprintf('"%s" is in neither the shipped baseline nor the live definition', $path),
				'from' => null,
				'to' => null,
				'definition' => $live,
			];
		}

		if ($hasShipped === true && $hasLive === true && $flat['shipped'][$path] === $flat['live'][$path]) {
			return [
				'applicable' => false,
				'reason' => sprintf('"%s" already matches what was shipped', $path),
				'from' => $flat['live'][$path],
				'to' => $flat['shipped'][$path],
				'definition' => $live,
			];
		}

		return null;
	}//end resetRefusal()

	/**
	 * What resetting one part would change it from, and to.
	 *
	 * @param string                                  $path  The part to reset.
	 * @param array<string, array<string|int, mixed>> $flat  The flattened shipped and live parts.
	 * @param DescriptorParts                         $parts The flattener, reused for the round trip.
	 *
	 * @return array{applicable: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>} The preview.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function resetPreview(string $path, array $flat, DescriptorParts $parts): array {
		$hasShipped = array_key_exists($path, $flat['shipped']);
		$hasLive = array_key_exists($path, $flat['live']);

		$next = $flat['live'];
		if ($hasShipped === true) {
			$next[$path] = $flat['shipped'][$path];
		}

		if ($hasShipped === false) {
			// The part was added locally and was never shipped, so going back
			// to the baseline means removing it.
			unset($next[$path]);
		}

		$from = null;
		if ($hasLive === true) {
			$from = $flat['live'][$path];
		}

		$to = null;
		if ($hasShipped === true) {
			$to = $flat['shipped'][$path];
		}

		return [
			'applicable' => true,
			'reason' => '',
			'from' => $from,
			'to' => $to,
			'definition' => $parts->unflatten(parts: $next),
		];
	}//end resetPreview()

	/**
	 * Reset one part to the shipped baseline, as a recorded act.
	 *
	 * 🔴 IT REFUSES WITHOUT AN ACTOR. A reset is a deliberate decision with
	 * consequences for stored objects (D-4), so it is not something a repair
	 * step or any unattended path does because it found a difference. No
	 * session means no actor means no reset, and the refusal says which.
	 *
	 * `$record: false` leaves the audit row to the caller, which writes it with
	 * recordPartReset() once the reset definition is stored. Recorded here, a write
	 * that then fails leaves a trail naming a reset the instance never ran.
	 *
	 * @param string               $slug   The schema slug.
	 * @param array<string, mixed> $live   What the instance runs.
	 * @param string               $path   The part to reset.
	 * @param bool                 $record False leaves the audit row to the caller.
	 *
	 * @return array{applied: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>} The outcome.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The default keeps every existing caller unchanged.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function resetToBaseline(string $slug, array $live, string $path, bool $record = true): array {
		$user = $this->session->getUser();
		if ($user === null) {
			return self::refusedWithoutActor(live: $live);
		}

		$preview = $this->previewPartReset(request: new ResetRequest(slug: $slug, live: $live, path: $path));
		if ($preview['applicable'] === false) {
			return self::refusedReset(preview: $preview, live: $live);
		}

		if ($record === true) {
			$this->recordPartReset(slug: $slug, reset: new PartReset(path: $path, from: $preview['from'], to: $preview['to']));
		}

		return self::appliedReset(preview: $preview);
	}//end resetToBaseline()

	/**
	 * The outcome of a reset asked for without a signed-in actor.
	 *
	 * @param array<string, mixed> $live What the instance runs, left as it is.
	 *
	 * @return array{applied: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>}
	 */
	private static function refusedWithoutActor(array $live): array {
		return [
			'applied' => false,
			'reason' => 'a reset needs an actor, and there is no session; it is never done by an unattended path',
			'from' => null,
			'to' => null,
			'definition' => $live,
		];
	}//end refusedWithoutActor()

	/**
	 * The outcome of a reset whose preview is not applicable, carrying the preview's reason.
	 *
	 * @param array<string, mixed> $preview The preview of the reset.
	 * @param array<string, mixed> $live    What the instance runs, left as it is.
	 *
	 * @return array{applied: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>}
	 */
	private static function refusedReset(array $preview, array $live): array {
		return [
			'applied' => false,
			'reason' => $preview['reason'],
			'from' => $preview['from'],
			'to' => $preview['to'],
			'definition' => $live,
		];
	}//end refusedReset()

	/**
	 * The outcome of an applied reset: the preview's change and its reset definition.
	 *
	 * @param array<string, mixed> $preview The applicable preview of the reset.
	 *
	 * @return array{applied: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>}
	 */
	private static function appliedReset(array $preview): array {
		return [
			'applied' => true,
			'reason' => '',
			'from' => $preview['from'],
			'to' => $preview['to'],
			'definition' => $preview['definition'],
		];
	}//end appliedReset()

	/**
	 * Put a reset on the trail: who, which schema, which part, from what, to what.
	 *
	 * @param string    $slug  The schema slug.
	 * @param PartReset $reset The part that was reset, from what and to what.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function recordPartReset(string $slug, PartReset $reset): void {
		$changed = ['schema' => $slug, 'path' => $reset->path, 'from' => $reset->from, 'to' => $reset->to];
		$this->record(action: self::ACTION_RESET, changed: $changed);
	}//end recordPartReset()

	/**
	 * Record each authorization block an administrator's re-import replaced
	 * over a local change: on the audit trail, and on the decision for the report.
	 *
	 * @param SchemaUpgrade      $upgrade The upgrade: the schema slug and the app that ships it.
	 * @param array<int, string> $roots   The replaced block roots.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	private function recordReplaced(SchemaUpgrade $upgrade, array $roots): void {
		$via = (string)$this->merge->decidedVia();
		foreach ($roots as $root) {
			$this->recordDecision(upgrade: $upgrade, decision: new UpstreamDecision(path: (string)$root, via: $via));
		}

		$this->merge->noteReplaced(slug: $upgrade->slug, roots: $roots);
	}//end recordReplaced()

	/**
	 * Record that a conflicting part was taken from upstream.
	 *
	 * @param SchemaUpgrade    $upgrade  The upgrade: the schema slug and the app.
	 * @param UpstreamDecision $decision The part, the kind of decision and what decided it.
	 *
	 * @return void
	 */
	private function recordDecision(SchemaUpgrade $upgrade, UpstreamDecision $decision): void {
		$changed = [
			'schema' => $upgrade->slug,
			'path' => $decision->path,
			'app' => $upgrade->incoming->app,
			'decision' => $decision->kind,
		];
		if ($decision->via !== '') {
			$changed['via'] = $decision->via;
		}

		$this->record(action: self::ACTION_DECIDED, changed: $changed);
	}//end recordDecision()

	/**
	 * One row on the trail. Never throws.
	 *
	 * @param string               $action  The action.
	 * @param array<string, mixed> $changed What changed.
	 *
	 * @return void
	 */
	private function record(string $action, array $changed): void {
		try {
			$user = $this->session->getUser();

			$row = new AuditTrail();
			$row->setUuid(Uuid::v4()->toRfc4122());
			$row->setAction($action);
			$actorId = 'system';
			$actorName = 'System';
			if ($user !== null) {
				$actorId = $user->getUID();
				$actorName = $user->getDisplayName();
			}

			$row->setUser($actorId);
			$row->setUserName($actorName);
			$row->setChanged($changed);
			$row->setCreated(new DateTime());

			$this->audit->insertAuditTrails(entries: [$row]);
		} catch (Throwable $e) {
			$this->logger->error(
				'[ShippedConfigurationGuard] the act happened but was not recorded: ' . $e->getMessage()
			);
		}
	}//end record()
}//end class
