<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Config\Types\FlowShareableConfigType;
use OCA\OpenRegister\Service\Flow\FlowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Flows as a shareable config type, now over the native store.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowShareableConfigTypeTest extends TestCase
{

    private FlowMapper&MockObject $mapper;

    private FlowService&MockObject $flows;

    private FlowShareableConfigType $type;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(FlowMapper::class);
        $this->flows  = $this->createMock(FlowService::class);
        $this->type   = new FlowShareableConfigType($this->mapper, $this->flows);
    }//end setUp()

    private function storedFlow(): Flow
    {
        $flow = new Flow();
        $flow->setUuid('flow-1');
        $flow->setApp('openregister');
        $flow->setOwner('admin');
        $flow->setOrganisation('org-x');
        $flow->setName('My flow');
        $flow->setTrigger('manual');
        $flow->setEnabled(true);
        $flow->setNodes([['id' => 'a']]);
        $flow->setEdges([['id' => 's1', 'from' => 'a', 'to' => 'b']]);

        return $flow;
    }//end storedFlow()

    public function testTheTypeIdentity(): void
    {
        $this->assertSame('openregister.flows', $this->type->getId());
        $this->assertSame('openregister-flow', $this->type->getTopic());
    }//end testTheTypeIdentity()

    public function testSerialiseKeepsPortableFieldsAndDropsInstanceFields(): void
    {
        $this->flows->method('find')->willReturn($this->storedFlow());

        $bundle = $this->type->serialise(['flowIds' => ['flow-1']]);

        $this->assertSame('openregister.flows', $bundle['type']);
        $this->assertCount(1, $bundle['flows']);
        $flow = $bundle['flows'][0];

        // Portable fields kept …
        $this->assertSame('My flow', $flow['name']);
        $this->assertSame('manual', $flow['trigger']);
        $this->assertCount(1, $flow['edges']);

        // … instance-specific fields stripped. `owner` and `organisation` name
        // an identity on the SENDING instance and mean nothing on the receiver.
        $this->assertArrayNotHasKey('id', $flow);
        $this->assertArrayNotHasKey('uuid', $flow);
        $this->assertArrayNotHasKey('owner', $flow);
        $this->assertArrayNotHasKey('organisation', $flow);
    }//end testSerialiseKeepsPortableFieldsAndDropsInstanceFields()

    public function testAFlowThatCannotBeLoadedIsSkippedRatherThanFailingTheBundle(): void
    {
        $this->flows->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

        $bundle = $this->type->serialise(['flowIds' => ['missing']]);

        $this->assertSame([], $bundle['flows']);
    }//end testAFlowThatCannotBeLoadedIsSkippedRatherThanFailingTheBundle()

    /**
     * THE SECURITY PROPERTY on the READ side.
     *
     * `serialise()` used to resolve each requested uuid through
     * `FlowMapper::findByUuid()`, which applies no organisation scoping — so
     * naming another tenant's flow uuid in `{flowIds: […]}` put that tenant's
     * whole flow definition in the bundle. The read must go through
     * `FlowService::find()`, which refuses a flow that is not the caller's.
     *
     * Asserting on the collaborator rather than on the payload is deliberate:
     * a payload assertion passes just as well when the mapper is consulted and
     * happens to return nothing, which is the state this test exists to rule
     * out.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function testSerialiseNeverReachesTheUnscopedMapper(): void
    {
        $this->mapper->expects($this->never())->method('findByUuid');
        $this->flows->expects($this->once())
            ->method('find')
            ->willReturn($this->storedFlow());

        $this->type->serialise(['flowIds' => ['flow-1']]);
    }//end testSerialiseNeverReachesTheUnscopedMapper()

    /**
     * A flow the caller may not see contributes NOTHING to the bundle — not a
     * stub, not an empty entry. `FlowService::find()` raises the same
     * `DoesNotExistException` for "not yours" as for "does not exist", so the
     * refusal is also not an enumeration oracle.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
     */
    public function testAnotherOrganisationsFlowIsNotBundled(): void
    {
        $this->flows->method('find')->willReturnCallback(
            static function (string $uuid): Flow {
                if ($uuid === 'other-org-flow') {
                    throw new \OCP\AppFramework\Db\DoesNotExistException('No such flow');
                }

                $flow = new Flow();
                $flow->setUuid($uuid);
                $flow->setName('Mine');

                return $flow;
            }
        );

        $bundle = $this->type->serialise(['flowIds' => ['mine-1', 'other-org-flow']]);

        $this->assertCount(1, $bundle['flows']);
        $this->assertSame('Mine', $bundle['flows'][0]['name']);
    }//end testAnotherOrganisationsFlowIsNotBundled()

    public function testDeserialiseWritesEachFlowIntoTheStore(): void
    {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->type->deserialise(
                [
                    'type'  => 'openregister.flows',
                    'flows' => [['name' => 'Imported', 'trigger' => 'manual', 'nodes' => [], 'edges' => []]],
                ]
                );

        $this->assertCount(1, $result['installed']);
        $this->assertNotSame('', $result['installed'][0]);
    }//end testDeserialiseWritesEachFlowIntoTheStore()

    /**
     * THE SECURITY PROPERTY. A bundle must not be able to arrive and start
     * executing against the receiving tenant's data. An imported flow lands
     * disabled and ownerless whatever the bundle claimed, and an ownerless flow
     * cannot dispatch.
     */
    public function testAnImportedFlowLandsDisabledAndOwnerless(): void
    {
        $captured = null;
        $this->mapper->method('insert')->willReturnCallback(
            function (Flow $flow) use (&$captured): Flow {
                $captured = $flow;
                return $flow;
            }
        );

        $this->type->deserialise(
                [
                    'flows' => [
                        [
                            'name'         => 'Hostile',
                            'trigger'      => 'object.created',
                            'enabled'      => true,
                            'owner'        => 'victim',
                            'organisation' => 'their-org',
                            'nodes'        => [],
                            'edges'        => [],
                        ],
                    ],
                ]
                );

        $this->assertNotNull($captured);
        $this->assertFalse((bool) $captured->getEnabled(), 'an imported flow must not arrive enabled');
        $this->assertNull($captured->getOwner(), 'an imported flow must not adopt the sender\'s owner');
        $this->assertNull($captured->getOrganisation());
        $this->assertFalse($captured->canDispatch(), 'and it must therefore not be dispatchable');
    }//end testAnImportedFlowLandsDisabledAndOwnerless()

    /**
     * A fresh id per import: two instances that both installed the same bundle
     * would otherwise hold flows with the same id, making a sub-flow reference
     * or a run row ambiguous the moment they exchanged anything again.
     */
    public function testAnImportedFlowGetsAFreshId(): void
    {
        $ids = [];
        $this->mapper->method('insert')->willReturnCallback(
            function (Flow $flow) use (&$ids): Flow {
                $ids[] = $flow->getUuid();
                return $flow;
            }
        );

        $bundle = ['flows' => [['name' => 'A', 'nodes' => [], 'edges' => []]]];
        $this->type->deserialise($bundle);
        $this->type->deserialise($bundle);

        $this->assertCount(2, $ids);
        $this->assertNotSame($ids[0], $ids[1], 'each import must mint its own id');
    }//end testAnImportedFlowGetsAFreshId()

    public function testARoundTripPreservesTheFlowShape(): void
    {
        $this->flows->method('find')->willReturn($this->storedFlow());
        $bundle = $this->type->serialise(['flowIds' => ['flow-1']]);

        $captured = null;
        $this->mapper->method('insert')->willReturnCallback(
            function (Flow $flow) use (&$captured): Flow {
                $captured = $flow;
                return $flow;
            }
        );

        $this->type->deserialise($bundle);

        $this->assertSame('My flow', $captured->getName());
        $this->assertSame('manual', $captured->getTrigger());
        $this->assertSame([['id' => 's1', 'from' => 'a', 'to' => 'b']], $captured->getEdges());
    }//end testARoundTripPreservesTheFlowShape()
}//end class
