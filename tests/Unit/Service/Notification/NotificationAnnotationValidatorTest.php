<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

class NotificationAnnotationValidatorTest extends TestCase
{
    private NotificationAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new NotificationAnnotationValidator();
    }

    public function testNoAnnotationIsValid(): void
    {
        $this->assertSame([], $this->v->validate(['properties' => []]));
    }

    public function testEmptyMapIsRejected(): void
    {
        $errors = $this->v->validate(['x-openregister-notifications' => [], 'properties' => []]);
        $this->assertSame('notifications-empty', $errors[0]['code']);
    }

    public function testBadTriggerIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'x' => [
                    'trigger' => ['type' => 'cron'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-trigger', $codes);
    }

    public function testBadChannelIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'x' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['unknown-channel'],
                    'subject' => 'hi',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-channel', $codes);
    }

    public function testRecipientFieldMustExist(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'x' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'field', 'field' => 'unknown']],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                ],
            ],
            'properties' => ['known' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-recipient-field-unknown', $codes);
    }

    public function testValidNoErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'transition', 'action' => 'open'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'Meeting opened',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }
}
