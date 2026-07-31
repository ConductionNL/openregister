<?php

/**
 * Unit tests for ObjectReadNode.
 *
 * The read is the half the engine was missing: a flow could write objects and
 * never ask a question about them (#2235). These tests pin the properties that
 * make it usable AND safe — it reads as the run owner, it bounds what it
 * returns, and a failed read is never mistaken for an empty one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode
 */
final class ObjectReadNodeTest extends TestCase
{

    /**
     * The mocked object service.
     *
     * @var ObjectService
     */
    private ObjectService $objects;

    /**
     * The node under test.
     *
     * @var ObjectReadNode
     */
    private ObjectReadNode $node;

    /**
     * A run context naming a real owner.
     *
     * @var array<string, mixed>
     */
    private array $ownedContext = ['triggeredBy' => 'alice'];


    /**
     * Wire the node over mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $register = new Register();
        $register->setId(1);
        $register->setSlug('example-hydra-cache');

        $schema = new Schema();
        $schema->setId(2);
        $schema->setSlug('example-lock');

        $this->objects = $this->createMock(originalClassName: ObjectService::class);
        $this->objects->method('setRegister')->willReturnSelf();
        $this->objects->method('setSchema')->willReturnSelf();

        $registers = $this->createMock(originalClassName: RegisterMapper::class);
        $registers->method('find')->willReturn($register);

        $schemas = $this->createMock(originalClassName: SchemaMapper::class);
        $schemas->method('findBySlugInIds')->willReturn($schema);
        $schemas->method('find')->willReturn($schema);

        $users = $this->createMock(originalClassName: IUserManager::class);
        $users->method('get')->willReturnCallback(
            function (string $uid): ?IUser {
                if ($uid !== 'alice') {
                    return null;
                }

                $user = $this->createMock(originalClassName: IUser::class);
                $user->method('getUID')->willReturn('alice');

                return $user;
            }
        );

        $l10n = $this->createMock(originalClassName: IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->node = new ObjectReadNode(
            $this->objects,
            $registers,
            $schemas,
            $users,
            $l10n,
            $this->createMock(originalClassName: IURLGenerator::class)
        );

    }//end setUp()


    /**
     * A stored object, as the mapper would return it.
     *
     * @param string $uuid   The object uuid.
     * @param array  $record The object's own fields.
     *
     * @return ObjectEntity The entity.
     */
    private function entity(string $uuid, array $record): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject($record);

        return $object;

    }//end entity()


    /**
     * The step's baseline configuration.
     *
     * @param array $overrides Config overrides.
     *
     * @return array The configuration.
     */
    private function config(array $overrides=[]): array
    {
        return array_merge(
            ['register' => 'example-hydra-cache', 'schema' => 'example-lock'],
            $overrides
        );

    }//end config()


    /**
     * Results land under `output`, beside the record the item was carrying.
     *
     * @return void
     */
    public function testResultsLandBesideTheIncomingRecord(): void
    {
        $this->objects->method('findAll')->willReturn(
            [$this->entity('uuid-1', ['holder' => 'issue-7'])]
        );

        $out = $this->node->execute(
            [FlowItems::item(json: ['repo' => 'ConductionNL/hydra'])],
            $this->config(['output' => 'locks']),
            $this->ownedContext
        );

        $this->assertSame('ConductionNL/hydra', $out[0]['json']['repo']);
        $this->assertCount(1, $out[0]['json']['locks']);
        $this->assertSame('issue-7', $out[0]['json']['locks'][0]['holder']);

    }//end testResultsLandBesideTheIncomingRecord()


    /**
     * Every result carries its uuid, because that is what a follow-up names it by.
     *
     * A reaper reads locks and then DELETES them. The uuid does not live in the
     * record's own fields, so without this the read would be unusable for the
     * case it exists to serve.
     *
     * @return void
     */
    public function testEveryResultCarriesItsUuid(): void
    {
        $this->objects->method('findAll')->willReturn(
            [$this->entity('uuid-1', ['holder' => 'a']), $this->entity('uuid-2', ['holder' => 'b'])]
        );

        $out = $this->node->execute(
            [FlowItems::item(json: [])],
            $this->config(),
            $this->ownedContext
        );

        $this->assertSame(['uuid-1', 'uuid-2'], array_column($out[0]['json']['objects'], 'uuid'));

    }//end testEveryResultCarriesItsUuid()


    /**
     * Filters are templated from the item, so two items ask their own questions.
     *
     * @return void
     */
    public function testFiltersAreRenderedFromTheItem(): void
    {
        $seen = [];
        $this->objects->method('findAll')->willReturnCallback(
            function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use (&$seen): array {
                $seen[] = $config;
                return [];
            }
        );

        $this->node->execute(
            [FlowItems::item(json: ['ref' => 'hydra-410']), FlowItems::item(json: ['ref' => 'hydra-411'])],
            $this->config(['filters' => ['holder' => '{{ref}}']]),
            $this->ownedContext
        );

        $this->assertSame('hydra-410', $seen[0]['filters']['holder']);
        $this->assertSame('hydra-411', $seen[1]['filters']['holder']);

    }//end testFiltersAreRenderedFromTheItem()


    /**
     * The result set is bounded, and an absurd `limit` is capped rather than honoured.
     *
     * A reaper over a runaway lock table should take a batch and come back next
     * tick, not build a million-item walk in memory and time the run out.
     *
     * @return void
     */
    public function testTheResultSetIsBounded(): void
    {
        $seen = [];
        $this->objects->method('findAll')->willReturnCallback(
            function (array $config=[], bool $_rbac=true, bool $_multitenancy=true) use (&$seen): array {
                $seen[] = $config['limit'];
                return [];
            }
        );

        $this->node->execute([FlowItems::item(json: [])], $this->config(), $this->ownedContext);
        $this->node->execute([FlowItems::item(json: [])], $this->config(['limit' => 5]), $this->ownedContext);
        $this->node->execute([FlowItems::item(json: [])], $this->config(['limit' => 999999]), $this->ownedContext);
        $this->node->execute([FlowItems::item(json: [])], $this->config(['limit' => 0]), $this->ownedContext);

        $this->assertSame([100, 5, 1000, 100], $seen);

    }//end testTheResultSetIsBounded()


    /**
     * A run with no owner reads NOTHING, and says why.
     *
     * A flow is authored data. If a flow could read past RBAC then authoring
     * one would be a disclosure escalation, exactly as writing past it would be
     * a privilege escalation.
     *
     * @return void
     */
    public function testARunWithNoOwnerReadsNothing(): void
    {
        $this->objects->expects($this->never())->method('findAll');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/triggeredBy/');

        $this->node->execute([FlowItems::item(json: [])], $this->config(), ['triggeredBy' => null]);

    }//end testARunWithNoOwnerReadsNothing()


    /**
     * An owner that is not a real account also fails closed.
     *
     * @return void
     */
    public function testAnUnknownOwnerAlsoFailsClosed(): void
    {
        $this->objects->expects($this->never())->method('findAll');

        $this->expectException(RuntimeException::class);

        $this->node->execute([FlowItems::item(json: [])], $this->config(), ['triggeredBy' => 'mallory']);

    }//end testAnUnknownOwnerAlsoFailsClosed()


    /**
     * A FAILED read throws rather than looking like an empty one.
     *
     * "No objects matched" and "the read did not happen" are different answers.
     * A reaper that reads the second as the first quietly stops reaping, and
     * nothing about the run says so.
     *
     * @return void
     */
    public function testAFailedReadIsNotAnEmptyResult(): void
    {
        $this->objects->method('findAll')->willThrowException(new \Exception('table is gone'));

        $this->expectException(RuntimeException::class);

        $this->node->execute([FlowItems::item(json: [])], $this->config(), $this->ownedContext);

    }//end testAFailedReadIsNotAnEmptyResult()


    /**
     * An empty branch asks nothing and is not an error.
     *
     * The owner is not even resolved: there is nothing to attribute.
     *
     * @return void
     */
    public function testAnEmptyBranchReadsNothing(): void
    {
        $this->objects->expects($this->never())->method('findAll');

        $this->assertSame([], $this->node->execute([], $this->config(), []));

    }//end testAnEmptyBranchReadsNothing()


    /**
     * A step that cannot name what it reads is refused when the flow is saved.
     *
     * @return void
     */
    public function testAStepMustNameARegisterAndSchema(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['schema' => 'example-lock']);

    }//end testAStepMustNameARegisterAndSchema()


    /**
     * Malformed filters are refused at save time too.
     *
     * @return void
     */
    public function testMalformedFiltersAreRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig($this->config(['filters' => 'holder=me']));

    }//end testMalformedFiltersAreRefused()
}//end class
