<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `critical`/`digest` dialect extensions added by
 * notification-delivery-windows: boolean grammar for `critical`, the
 * `digest.schedule`/`digest.at`/`digest.weekday`/`digest.timezone` grammar,
 * and mutual exclusion between a `digest` schedule and the pre-existing
 * rolling `coalesce` window.
 *
 * @spec openspec/changes/notification-delivery-windows/specs/notificatie-engine/spec.md
 */
class NotificationAnnotationValidatorDeliveryWindowTest extends TestCase
{
    private NotificationAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new NotificationAnnotationValidator();
    }

    /**
     * @param array<string, mixed> $overrides Keys to merge onto the base spec.
     *
     * @return array<string, mixed> A full schema with one notification.
     */
    private function schema(array $overrides): array
    {
        $spec = array_merge(
            [
                'trigger'    => ['type' => 'created'],
                'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                'channels'   => ['nc-notification'],
                'subject'    => 'hi',
            ],
            $overrides
        );

        return [
            'x-openregister-notifications' => ['x' => $spec],
            'properties'                   => [],
        ];
    }

    public function testNoCriticalOrDigestKeyIsValid(): void
    {
        $errors = $this->v->validate($this->schema([]));
        $this->assertSame([], $errors);
    }

    public function testCriticalTrueIsAccepted(): void
    {
        $errors = $this->v->validate($this->schema(['critical' => true]));
        $this->assertSame([], $errors);
    }

    public function testCriticalFalseIsAccepted(): void
    {
        $errors = $this->v->validate($this->schema(['critical' => false]));
        $this->assertSame([], $errors);
    }

    public function testCriticalMustBeBoolean(): void
    {
        $errors = $this->v->validate($this->schema(['critical' => 'yes']));
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-critical-not-boolean', $codes);
    }

    public function testDailyDigestIsAccepted(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Europe/Amsterdam']])
        );
        $this->assertSame([], $errors);
    }

    public function testWeeklyDigestWithWeekdayIsAccepted(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'weekly', 'at' => '08:00', 'weekday' => 1]])
        );
        $this->assertSame([], $errors);
    }

    public function testWeeklyDigestWithoutWeekdayIsRejected(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'weekly', 'at' => '08:00']])
        );
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-weekly-missing-weekday', $codes);
    }

    public function testDailyDigestWithWeekdayIsRejected(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'daily', 'at' => '07:00', 'weekday' => 1]])
        );
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-weekday-not-allowed', $codes);
    }

    public function testBadDigestScheduleValueIsRejected(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'hourly', 'at' => '07:00']])
        );
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-bad-schedule', $codes);
    }

    public function testBadDigestTimeFormatIsRejected(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'daily', 'at' => '7am']])
        );
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-bad-time', $codes);
    }

    public function testBadDigestTimezoneIsRejected(): void
    {
        $errors = $this->v->validate(
            $this->schema(['digest' => ['schedule' => 'daily', 'at' => '07:00', 'timezone' => 'Not/AZone']])
        );
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-bad-timezone', $codes);
    }

    public function testDigestMustBeAnObject(): void
    {
        $errors = $this->v->validate($this->schema(['digest' => 'daily']));
        $codes  = array_column($errors, 'code');
        $this->assertContains('notification-digest-malformed', $codes);
    }

    /**
     * Scenario: rolling digest period and fixed digest schedule are
     * mutually exclusive — a rule declaring both `coalesce` (the
     * codebase's rolling-batching mechanism) and `digest` (fixed
     * time-of-day) MUST fail schema-save validation.
     */
    public function testDigestAndCoalesceAreMutuallyExclusive(): void
    {
        $errors = $this->v->validate(
            $this->schema(
                [
                    'coalesce' => ['windowSeconds' => 300],
                    'digest'   => ['schedule' => 'daily', 'at' => '07:00'],
                ]
            )
        );
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-digest-and-coalesce-mutually-exclusive', $codes);
    }

    public function testCoalesceAloneWithoutDigestIsUnaffected(): void
    {
        $errors = $this->v->validate($this->schema(['coalesce' => ['windowSeconds' => 300]]));
        $this->assertSame([], $errors);
    }
}
