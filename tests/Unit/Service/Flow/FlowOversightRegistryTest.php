<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use PHPUnit\Framework\TestCase;

/**
 * The registry's whole contract is that it FAILS CLOSED.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class FlowOversightRegistryTest extends TestCase
{
    /**
     * A check that answers with a fixed verdict, or throws.
     *
     * @param string      $id     The check id.
     * @param string|null $reason The veto reason, or null to consent.
     * @param boolean     $throws Whether the check throws instead of answering.
     *
     * @return IFlowOversightCheck The stub.
     */
    private function check(string $id, ?string $reason, bool $throws=false): IFlowOversightCheck
    {
        $stub = $this->createMock(IFlowOversightCheck::class);
        $stub->method('getId')->willReturn($id);

        if ($throws === true) {
            $stub->method('veto')->willThrowException(new \RuntimeException('check exploded'));
        } else {
            $stub->method('veto')->willReturn($reason);
        }

        return $stub;
    }//end check()

    private function registry(IFlowOversightCheck ...$checks): FlowOversightRegistry
    {
        $registry = new FlowOversightRegistry(new \Psr\Log\NullLogger());
        foreach ($checks as $c) {
            $registry->register($c);
        }

        return $registry;
    }//end registry()

    /**
     * An empty registry consents: enabling oversight on an instance with no
     * registered checks is a no-op, not a wall.
     */
    public function testAnEmptyRegistryConsents(): void
    {
        $this->assertNull($this->registry()->firstRefusal([]));
    }//end testAnEmptyRegistryConsents()

    public function testAConsentingCheckAllowsTheHop(): void
    {
        $registry = $this->registry($this->check('a.ok', null));

        $this->assertNull($registry->firstRefusal([]));
    }//end testAConsentingCheckAllowsTheHop()

    public function testAVetoIsReturnedWithItsCheckId(): void
    {
        $registry = $this->registry($this->check('a.budget', 'Budget exhausted'));

        $refusal = $registry->firstRefusal([]);

        $this->assertSame('a.budget', $refusal['checkId']);
        $this->assertSame('Budget exhausted', $refusal['reason']);
    }//end testAVetoIsReturnedWithItsCheckId()

    /**
     * THE POINT OF THE CLASS. A check that throws has not consented. Treating
     * its failure as consent would mean an oversight outage silently disables
     * oversight — precisely when it is most likely to matter.
     */
    public function testAThrowingCheckRefusesRatherThanFailingOpen(): void
    {
        $registry = $this->registry($this->check('a.broken', null, throws: true));

        $refusal = $registry->firstRefusal([]);

        $this->assertNotNull($refusal, 'a throwing check must not be read as consent');
        $this->assertSame('a.broken', $refusal['checkId']);
    }//end testAThrowingCheckRefusesRatherThanFailingOpen()

    /**
     * A blank reason is not a veto — a check must say WHY, or it has consented.
     * Otherwise an accidental `return ''` becomes an unexplainable hard stop.
     */
    public function testABlankReasonIsTreatedAsConsent(): void
    {
        $registry = $this->registry($this->check('a.blank', '   '));

        $this->assertNull($registry->firstRefusal([]));
    }//end testABlankReasonIsTreatedAsConsent()

    public function testTheFirstRefusalWins(): void
    {
        $registry = $this->registry(
            $this->check('a.ok', null),
            $this->check('b.no', 'nope'),
            $this->check('c.also-no', 'also nope')
        );

        $this->assertSame('b.no', $registry->firstRefusal([])['checkId']);
    }//end testTheFirstRefusalWins()

    /**
     * Re-registering an id replaces it, matching the node registry, so an app
     * can deliberately override a check it ships itself.
     */
    public function testRegisteringTheSameIdReplacesTheEarlierCheck(): void
    {
        $registry = $this->registry(
            $this->check('same.id', 'first'),
            $this->check('same.id', 'second')
        );

        $this->assertCount(1, $registry->all());
        $this->assertSame('second', $registry->firstRefusal([])['reason']);
    }//end testRegisteringTheSameIdReplacesTheEarlierCheck()
}//end class
