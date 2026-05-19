<?php

/**
 * NotificationHistoryTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationHistory entity.
 */
class NotificationHistoryTest extends TestCase
{

    /**
     * Typed fields round-trip correctly.
     */
    public function testTypedFieldRoundTrip(): void
    {
        $entity = new NotificationHistory();
        $entity->setRuleId(ruleId: 'my-rule');
        $entity->setChannel(channel: 'nc-notification');
        $entity->setRecipient(recipient: 'alice');
        $entity->setStatus(status: 'dispatched');
        $entity->setObjectUuid(objectUuid: 'obj-uuid-1');
        $entity->setSchemaId(schemaId: 'schema-42');
        $entity->setRegisterId(registerId: 'register-7');

        self::assertSame('my-rule', $entity->getRuleId());
        self::assertSame('nc-notification', $entity->getChannel());
        self::assertSame('alice', $entity->getRecipient());
        self::assertSame('dispatched', $entity->getStatus());
        self::assertSame('obj-uuid-1', $entity->getObjectUuid());
        self::assertSame('schema-42', $entity->getSchemaId());
        self::assertSame('register-7', $entity->getRegisterId());
    }

    /**
     * jsonSerialize() returns documented shape.
     */
    public function testJsonSerializeShape(): void
    {
        $entity = new NotificationHistory();
        $entity->setRuleId(ruleId: 'rule-1');
        $entity->setChannel(channel: 'email');
        $entity->setRecipient(recipient: 'bob@example.com');
        $entity->setStatus(status: 'rate-limited');

        $data = $entity->jsonSerialize();

        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('ruleId', $data);
        self::assertArrayHasKey('channel', $data);
        self::assertArrayHasKey('recipient', $data);
        self::assertArrayHasKey('objectUuid', $data);
        self::assertArrayHasKey('schemaId', $data);
        self::assertArrayHasKey('registerId', $data);
        self::assertArrayHasKey('status', $data);
        self::assertArrayHasKey('dispatchedAt', $data);

        self::assertSame('rule-1', $data['ruleId']);
        self::assertSame('email', $data['channel']);
        self::assertSame('rate-limited', $data['status']);
    }

    /**
     * dispatchedAt defaults to now on construction.
     */
    public function testDispatchedAtDefaultsToNow(): void
    {
        $before = new DateTime('-1 second');
        $entity = new NotificationHistory();
        $after  = new DateTime('+1 second');

        $at = $entity->getDispatchedAt();
        self::assertGreaterThanOrEqual($before, $at);
        self::assertLessThanOrEqual($after, $at);
    }
}
