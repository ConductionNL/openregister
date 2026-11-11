<?php

/**
 * A batch of reveal rows chains exactly like any other audit row.
 *
 * The flush hands its rows to `AuditTrailMapper::insertAuditTrails()`, which
 * seals each chunk into the hash chain. That is the contract the whole of
 * `sensitive-field-reveal-audit` rests on, and this is what makes it a checked
 * claim rather than a comment: the chain is rebuilt here over a FIXTURE of
 * reveal rows, with the real `AuditHashService` arithmetic, and then broken on
 * purpose to prove the verification can tell.
 *
 * 🔴 WHY A FIXTURE CHAIN AND NOT THE LIVE ONE. The trail is APPEND-ONLY and
 * hash-chained: rows written to it to prove a test cannot be removed afterwards
 * without breaking the chain for everything after them. Seeding a live instance
 * to demonstrate integrity would therefore permanently pollute the artefact
 * whose value is that it is not polluted. What the live instance CAN answer is
 * asked of it read-only instead, and the PR body records the number.
 *
 * WHAT REMAINS UNPROVEN HERE, said plainly rather than implied: that the
 * mapper's INSERT-then-seal pass behaves as documented against a real database
 * under concurrency. This proves the arithmetic and the link structure; the
 * concurrency of the seal is `harden-audit-seal-concurrency`'s and has its own
 * suite.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
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
 * @spec openspec/specs/audit-hash-chain/spec.md
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use PHPUnit\Framework\TestCase;

/**
 * Pins that a run of reveal rows forms a verifiable chain, and that a tampered
 * one does not.
 */
class RevealChainIntegrityTest extends TestCase {

	/**
	 * The genesis seed, written out rather than read from the service.
	 *
	 * A test that took the seed from the code under test would agree with any
	 * seed, including one somebody changed by accident — which is exactly what
	 * writing it out caught. `openspec/specs/audit-hash-chain/spec.md` still
	 * says `openregister-genesis-v1` in its scenario, and both
	 * `AuditHashService` and the live chain of the development instance are on
	 * `-v2`: its first sealed row carries
	 * `ce429ddf6fb0601d34d2a40bb8758c79610f4d59cd9342aa9c5c1e3ac46e4fce`, which
	 * is SHA-256 of the v2 seed. `flow-object-attribution` task 4.1 owns that
	 * move and is unticked, so the code went ahead of both its own task and the
	 * spec text.
	 *
	 * The SHIPPED value is asserted here, because this suite is about what the
	 * chain does; the stale requirement text is corrected where it lives.
	 *
	 * @var string
	 */
	private const GENESIS_SEED = 'openregister-genesis-v2';

	/**
	 * One reveal row, as `RevealFlusher::rowFor()` builds it.
	 *
	 * @param string $objectUuid The object revealed.
	 * @param string $property The property revealed.
	 *
	 * @return AuditTrail The row.
	 */
	private function revealRow(string $objectUuid, string $property): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid('uuid-' . $objectUuid . '-' . $property);
		$row->setAction(RevealCollector::ACTION);
		$row->setUser('alice');
		$row->setUserName('alice');
		$row->setObjectUuid($objectUuid);
		$row->setSchema(24);
		$row->setRegister(14);
		$row->setChanged(['property' => $property]);
		$row->setCreated(new DateTime('2026-09-18 12:00:00'));

		return $row;
	}//end revealRow()

	/**
	 * The canonical JSON of a row, as `AuditHashService` computes it.
	 *
	 * Reimplemented from the SPEC's three clauses — every field except `hash`
	 * and `previousHash`, sorted keys, compact — rather than called on the
	 * service. Calling the service would make this test agree with whatever the
	 * service does, which is the one thing a chain test must not do.
	 *
	 * @param AuditTrail $row The row.
	 *
	 * @return string The canonical JSON.
	 */
	private function canonical(AuditTrail $row): string {
		$data = $row->jsonSerialize();
		unset($data['hash'], $data['previousHash']);
		ksort($data);

		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}//end canonical()

	/**
	 * Seal a run of rows, returning them chained.
	 *
	 * @param AuditTrail[] $rows The rows, in order.
	 * @param string|null $from The hash to chain from; the genesis when null.
	 *
	 * @return AuditTrail[] The same rows, sealed.
	 */
	private function seal(array $rows, ?string $from = null): array {
		$previous = ($from ?? hash('sha256', self::GENESIS_SEED));
		foreach ($rows as $row) {
			$row->setPreviousHash($previous);
			$hash = hash('sha256', $previous . $this->canonical($row));
			$row->setHash($hash);
			$previous = $hash;
		}

		return $rows;
	}//end seal()

	/**
	 * Verify a run, the way the endpoint does.
	 *
	 * @param AuditTrail[] $rows The rows, in order.
	 * @param string|null $from The hash the run should chain from.
	 *
	 * @return array{valid: bool, entriesVerified: int, brokeAt: int|null} The verdict.
	 */
	private function verify(array $rows, ?string $from = null): array {
		$previous = ($from ?? hash('sha256', self::GENESIS_SEED));
		$checked = 0;

		foreach ($rows as $index => $row) {
			if ($row->getPreviousHash() !== $previous) {
				return ['valid' => false, 'entriesVerified' => $checked, 'brokeAt' => $index];
			}

			$expected = hash('sha256', $previous . $this->canonical($row));
			if ($row->getHash() !== $expected) {
				return ['valid' => false, 'entriesVerified' => $checked, 'brokeAt' => $index];
			}

			$previous = $row->getHash();
			$checked++;
		}

		return ['valid' => true, 'entriesVerified' => $checked, 'brokeAt' => null];
	}//end verify()

	/**
	 * 🔴 This test's canonical form is the SERVICE's canonical form.
	 *
	 * Everything below reimplements the spec's three clauses rather than
	 * calling `AuditHashService`, on purpose: a chain test that used the code
	 * under test to describe the chain would agree with any implementation,
	 * including a broken one. The cost of that choice is that the two could
	 * drift, and a drift would mean this suite verifies a chain nobody writes.
	 *
	 * So the two are tied together HERE, once, on a real reveal row: the
	 * service's `getCanonicalJson()` and this file's `canonical()` must agree
	 * character for character, and the service's genesis must be the seed the
	 * spec names. If either moves, this fails and says so, rather than the
	 * whole suite quietly becoming a test of itself.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testThisSuiteAgreesWithTheRealHashService(): void {
		$service = new \OCA\OpenRegister\Service\AuditHashService(
			db: $this->createMock(\OCP\IDBConnection::class),
			lockingProvider: $this->createMock(\OCP\Lock\ILockingProvider::class),
			logger: $this->createMock(\Psr\Log\LoggerInterface::class),
			appConfig: $this->createMock(\OCP\IAppConfig::class)
		);

		$row = $this->revealRow('object-1', 'bsn');

		$this->assertSame(
			$service->getCanonicalJson(entry: $row),
			$this->canonical($row),
			'this suite must describe the chain the service actually writes'
		);

		$this->assertSame(
			$service->getGenesisHash(),
			hash('sha256', self::GENESIS_SEED),
			'the genesis seed is the one the spec names'
		);

		$this->assertSame(
			$service->computeHash(entry: $row, previousHash: 'abc'),
			hash('sha256', 'abc' . $this->canonical($row)),
			'and the hash is composed the same way'
		);
	}//end testThisSuiteAgreesWithTheRealHashService()

	/**
	 * A batch of forty reveals forms one verifiable chain.
	 *
	 * The list case, which is the one the feature exists for: forty citizens'
	 * numbers on one screen is forty rows, and they chain.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testAListOfFortyRevealsChains(): void {
		$rows = [];
		for ($i = 0; $i < 40; $i++) {
			$rows[] = $this->revealRow('object-' . $i, 'bsn');
		}

		$verdict = $this->verify($this->seal($rows));

		$this->assertTrue($verdict['valid']);
		$this->assertSame(40, $verdict['entriesVerified']);
	}//end testAListOfFortyRevealsChains()

	/**
	 * The first row of an empty trail chains to the genesis hash.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testTheFirstRowChainsToGenesis(): void {
		$rows = $this->seal([$this->revealRow('object-1', 'bsn')]);

		$this->assertSame(
			hash('sha256', self::GENESIS_SEED),
			$rows[0]->getPreviousHash()
		);
	}//end testTheFirstRowChainsToGenesis()

	/**
	 * A batch appended to an existing trail chains to its last hash.
	 *
	 * This is the real case for a reveal flush: the trail is never empty by the
	 * time anybody reads a BSN.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testABatchChainsOntoWhatCameBefore(): void {
		$earlier = $this->seal([$this->revealRow('object-0', 'bsn')]);
		$lastHash = $earlier[0]->getHash();

		$batch = $this->seal(
			[$this->revealRow('object-1', 'bsn'), $this->revealRow('object-2', 'bsn')],
			$lastHash
		);

		$this->assertSame($lastHash, $batch[0]->getPreviousHash());
		$this->assertTrue($this->verify($batch, $lastHash)['valid']);
	}//end testABatchChainsOntoWhatCameBefore()

	/**
	 * 🔴 Editing a row's content breaks the chain, and the verification says where.
	 *
	 * The assertion the chain exists for. Without it every test above would
	 * pass over a "verification" that returns true unconditionally.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testEditingARowBreaksTheChain(): void {
		$rows = $this->seal(
			[
				$this->revealRow('object-1', 'bsn'),
				$this->revealRow('object-2', 'bsn'),
				$this->revealRow('object-3', 'bsn'),
			]
		);

		$this->assertTrue($this->verify($rows)['valid'], 'sound before the edit');

		// Somebody quietly changes WHO saw it.
		$rows[1]->setUser('bob');

		$verdict = $this->verify($rows);
		$this->assertFalse($verdict['valid']);
		$this->assertSame(1, $verdict['brokeAt']);
		$this->assertSame(1, $verdict['entriesVerified'], 'the rows before the edit are still sound');
	}//end testEditingARowBreaksTheChain()

	/**
	 * Removing a row from the middle breaks the chain.
	 *
	 * The tamper an auditor is most likely to meet: not an edit, a deletion of
	 * the look somebody would rather nobody found.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testRemovingARowBreaksTheChain(): void {
		$rows = $this->seal(
			[
				$this->revealRow('object-1', 'bsn'),
				$this->revealRow('object-2', 'bsn'),
				$this->revealRow('object-3', 'bsn'),
			]
		);

		unset($rows[1]);

		$verdict = $this->verify(array_values($rows));
		$this->assertFalse($verdict['valid']);
		$this->assertSame(1, $verdict['entriesVerified']);
	}//end testRemovingARowBreaksTheChain()

	/**
	 * Re-ordering two rows breaks the chain.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testReorderingBreaksTheChain(): void {
		$rows = $this->seal(
			[
				$this->revealRow('object-1', 'bsn'),
				$this->revealRow('object-2', 'bsn'),
			]
		);

		$this->assertFalse($this->verify([$rows[1], $rows[0]])['valid']);
	}//end testReorderingBreaksTheChain()

	/**
	 * The hash covers the property name, so changing WHICH field was seen shows.
	 *
	 * A reveal row's whole content is who, what object and which property. If
	 * the property were outside the canonical form, the one field that says
	 * what was disclosed could be rewritten freely.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testTheHashCoversTheRevealedPropertyName(): void {
		$rows = $this->seal([$this->revealRow('object-1', 'bsn')]);

		$rows[0]->setChanged(['property' => 'postalCode']);

		$this->assertFalse($this->verify($rows)['valid']);
	}//end testTheHashCoversTheRevealedPropertyName()

	/**
	 * The canonical form excludes the two chain fields themselves.
	 *
	 * Including them would make the hash depend on itself, which is not a
	 * subtle bug: nothing would ever verify.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testTheCanonicalFormExcludesTheChainFields(): void {
		$row = $this->revealRow('object-1', 'bsn');
		$before = $this->canonical($row);

		$row->setHash('deadbeef');
		$row->setPreviousHash('cafebabe');

		$this->assertSame($before, $this->canonical($row));
	}//end testTheCanonicalFormExcludesTheChainFields()

	/**
	 * The canonical form is key-sorted and compact.
	 *
	 * Two instances that serialise the same fields in a different order would
	 * hash differently, and a chain written by one and verified by the other
	 * would read as tampered on a perfectly sound trail.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testTheCanonicalFormIsSortedAndCompact(): void {
		$canonical = $this->canonical($this->revealRow('object-1', 'bsn'));

		$this->assertStringNotContainsString("\n", $canonical);
		$this->assertStringNotContainsString(': ', $canonical);

		$keys = array_keys(json_decode($canonical, true));
		$sorted = $keys;
		sort($sorted);
		$this->assertSame($sorted, $keys);
	}//end testTheCanonicalFormIsSortedAndCompact()
}//end class
