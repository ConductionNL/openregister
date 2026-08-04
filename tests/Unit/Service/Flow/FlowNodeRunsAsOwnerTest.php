<?php

/**
 * Regression: a flow's object READS must run as the run owner.
 *
 * `ObjectReadNode::read()` resolved an `IUser $owner`, declared it, documented
 * it as "the run owner, whose RBAC applies" — and never referenced it. Psalm
 * reported it as an unused param. So the read ran under whatever subject the
 * ambient session carried, which for a scheduled run is none at all. Both the
 * RBAC filter and the organisation filter treat a sessionless CLI context as
 * trusted and skip themselves, so a scheduled flow read PAST the very access
 * control the parameter names.
 *
 * `ObjectWriteNode::findMatch()` had the same hole and did not even declare the
 * parameter: its writes were attributed to the owner while the scan deciding
 * WHICH row to write ran unattributed.
 *
 * Every existing owner test asserts only that an owner is RESOLVABLE. None
 * asserted the read is PERFORMED as that owner, which is exactly why a dead
 * parameter survived review. These assert the subject.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
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
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks owner-scoped reads in both object nodes.
 */
class FlowNodeRunsAsOwnerTest extends TestCase
{

    /** @var MockObject&ObjectService */
    private $objects;

    private Register $register;

    private Schema $schema;

    /**
     * The uid that was the acting subject when findAll() was called.
     *
     * @var string|null
     */
    private ?string $subjectAtRead = null;

    /**
     * Whether findAll() ran inside a runAs() scope at all.
     *
     * @var bool
     */
    private bool $readWasScoped = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->register = new Register();
        $this->register->setId(1);
        $this->register->setSlug('example-hydra-cache');

        $this->schema = new Schema();
        $this->schema->setId(2);
        $this->schema->setSlug('example-cache-entry');

        $this->objects = $this->createMock(ObjectService::class);
        $this->objects->method('setRegister')->willReturnSelf();
        $this->objects->method('setSchema')->willReturnSelf();

        // Model runAs() the way the real one behaves: it establishes an acting
        // subject for the duration of the callable. Recording the subject at
        // the moment findAll() runs is what proves the read is scoped — an
        // assertion on the argument alone would pass even if the callable were
        // invoked outside the scope.
        $acting = null;
        $this->objects->method('runAs')->willReturnCallback(
            static function (IUser $user, callable $operation) use (&$acting) {
                $previous = $acting;
                $acting   = $user->getUID();

                try {
                    return $operation();
                } finally {
                    $acting = $previous;
                }
            }
        );

        $this->objects->method('findAll')->willReturnCallback(
            function () use (&$acting): array {
                $this->subjectAtRead = $acting;
                $this->readWasScoped = ($acting !== null);

                $entity = new ObjectEntity();
                $entity->setUuid('u-1');
                $entity->setObject(['title' => 'a thing']);

                return [$entity];
            }
        );

        $this->subjectAtRead = null;
        $this->readWasScoped = false;
    }//end setUp()

    /**
     * Build a user manager resolving `alice` only.
     *
     * @return MockObject&IUserManager
     */
    private function userManager(): IUserManager
    {
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(
            function (string $uid): ?IUser {
                if ($uid !== 'alice') {
                    return null;
                }

                $user = $this->createMock(IUser::class);
                $user->method('getUID')->willReturn('alice');

                return $user;
            }
        );

        return $userManager;
    }//end userManager()

    /**
     * Build a translation double.
     *
     * @return MockObject&IL10N
     */
    private function l10n(): IL10N
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters)
        );

        return $l10n;
    }//end l10n()

    /**
     * Build the mappers both nodes need.
     *
     * @return array{0: RegisterMapper, 1: SchemaMapper}
     */
    private function mappers(): array
    {
        $registers = $this->createMock(RegisterMapper::class);
        $registers->method('find')->willReturn($this->register);

        $schemas = $this->createMock(SchemaMapper::class);
        $schemas->method('findBySlugInIds')->willReturn($this->schema);
        $schemas->method('find')->willReturn($this->schema);

        return [$registers, $schemas];
    }//end mappers()

    /**
     * The read node performs its read as the run owner.
     *
     * @return void
     */
    public function testTheReadNodeReadsAsTheRunOwner(): void
    {
        [$registers, $schemas] = $this->mappers();

        $node = new ObjectReadNode(
            $this->objects,
            $registers,
            $schemas,
            $this->userManager(),
            $this->l10n(),
            $this->createMock(IURLGenerator::class)
        );

        $node->execute(
            [FlowItems::item(json: [])],
            [
                'register' => 'example-hydra-cache',
                'schema'   => 'example-cache-entry',
            ],
            ['triggeredBy' => 'alice']
        );

        $this->assertTrue($this->readWasScoped, 'the read must run inside an owner scope');
        $this->assertSame('alice', $this->subjectAtRead, 'the read must run as the run owner');
    }//end testTheReadNodeReadsAsTheRunOwner()

    /**
     * The write node's own match lookup also runs as the run owner.
     *
     * Its writes were already attributed; the scan choosing WHICH row to write
     * was not. Checking one subject and acting on another is the gap.
     *
     * @return void
     */
    public function testTheWriteNodeMatchLookupAlsoRunsAsTheRunOwner(): void
    {
        [$registers, $schemas] = $this->mappers();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn(1000);

        $this->objects->method('patchObject')->willReturnCallback(
            function (): ObjectEntity {
                $entity = new ObjectEntity();
                $entity->setUuid('u-1');
                $entity->setObject([]);

                return $entity;
            }
        );

        $node = new ObjectWriteNode(
            $this->objects,
            $registers,
            $schemas,
            $this->userManager(),
            $appConfig,
            $this->l10n(),
            $this->createMock(IURLGenerator::class)
        );

        $node->execute(
            [['json' => ['sourceId' => 's-1']]],
            [
                'register'  => 'example-hydra-cache',
                'schema'    => 'example-cache-entry',
                'operation' => ObjectWriteNode::OP_UPDATE,
                'fields'    => ['status' => 'seen'],
                'match'     => [['property' => 'sourceId', 'value' => 's-1']],
            ],
            ['triggeredBy' => 'alice']
        );

        $this->assertTrue($this->readWasScoped, 'the match lookup must run inside an owner scope');
        $this->assertSame('alice', $this->subjectAtRead, 'the match lookup must run as the run owner');
    }//end testTheWriteNodeMatchLookupAlsoRunsAsTheRunOwner()

    /**
     * The scope is released once the read is done.
     *
     * A long-lived process must never carry one run's identity into the next.
     *
     * @return void
     */
    public function testTheOwnerScopeIsReleasedAfterTheRead(): void
    {
        [$registers, $schemas] = $this->mappers();

        $node = new ObjectReadNode(
            $this->objects,
            $registers,
            $schemas,
            $this->userManager(),
            $this->l10n(),
            $this->createMock(IURLGenerator::class)
        );

        $node->execute(
            [FlowItems::item(json: [])],
            [
                'register' => 'example-hydra-cache',
                'schema'   => 'example-cache-entry',
            ],
            ['triggeredBy' => 'alice']
        );

        // A second read with no owner must fail closed rather than inheriting
        // alice from the run before it.
        $this->expectException(RuntimeException::class);

        $node->execute(
            [FlowItems::item(json: [])],
            [
                'register' => 'example-hydra-cache',
                'schema'   => 'example-cache-entry',
            ],
            []
        );
    }//end testTheOwnerScopeIsReleasedAfterTheRead()
}//end class
