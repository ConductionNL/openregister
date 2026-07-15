<?php

/**
 * Audit hash chain consistency tests.
 *
 * Proves the security-critical invariants of the hash chain sealing
 * mechanism: canonical JSON excludes the chain-linking fields themselves,
 * the hash formula is exactly sha256(previousHash . canonicalJson), and the
 * computed hash is tamper-sensitive to both payload and chain-position
 * changes.
 *
 * sealRow()'s database IO (reading a row, deriving previousHash from the
 * prior row, writing hash + previous_hash) is intentionally NOT re-mocked
 * here — it is covered by the live e2e verifyChain() checks (valid chain +
 * tamper detection). What is verified here is the hashing math that
 * sealRow(), verifyChain(), and getHashBefore() all share via
 * computeHash()/getCanonicalJson(), so a regression in the formula itself
 * would be caught regardless of which caller exercises it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests proving hash-chain consistency invariants for AuditHashService.
 */
class AuditHashChainConsistencyTest extends TestCase
{
    private AuditHashService $service;
    private IDBConnection&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db      = $this->createMock(IDBConnection::class);
        $this->service = new AuditHashService(
            $this->db,
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * (a) getCanonicalJson must exclude BOTH `hash` and `previousHash`, even
     * when they are populated with real (non-empty) values — proving the
     * exclusion is not merely an artifact of the fields being null/empty.
     */
    public function testCanonicalJsonExcludesHashAndPreviousHashWhenPopulated(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('entry-uuid');
        $entry->setAction('update');
        $entry->setHash(str_repeat('a', 64));
        $entry->setPreviousHash(str_repeat('b', 64));

        $json = $this->service->getCanonicalJson($entry);

        $this->assertStringNotContainsString('"hash"', $json);
        $this->assertStringNotContainsString('"previousHash"', $json);

        $data = json_decode($json, true);
        $this->assertArrayNotHasKey('hash', $data);
        $this->assertArrayNotHasKey('previousHash', $data);
    }

    /**
     * (a) Two entries that differ ONLY in hash/previousHash must produce the
     * IDENTICAL canonical JSON, since those fields are excluded from the
     * canonical form used to derive the hash.
     */
    public function testCanonicalJsonIsIdenticalRegardlessOfHashFieldValues(): void
    {
        $entryA = new AuditTrail();
        $entryA->setUuid('same-uuid');
        $entryA->setAction('create');
        $entryA->setHash('hash-one');
        $entryA->setPreviousHash('prev-one');

        $entryB = new AuditTrail();
        $entryB->setUuid('same-uuid');
        $entryB->setAction('create');
        $entryB->setHash('hash-two-completely-different');
        $entryB->setPreviousHash('prev-two-completely-different');

        $this->assertSame(
            $this->service->getCanonicalJson($entryA),
            $this->service->getCanonicalJson($entryB)
        );
    }

    /**
     * (c) computeHash must equal EXACTLY sha256(previousHash . canonicalJson)
     * — verifying the concatenation order and hash algorithm directly
     * against the formula, not just indirectly via determinism checks.
     */
    public function testComputeHashMatchesExactFormula(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('formula-uuid');
        $entry->setAction('create');
        $entry->setObject(42);

        $previousHash = 'deadbeef'.str_repeat('0', 56);

        $canonicalJson = $this->service->getCanonicalJson($entry);
        $expected       = hash('sha256', $previousHash.$canonicalJson);

        $actual = $this->service->computeHash($entry, $previousHash);

        $this->assertSame($expected, $actual);
    }

    /**
     * (c) The formula must concatenate previousHash BEFORE the canonical
     * JSON, not after — swapping the order must NOT reproduce the same
     * hash (guards against an accidental refactor reversing the order).
     */
    public function testComputeHashOrderMattersPreviousHashFirst(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('order-uuid');
        $entry->setAction('create');

        $previousHash  = 'previous-hash-value';
        $canonicalJson = $this->service->getCanonicalJson($entry);

        $correctOrder   = hash('sha256', $previousHash.$canonicalJson);
        $reversedOrder  = hash('sha256', $canonicalJson.$previousHash);

        $actual = $this->service->computeHash($entry, $previousHash);

        $this->assertSame($correctOrder, $actual);
        $this->assertNotSame($reversedOrder, $actual);
    }

    /**
     * (b) Tamper sensitivity: changing a single payload field (the
     * `changed` diff) must change the resulting hash, proving the hash
     * actually binds to the entry's content and not just its identifiers.
     */
    public function testComputeHashChangesWhenPayloadFieldChanges(): void
    {
        $previousHash = $this->service->getGenesisHash();

        $original = new AuditTrail();
        $original->setUuid('tamper-uuid');
        $original->setAction('update');
        $original->setChanged(['field' => ['old' => 'a', 'new' => 'b']]);

        $tampered = new AuditTrail();
        $tampered->setUuid('tamper-uuid');
        $tampered->setAction('update');
        $tampered->setChanged(['field' => ['old' => 'a', 'new' => 'TAMPERED']]);

        $originalHash = $this->service->computeHash($original, $previousHash);
        $tamperedHash = $this->service->computeHash($tampered, $previousHash);

        $this->assertNotSame($originalHash, $tamperedHash);
    }

    /**
     * (b) Tamper sensitivity: re-parenting the SAME entry onto a different
     * previousHash must change the resulting hash — this is what makes the
     * structure a *chain* rather than a set of independently-hashed rows.
     */
    public function testComputeHashChangesWhenPreviousHashChanges(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('chain-uuid');
        $entry->setAction('delete');

        $genesisHash = $this->service->getGenesisHash();
        $otherHash   = hash('sha256', 'some-other-prior-entry');

        $hashFromGenesis = $this->service->computeHash($entry, $genesisHash);
        $hashFromOther   = $this->service->computeHash($entry, $otherHash);

        $this->assertNotSame($hashFromGenesis, $hashFromOther);
    }

    /**
     * (b) Determinism: computing the hash twice for the identical entry +
     * previousHash pair must yield the identical hash — a prerequisite for
     * verifyChain() being able to recompute and compare stored hashes.
     */
    public function testComputeHashIsFullyDeterministicAcrossRepeatedCalls(): void
    {
        $entry = new AuditTrail();
        $entry->setUuid('deterministic-uuid');
        $entry->setAction('create');
        $entry->setObject(7);
        $entry->setRegister(3);

        $previousHash = $this->service->getGenesisHash();

        $hashes = [];
        for ($i = 0; $i < 5; $i++) {
            $hashes[] = $this->service->computeHash($entry, $previousHash);
        }

        $this->assertCount(1, array_unique($hashes));
    }
}
