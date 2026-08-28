<?php

/**
 * Verify the v1 audit chain, record what it found, then re-seal under v2.
 *
 * The flow-attribution fields joined the canonical JSON the hash chain covers,
 * so a row's run/node/step can no longer be altered without breaking
 * verification. ADR-003 Rule 4 makes that a migration event rather than an edit:
 * the seed moves v1 → v2 and every stored hash has to be recomputed, because a
 * hash sealed over the old field set can never again be re-derived from the new
 * one.
 *
 * 🔴 THE ORDER IS THE WHOLE POINT, AND IT IS NOT COSMETIC.
 *
 * A re-chain recomputes every hash from current row content. That means it
 * cannot distinguish a chain that was intact from one that had been tampered
 * with — it makes both verify afterwards. Whatever the chain's true state was
 * at this moment, it is not recoverable after this step runs.
 *
 * So this step verifies FIRST, against {@see AuditCanonicalV1} — the frozen old
 * form — and writes that verdict down before it rewrites anything. Verifying
 * with the CURRENT canonicaliser would have been worthless: it includes the new
 * keys, so every v1 row mismatches whether or not it was ever touched, the
 * verdict reads "broken" unconditionally, and the one fact worth preserving is
 * destroyed by the very step that was supposed to check it.
 *
 * The step does not REFUSE on a broken chain. An operator whose chain is
 * already broken still needs their instance to upgrade, and refusing would
 * strand them with no path forward. It records, loudly and durably, and
 * continues — the difference between a silent overwrite and an accountable one.
 *
 * It DOES refuse if it cannot record the verdict at all, because a re-chain
 * with no surviving account of what preceded it is the one outcome with no
 * remedy.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditCanonicalV1;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-verifies the v1 chain and re-seals the audit trail under the v2 seed.
 *
 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
 */
class RechainAuditTrailForFlowAttribution implements IRepairStep {
	/**
	 * App-config key recording that the v1 → v2 re-chain has been performed.
	 *
	 * Presence is what makes the step idempotent: a re-chain is expensive and,
	 * far more importantly, its pre-verification is only meaningful ONCE. After
	 * the first pass every row is sealed under v2, so a second pre-verify would
	 * compare v2 rows against the v1 form and report a false break — recording
	 * a scary, meaningless verdict over the real one.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'audit_chain_seed_v2_rechained_at';

	/**
	 * App-config key holding the pre-migration verdict, as JSON.
	 *
	 * @var string
	 */
	public const VERDICT_KEY = 'audit_chain_seed_v2_preverify';

	/**
	 * How many rows to verify per query window.
	 *
	 * @var integer
	 */
	private const WINDOW = 500;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection    $db     Reads the audit rows to verify.
	 * @param IAppConfig       $config Stores the verdict and the done-marker.
	 * @param AuditHashService $hashes Performs the re-seal.
	 * @param LoggerInterface  $logger Records the verdict in the log as well.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppConfig $config,
		private readonly AuditHashService $hashes,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as shown by `occ maintenance:repair`.
	 *
	 * @return string The human-readable name.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public function getName(): string {
		return 'Verify and re-seal the OpenRegister audit chain for flow attribution (seed v1 → v2)';
	}//end getName()

	/**
	 * Verify under v1, record the verdict, then re-chain under v2.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->config->getValueString('openregister', self::DONE_KEY, '') !== '') {
			$output->info('Audit chain already re-sealed under seed v2; nothing to do.');
			return;
		}

		$verdict = $this->verifyUnderV1();

		// Refuse before touching a single row if the account of what we are
		// about to overwrite cannot be kept. A re-chain is not reversible, so
		// "it ran but we do not know what it ran over" is strictly worse than
		// "it has not run yet".
		if ($this->recordVerdict(verdict: $verdict, output: $output) === false) {
			$output->warning(
				'REFUSING to re-chain: the pre-verification verdict could not be stored. '
				. 'A re-chain that leaves no record of the chain state it replaced is not recoverable. '
				. 'Fix app-config writes and re-run `occ maintenance:repair`.'
			);
			return;
		}

		$this->reportVerdict(verdict: $verdict, output: $output);

		$result = $this->hashes->rechainAll();

		$this->config->setValueString('openregister', self::DONE_KEY, (new DateTime())->format('c'));

		$output->info(
			sprintf(
				'Re-sealed %d audit row(s) under seed v2; %d retention tombstone(s) carried forward.',
				(int)($result['rechained'] ?? 0),
				(int)($result['tombstonesPreserved'] ?? 0)
			)
		);
	}//end run()

	/**
	 * Say what the pre-check found, in the register an operator will actually read.
	 *
	 * A broken chain is a WARNING and not a refusal: an operator whose chain is
	 * already broken still needs their instance to upgrade, and refusing would
	 * strand them. What must not happen is the re-seal going by unremarked,
	 * because afterwards the break is no longer detectable.
	 *
	 * @param array   $verdict The pre-verification result.
	 * @param IOutput $output  Progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	private function reportVerdict(array $verdict, IOutput $output): void {
		if ($verdict['valid'] === true) {
			$output->info(
				sprintf(
					'Audit chain verified intact under seed v1 (%d row(s), %d tombstone(s)). Re-sealing under v2.',
					$verdict['entriesVerified'],
					$verdict['purgedTombstones']
				)
			);

			return;
		}

		$output->warning(
			sprintf(
				'The audit chain did NOT verify under seed v1 before re-sealing '
				. '(first break at row id %s, %d row(s) verified). '
				. 'This is recorded under app-config `%s`. The re-seal below will make the '
				. 'chain verify again — it does NOT repair the cause, and after it runs the '
				. 'break is no longer detectable. Investigate using the stored verdict.',
				var_export($verdict['brokenAt'], true),
				$verdict['entriesVerified'],
				self::VERDICT_KEY
			)
		);
	}//end reportVerdict()

	/**
	 * Walk the chain using the FROZEN v1 canonical form.
	 *
	 * Mirrors the live verifier's tombstone rule: a purged row's content no
	 * longer re-hashes to its stored value by design, but that stored value is
	 * still the link the next row committed to, so the chain is carried across
	 * it rather than reported as a break.
	 *
	 * Unsealed rows (null hash) are skipped and carry the previous hash
	 * forward, exactly as the live verifier does — a tail of unsealed rows is a
	 * smaller claim, never a false alarm.
	 *
	 * @return array{valid: bool, entriesVerified: int, brokenAt: int|null, skippedNullHashes: int, purgedTombstones: int}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AuditCanonicalV1 is deliberately static and
	 * deliberately FROZEN. Injecting it would present it as a collaborator that could be
	 * swapped or updated, which is the one thing it must never be: it describes the audit
	 * entity as it WAS, and a substituted implementation would silently invalidate the
	 * only check that can still tell an intact v1 chain from a tampered one.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	private function verifyUnderV1(): array {
		$previousHash = AuditCanonicalV1::genesisHash();
		$entriesVerified = 0;
		$skippedNullHashes = 0;
		$purgedTombstones = 0;
		$brokenAt = null;
		$afterId = 0;

		while ($brokenAt === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_audit_trails')
				->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults(self::WINDOW);

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$afterId = (int)$row['id'];

				$entity = $this->hydrate(row: $row);
				$storedHash = $entity->getHash();

				if ($storedHash === null || $storedHash === '') {
					$skippedNullHashes++;
					continue;
				}

				if ($entity->isPurged() === true) {
					// A declared tombstone: carry its stored hash forward as
					// the chain link without re-deriving its blanked content.
					$purgedTombstones++;
					$previousHash = $storedHash;
					continue;
				}

				$expected = AuditCanonicalV1::computeHash(entry: $entity, previousHash: $previousHash);

				if (hash_equals($expected, $storedHash) === false) {
					$brokenAt = (int)$row['id'];
					break;
				}

				$entriesVerified++;
				$previousHash = $storedHash;
			}//end foreach
		}//end while

		return [
			'valid' => ($brokenAt === null),
			'entriesVerified' => $entriesVerified,
			'brokenAt' => $brokenAt,
			'skippedNullHashes' => $skippedNullHashes,
			'purgedTombstones' => $purgedTombstones,
		];
	}//end verifyUnderV1()

	/**
	 * Hydrate a raw row exactly the way the live verifier does.
	 *
	 * 🔑 This deliberately mirrors `AuditHashService::mapRowToEntity()` +
	 * `AuditTrail::hydrate()` rather than using `Entity::fromRow()`. The two are
	 * very nearly equivalent, and "very nearly" is worth nothing here: this
	 * method feeds the one comparison whose job is to tell an intact chain from
	 * a tampered one. Any difference in how a date is parsed or a JSON column is
	 * decoded would change the canonical JSON, mismatch every row, and report a
	 * healthy chain as broken — the precise false verdict this whole step is
	 * built to avoid. `hydrate()` also ignores unknown properties, where
	 * `fromRow()` would throw on a column the entity does not declare.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return AuditTrail The hydrated entity.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	private function hydrate(array $row): AuditTrail {
		$mapped = [];
		foreach ($row as $key => $value) {
			// Snake_case to camelCase, character for character as the verifier does.
			$camelKey = lcfirst(str_replace('_', '', ucwords((string)$key, '_')));
			$mapped[$camelKey] = $value;
		}

		$entity = new AuditTrail();
		$entity->hydrate(object: $mapped);

		return $entity;
	}//end hydrate()

	/**
	 * Persist the verdict, and say whether it actually landed.
	 *
	 * The boolean is load-bearing: {@see run()} refuses to re-chain on false.
	 * It is verified by READING BACK what was written rather than by the write
	 * not throwing — a config backend that silently drops a write would
	 * otherwise report success and leave no record at all.
	 *
	 * @param array   $verdict The pre-verification result.
	 * @param IOutput $output  Progress output.
	 *
	 * @return boolean True when the verdict is durably stored.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/audit-hash-chain/spec.md
	 */
	private function recordVerdict(array $verdict, IOutput $output): bool {
		$payload = json_encode(
			array_merge($verdict, [
				'seedFrom' => AuditCanonicalV1::GENESIS_SEED,
				'seedTo' => 'openregister-genesis-v2',
				'verifiedAt' => (new DateTime())->format('c'),
			])
		);

		if ($payload === false) {
			return false;
		}

		$this->logger->warning(
			message: '[RechainAuditTrailForFlowAttribution] Pre-re-chain audit chain verdict: ' . $payload,
			context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
		);

		try {
			$this->config->setValueString('openregister', self::VERDICT_KEY, $payload);
		} catch (Throwable $e) {
			$output->warning('Could not store the pre-verification verdict: ' . $e->getMessage());
			return false;
		}

		// Read it back. "The setter did not throw" is not evidence the value is
		// there, and this is the one fact the whole step exists to preserve.
		return ($this->config->getValueString('openregister', self::VERDICT_KEY, '') === $payload);
	}//end recordVerdict()
}//end class
