<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\NotificationHistory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the NotificationHistory entity.
 *
 * Verifies the entity round-trips every typed field through its
 * accessors and that `jsonSerialize()` returns the documented shape.
 *
 * @spec openspec/changes/notificatie-engine/tasks.md
 */
class NotificationHistoryTest extends TestCase
{

    public function testAllFieldsRoundTrip(): void
    {
        $entity = new NotificationHistory();
        $now    = new DateTime('2026-05-01T10:00:00+00:00');

        $entity->setRuleId('object-updated');
        $entity->setSchemaId('schema-1');
        $entity->setRegisterId('register-1');
        $entity->setObjectUuid('uuid-123');
        $entity->setChannel('nc-notification');
        $entity->setRecipient('alice');
        $entity->setSubject('Object updated');
        $entity->setStatus('dispatched');
        $entity->setErrorMessage(null);
        $entity->setLocale('nl');
        $entity->setDispatchedAt($now);

        $this->assertSame('object-updated', $entity->getRuleId());
        $this->assertSame('schema-1', $entity->getSchemaId());
        $this->assertSame('register-1', $entity->getRegisterId());
        $this->assertSame('uuid-123', $entity->getObjectUuid());
        $this->assertSame('nc-notification', $entity->getChannel());
        $this->assertSame('alice', $entity->getRecipient());
        $this->assertSame('Object updated', $entity->getSubject());
        $this->assertSame('dispatched', $entity->getStatus());
        $this->assertNull($entity->getErrorMessage());
        $this->assertSame('nl', $entity->getLocale());
        $this->assertSame($now, $entity->getDispatchedAt());
    }

    public function testJsonSerializeReturnsDocumentedShape(): void
    {
        $entity = new NotificationHistory();
        $entity->setRuleId('object-updated');
        $entity->setSchemaId('schema-1');
        $entity->setRegisterId('register-1');
        $entity->setObjectUuid('uuid-123');
        $entity->setChannel('webhook');
        $entity->setRecipient('__webhook__');
        $entity->setSubject('Object updated');
        $entity->setStatus('rate-limited');
        $entity->setErrorMessage('bucket drained');
        $entity->setLocale(null);
        $entity->setDispatchedAt(new DateTime('2026-05-01T10:00:00+00:00'));

        $serialized = $entity->jsonSerialize();
        $this->assertSame('object-updated', $serialized['ruleId']);
        $this->assertSame('schema-1', $serialized['schemaId']);
        $this->assertSame('register-1', $serialized['registerId']);
        $this->assertSame('uuid-123', $serialized['objectUuid']);
        $this->assertSame('webhook', $serialized['channel']);
        $this->assertSame('__webhook__', $serialized['recipient']);
        $this->assertSame('Object updated', $serialized['subject']);
        $this->assertSame('rate-limited', $serialized['status']);
        $this->assertSame('bucket drained', $serialized['errorMessage']);
        $this->assertNull($serialized['locale']);
        $this->assertSame('2026-05-01T10:00:00+00:00', $serialized['dispatchedAt']);
    }

    public function testJsonSerializeWithNullDispatchedAt(): void
    {
        $entity = new NotificationHistory();
        $entity->setRuleId('test');
        $entity->setChannel('email');
        $entity->setRecipient('alice');
        $entity->setStatus('dispatched');

        $serialized = $entity->jsonSerialize();
        $this->assertNull($serialized['dispatchedAt']);
    }
}
