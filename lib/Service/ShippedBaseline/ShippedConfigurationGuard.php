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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Guards an app-shipped schema update against local changes.
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
	 * Constructor.
	 *
	 * @param ShippedBaselineStore   $baselines  Where the shipped definitions are kept.
	 * @param GuardedDescriptorMerge $merge      The per-part merge.
	 * @param DivergenceComparator   $comparator The four states.
	 * @param AuditTrailMapper       $audit      The hash-chained trail.
	 * @param IUserSession           $session    Who is acting.
	 * @param LoggerInterface        $logger     The logger.
	 */
	public function __construct(
		private readonly ShippedBaselineStore $baselines,
		private readonly GuardedDescriptorMerge $merge,
		private readonly DivergenceComparator $comparator,
		private readonly AuditTrailMapper $audit,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What an upgrade should write for one schema, and what it could not decide.
	 *
	 * @param string               $slug       The schema slug.
	 * @param array<string, mixed> $live       What the instance runs.
	 * @param array<string, mixed> $incoming   What the app now ships.
	 * @param string               $app        The app.
	 * @param string               $appVersion The app version.
	 * @param array<int, string>   $decisions  Paths an administrator decided to take from upstream.
	 *
	 * @return array{
	 *     definition: array<string, mixed>,
	 *     guarded: bool,
	 *     applied: array<int, string>,
	 *     preserved: array<int, string>,
	 *     conflicts: array<int, array<string, mixed>>
	 * } What to write and what happened.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function guardSchemaUpdate(
		string $slug,
		array $live,
		array $incoming,
		string $app,
		string $appVersion,
		array $decisions = []
	): array {
		$subject = $this->baselines->schemaSubject(slug: $slug);

		try {
			$baseline = $this->baselines->read(subject: $subject);

			if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
				// Nothing to compare against: import as today, and record what
				// the app shipped so the NEXT release can be guarded.
				$this->baselines->record(
					subject: $subject,
					definition: $incoming,
					app: $app,
					appVersion: $appVersion
				);

				return [
					'definition' => $incoming,
					'guarded' => false,
					'applied' => [],
					'preserved' => [],
					'conflicts' => [],
				];
			}

			$result = $this->merge->merge(
				baseline: $baseline['definition'],
				live: $live,
				incoming: $incoming,
				decisions: $decisions
			);

			$this->baselines->record(
				subject: $subject,
				definition: $result['baseline'],
				app: $app,
				appVersion: $appVersion
			);

			if ($result['conflicts'] !== []) {
				// INFO on the parts that were left alone, because this is the
				// decision somebody re-reads the upgrade log to find. The
				// upgrade itself completes either way.
				$this->logger->info(
					sprintf(
						'[ShippedConfigurationGuard] %s: %d part(s) changed on both sides, kept local and reported',
						$slug,
						count($result['conflicts'])
					)
				);
			}

			foreach ($decisions as $path) {
				$this->recordDecision(slug: $slug, path: (string)$path, app: $app);
			}

			return [
				'definition' => $result['merged'],
				'guarded' => true,
				'applied' => $result['applied'],
				'preserved' => $result['preserved'],
				'conflicts' => $result['conflicts'],
			];
		} catch (Throwable $e) {
			// An upgrade must finish. A guard that cannot run means the import
			// behaves as it did before the guard existed, which is a known
			// state, not a broken one.
			$this->logger->error(
				sprintf('[ShippedConfigurationGuard] %s: guard skipped, importing unguarded: %s', $slug, $e->getMessage())
			);

			return [
				'definition' => $incoming,
				'guarded' => false,
				'applied' => [],
				'preserved' => [],
				'conflicts' => [],
			];
		}//end try
	}//end guardSchemaUpdate()

	/**
	 * Record what an app shipped for a schema it has just created.
	 *
	 * @param string               $slug       The schema slug.
	 * @param array<string, mixed> $definition What the app ships.
	 * @param string               $app        The app.
	 * @param string               $appVersion The app version.
	 *
	 * @return bool True when it was recorded.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function recordShipped(string $slug, array $definition, string $app, string $appVersion): bool {
		return $this->baselines->record(
			subject: $this->baselines->schemaSubject(slug: $slug),
			definition: $definition,
			app: $app,
			appVersion: $appVersion
		);
	}//end recordShipped()

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
			return [
				'baseline' => false,
				'app' => '',
				'appVersion' => '',
				'recordedAt' => '',
				'divergences' => [],
			];
		}

		return [
			'baseline' => true,
			'app' => $baseline['app'],
			'appVersion' => $baseline['appVersion'],
			'recordedAt' => $baseline['recordedAt'],
			'divergences' => $this->comparator->report(baseline: $baseline['definition'], live: $live),
		];
	}//end divergenceFor()

	/**
	 * What resetting one part to the shipped baseline would change.
	 *
	 * Reads nothing back into the schema: a reset shows its effect before it is
	 * confirmed, which is the spec's second scenario, and a preview that wrote
	 * would make the confirmation decorative.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $live What the instance runs.
	 * @param string               $path The part to reset.
	 *
	 * @return array{applicable: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>} The preview.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function previewReset(string $slug, array $live, string $path): array {
		$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $slug));

		if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
			return [
				'applicable' => false,
				'reason' => 'no shipped baseline is recorded for this schema, so there is nothing to go back to',
				'from' => null,
				'to' => null,
				'definition' => $live,
			];
		}

		$parts = new DescriptorParts();
		$flat = [
			'shipped' => $parts->flatten(descriptor: $baseline['definition']),
			'live' => $parts->flatten(descriptor: $live),
		];

		$refusal = $this->resetRefusal(path: $path, flat: $flat, live: $live);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->resetPreview(path: $path, flat: $flat, parts: $parts);
	}//end previewReset()

	/**
	 * Why one part cannot be reset, or null when it can.
	 *
	 * Both answers carry `applicable: false` AND a reason. A reset that simply
	 * did nothing would look from the outside exactly like one that worked.
	 *
	 * @param string                                  $path The part to reset.
	 * @param array<string, array<string|int, mixed>> $flat The flattened shipped and live parts.
	 * @param array<string, mixed>                    $live What the instance runs.
	 *
	 * @return array{applicable: bool, reason: string, from: mixed, to: mixed, definition: array<string, mixed>}|null The refusal, or null.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	private function resetRefusal(string $path, array $flat, array $live): ?array {
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
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $live What the instance runs.
	 * @param string               $path The part to reset.
	 *
	 * @return array{applied: bool, reason: string, definition: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function resetToBaseline(string $slug, array $live, string $path): array {
		$user = $this->session->getUser();
		if ($user === null) {
			return [
				'applied' => false,
				'reason' => 'a reset needs an actor, and there is no session; it is never done by an unattended path',
				'definition' => $live,
			];
		}

		$preview = $this->previewReset(slug: $slug, live: $live, path: $path);
		if ($preview['applicable'] === false) {
			return [
				'applied' => false,
				'reason' => $preview['reason'],
				'definition' => $live,
			];
		}

		$this->record(
			action: self::ACTION_RESET,
			changed: [
				'schema' => $slug,
				'path' => $path,
				'from' => $preview['from'],
				'to' => $preview['to'],
			]
		);

		return [
			'applied' => true,
			'reason' => '',
			'definition' => $preview['definition'],
		];
	}//end resetToBaseline()

	/**
	 * Record that a conflicting part was taken from upstream.
	 *
	 * @param string $slug The schema slug.
	 * @param string $path The part.
	 * @param string $app  The app.
	 *
	 * @return void
	 */
	private function recordDecision(string $slug, string $path, string $app): void {
		$this->record(
			action: self::ACTION_DECIDED,
			changed: [
				'schema' => $slug,
				'path' => $path,
				'app' => $app,
				'decision' => 'accept-shipped',
			]
		);
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
