<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Handoff\HandoffKindContracts}.
 *
 * Asserts the seed kind-contract map mirrors the hydra contract specs
 * (handoff-contract-case + handoff-contract-order-chain) exactly: the four
 * kinds, their mandatory field sets, and their optional field sets.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
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
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffKindContracts;
use PHPUnit\Framework\TestCase;

/**
 * HandoffKindContractsTest.
 */
class HandoffKindContractsTest extends TestCase
{

    private const NS = 'https://openregister.app/ns#';


    /**
     * The four seed kinds are registered — and nothing else.
     *
     * @return void
     */
    public function testSeedKindsAreRegistered(): void
    {
        $this->assertEqualsCanonicalizing(
            [self::NS.'Case', self::NS.'Quote', self::NS.'Contract', self::NS.'Invoice'],
            HandoffKindContracts::kinds()
        );
        $this->assertTrue(HandoffKindContracts::isContractKind(self::NS.'Case'));
        $this->assertFalse(HandoffKindContracts::isContractKind(self::NS.'Vendor'));
        $this->assertFalse(HandoffKindContracts::isContractKind(''));

    }//end testSeedKindsAreRegistered()


    /**
     * ns#Case mandatory/optional fields match the hydra contract spec.
     *
     * @return void
     */
    public function testCaseContractFields(): void
    {
        $this->assertEqualsCanonicalizing(
            ['title', 'summary', 'channel', 'source'],
            HandoffKindContracts::mandatoryFields(self::NS.'Case')
        );
        $this->assertEqualsCanonicalizing(
            ['title', 'summary', 'channel', 'source', 'requester', 'priority'],
            HandoffKindContracts::allFields(self::NS.'Case')
        );

    }//end testCaseContractFields()


    /**
     * Order-chain kinds match the hydra contract spec field sets.
     *
     * @return void
     */
    public function testOrderChainContractFields(): void
    {
        $this->assertEqualsCanonicalizing(
            ['title', 'counterparty', 'currency', 'totalAmount', 'source'],
            HandoffKindContracts::mandatoryFields(self::NS.'Quote')
        );
        $this->assertEqualsCanonicalizing(
            ['title', 'counterparty', 'currency', 'totalAmount', 'startDate', 'source'],
            HandoffKindContracts::mandatoryFields(self::NS.'Contract')
        );
        // Invoice numbering / VAT / ledger are explicitly NOT contract fields.
        $this->assertEqualsCanonicalizing(
            ['counterparty', 'currency', 'totalAmount', 'source'],
            HandoffKindContracts::mandatoryFields(self::NS.'Invoice')
        );
        $this->assertEqualsCanonicalizing(
            ['counterparty', 'currency', 'totalAmount', 'source', 'lines', 'dueDate'],
            HandoffKindContracts::allFields(self::NS.'Invoice')
        );

    }//end testOrderChainContractFields()


    /**
     * Unknown kinds yield empty field sets (never raise).
     *
     * @return void
     */
    public function testUnknownKindYieldsEmptySets(): void
    {
        $this->assertSame([], HandoffKindContracts::mandatoryFields('https://example.org/ns#Nope'));
        $this->assertSame([], HandoffKindContracts::allFields('https://example.org/ns#Nope'));

    }//end testUnknownKindYieldsEmptySets()
}//end class
