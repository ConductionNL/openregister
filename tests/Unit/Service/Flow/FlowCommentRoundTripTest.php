<?php

/**
 * A flow's rationale survives the database.
 *
 * Flows are authored two ways: through the UI, and as definition files that
 * carry their reasoning in a top-level `$comment`. Until `openregister_flows`
 * had a column for it, the second kind lost that text the moment it was saved
 * — silently, because a flow with no rationale is indistinguishable from one
 * whose author wrote none.
 *
 * The tests below pin the three claims that loss turned on: the field is
 * stored, the file's `$comment` spelling reaches it, and the explicit API
 * field wins when a payload carries both.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Storage of the `comment` field on a flow.
 */
final class FlowCommentRoundTripTest extends TestCase
{

    /**
     * The organisation an update-path flow is scoped to.
     *
     * `find()` refuses any flow that does not belong to the ACTIVE
     * organisation, and `belongsTo()` is false when either side is empty — so
     * without a resolvable one, every update would throw before reaching the
     * field logic and the tests would pass for the wrong reason.
     */
    private const ORGANISATION = 'org-under-test';

    /**
     * The flow the mapper was handed on insert.
     *
     * @var Flow|null
     */
    private ?Flow $inserted = null;


    /**
     * A container that resolves an organisation service naming ORGANISATION.
     *
     * @return ContainerInterface The configured double.
     */
    private function organisationContainer(): ContainerInterface
    {
        $organisation = new class {


            /**
             * The active organisation's uuid.
             *
             * @return string The uuid.
             */
            public function getUuid(): string
            {
                return 'org-under-test';

            }


        };

        $organisationService = new class($organisation)
        {


            /**
             * @param object $organisation The active organisation stub.
             */
            public function __construct(private readonly object $organisation)
            {

            }


            /**
             * The active organisation.
             *
             * @return object The organisation stub.
             */
            public function getActiveOrganisation(): object
            {
                return $this->organisation;

            }


        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($organisationService);

        return $container;

    }//end organisationContainer()


    /**
     * Build a FlowService whose collaborators are all doubles.
     *
     * @param FlowMapper              $mapper    The flow mapper double.
     * @param ContainerInterface|null $container The container double, or null for a bare one.
     *
     * @return FlowService The service under test.
     */
    private function serviceWith(FlowMapper $mapper, ?ContainerInterface $container=null): FlowService
    {
        return new FlowService(
            $mapper,
            $this->createMock(FlowTriggerIndex::class),
            $this->createMock(FlowRunService::class),
            $this->createMock(FlowRunAdvancer::class),
            $this->createMock(FlowRunMapper::class),
            $this->createMock(FlowRunStepMapper::class),
            $this->createMock(FlowStateMapper::class),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class),
            ($container ?? $this->createMock(ContainerInterface::class))
        );

    }//end serviceWith()


    /**
     * Save a payload through a real FlowService and return the stored flow.
     *
     * The mapper double returns whatever it was given, so the assertion reads
     * the entity the service actually built rather than a fixture.
     *
     * @param array<string, mixed> $data The payload to save.
     *
     * @return Flow The flow as it reached the mapper.
     */
    private function saved(array $data): Flow
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('insert')->willReturnCallback(
            function (Flow $flow): Flow {
                $this->inserted = $flow;
                return $flow;
            }
        );

        $this->serviceWith($mapper)->save(data: $data);

        $this->assertInstanceOf(Flow::class, $this->inserted, 'the service should have inserted a flow');

        return $this->inserted;

    }//end saved()


    /**
     * The API field is stored and serialised back.
     *
     * @return void
     */
    public function testCommentIsStoredAndSerialised(): void
    {
        $flow = $this->saved(['name' => 'reaper', 'comment' => 'why this exists']);

        $this->assertSame('why this exists', $flow->getComment());
        $this->assertSame('why this exists', $flow->jsonSerialize()['comment']);

    }//end testCommentIsStoredAndSerialised()


    /**
     * A definition file's `$comment` reaches the column.
     *
     * This is the regression the field was added for: `$comment` is the only
     * spelling the on-disk definitions use, and it is not a legal column name,
     * so nothing but an explicit normalisation carries it across.
     *
     * @return void
     */
    public function testDollarCommentFromADefinitionFileIsStored(): void
    {
        $flow = $this->saved(['name' => 'reaper', '$comment' => 'the file rationale']);

        $this->assertSame('the file rationale', $flow->getComment());

    }//end testDollarCommentFromADefinitionFileIsStored()


    /**
     * The explicit field beats the file spelling when a payload carries both.
     *
     * A UI edit sends `comment`; the `$comment` alongside it is whatever the
     * definition the flow was imported from happened to carry, so letting the
     * alias win would discard the newer of the two on every save.
     *
     * @return void
     */
    public function testTheExplicitFieldWinsOverTheAlias(): void
    {
        $flow = $this->saved(
            [
                'name'     => 'reaper',
                'comment'  => 'edited in the UI',
                '$comment' => 'stale, from the file',
            ]
        );

        $this->assertSame('edited in the UI', $flow->getComment());

    }//end testTheExplicitFieldWinsOverTheAlias()


    /**
     * A partial update that mentions neither key leaves the stored comment alone.
     *
     * `applyEditableFields` only touches keys that are present, and a partial
     * update — enabling a flow, say — must not blank 90 lines of rationale as
     * a side effect. This goes through the UPDATE path deliberately: on a
     * create the field starts null, so the same assertion there would hold no
     * matter what the code did.
     *
     * @return void
     */
    public function testAPartialUpdateDoesNotBlankTheStoredComment(): void
    {
        $existing = new Flow();
        $existing->setUuid('flow-uuid-1');
        $existing->setOrganisation(self::ORGANISATION);
        $existing->setComment('90 lines of why');

        $updated = null;

        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findByUuid')->willReturn($existing);
        $mapper->method('update')->willReturnCallback(
            function (Flow $flow) use (&$updated): Flow {
                $updated = $flow;
                return $flow;
            }
        );

        $this->serviceWith($mapper, $this->organisationContainer())
            ->save(data: ['enabled' => true], uuid: 'flow-uuid-1');

        $this->assertInstanceOf(Flow::class, $updated, 'the service should have updated a flow');
        $this->assertSame('90 lines of why', $updated->getComment());

    }//end testAPartialUpdateDoesNotBlankTheStoredComment()


    /**
     * An explicit null DOES blank it.
     *
     * The counterpart to the test above, and the reason that one is not
     * vacuous: "absent" and "explicitly cleared" are different payloads and
     * must land differently, or the field could never be emptied once set.
     *
     * @return void
     */
    public function testAnExplicitNullClearsTheComment(): void
    {
        $existing = new Flow();
        $existing->setUuid('flow-uuid-1');
        $existing->setOrganisation(self::ORGANISATION);
        $existing->setComment('90 lines of why');

        $updated = null;

        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findByUuid')->willReturn($existing);
        $mapper->method('update')->willReturnCallback(
            function (Flow $flow) use (&$updated): Flow {
                $updated = $flow;
                return $flow;
            }
        );

        $this->serviceWith($mapper, $this->organisationContainer())
            ->save(data: ['comment' => null], uuid: 'flow-uuid-1');

        $this->assertInstanceOf(Flow::class, $updated, 'the service should have updated a flow');
        $this->assertNull($updated->getComment());

    }//end testAnExplicitNullClearsTheComment()


}//end class
