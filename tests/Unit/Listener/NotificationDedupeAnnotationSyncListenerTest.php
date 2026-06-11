<?php

/**
 * Unit tests for NotificationDedupeAnnotationSyncListener.
 *
 * @category Tests\Unit\Listener
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\NotificationDedupeAnnotationSyncListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the schema-save dedup-state sync listener.
 */
class NotificationDedupeAnnotationSyncListenerTest extends TestCase
{

    public function testDeletesByRuleForRemovedKeysOnSchemaCreated(): void
    {
        $schema = $this->schemaWithRules(7, ['alpha']);

        $mapper = $this->createMock(NotificationDedupeStateMapper::class);
        $mapper->method('listRuleKeysForSchema')->with(7)->willReturn(['alpha', 'beta', 'gamma']);

        $deleted = [];
        $mapper->method('deleteByRule')->willReturnCallback(function (int $sid, string $key) use (&$deleted): int {
            $deleted[] = [$sid, $key];
            return 1;
        });

        $listener = new NotificationDedupeAnnotationSyncListener($mapper, new NullLogger());
        $listener->handle(new SchemaCreatedEvent($schema));

        $keys = array_map(static fn ($p) => $p[1], $deleted);
        sort($keys);
        $this->assertSame(['beta', 'gamma'], $keys);
    }//end testDeletesByRuleForRemovedKeysOnSchemaCreated()

    public function testKeepsRulesThatStillExistOnSchemaUpdated(): void
    {
        $newSchema = $this->schemaWithRules(11, ['alpha', 'beta']);
        $oldSchema = $this->schemaWithRules(11, ['alpha', 'beta', 'gamma']);

        $mapper = $this->createMock(NotificationDedupeStateMapper::class);
        $mapper->method('listRuleKeysForSchema')->with(11)->willReturn(['alpha', 'beta']);
        $mapper->expects($this->never())->method('deleteByRule');

        $listener = new NotificationDedupeAnnotationSyncListener($mapper, new NullLogger());
        $listener->handle(new SchemaUpdatedEvent($newSchema, $oldSchema));
    }//end testKeepsRulesThatStillExistOnSchemaUpdated()

    public function testIgnoresUnrelatedEvents(): void
    {
        $mapper = $this->createMock(NotificationDedupeStateMapper::class);
        $mapper->expects($this->never())->method('listRuleKeysForSchema');

        $listener = new NotificationDedupeAnnotationSyncListener($mapper, new NullLogger());
        $listener->handle(new Event());
    }//end testIgnoresUnrelatedEvents()

    /**
     * @param array<int, string> $ruleKeys
     */
    private function schemaWithRules(int $id, array $ruleKeys): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $rules = [];
        foreach ($ruleKeys as $key) {
            $rules[$key] = ['trigger' => ['type' => 'scheduled', 'intervalSec' => 60]];
        }

        $schema->setConfiguration(['x-openregister-notifications' => $rules]);
        return $schema;
    }//end schemaWithRules()
}//end class
