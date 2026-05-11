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

    public function testGroupsRecipientAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'transition', 'action' => 'open'],
                    'recipients' => [['kind' => 'groups', 'groups' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testRelationRecipientAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'transition', 'action' => 'open'],
                    'recipients' => [['kind' => 'relation', 'relation' => 'approvers']],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testWebhookChannelRequiresUrl(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['webhook'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-webhook-no-url', $codes);
    }

    public function testWebhookChannelWithUrlAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['webhook'],
                    'webhook' => ['url' => 'https://hooks.example.com/x'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testObjectAclRecipientRequiresPermission(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'object-acl']],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-recipient-acl-bad-permission', $codes);
    }

    public function testObjectAclWithReadPermissionAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'object-acl', 'permission' => 'read']],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testExpressionRecipientRequiresResolver(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'expression']],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-recipient-expression-no-resolver', $codes);
    }

    public function testExpressionRecipientWithResolverAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'expression', 'resolver' => 'OCA\\Foo\\Resolver']],
                    'channels' => ['nc-notification'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testTalkChannelRequiresToken(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'transition', 'action' => 'open'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['talk'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-talk-no-token', $codes);
    }

    public function testTalkChannelWithTokenAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'transition', 'action' => 'open'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['talk'],
                    'talk' => ['token' => 'abc123'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testEmailAndActivityChannelsAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'opened' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification', 'email', 'activity'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testScheduledTriggerRequiresIntervalSec(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'nightly' => [
                    'trigger' => ['type' => 'scheduled'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['email'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-scheduled-bad-interval', $codes);
    }

    public function testScheduledTriggerRejectsTooShortInterval(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'nightly' => [
                    'trigger' => ['type' => 'scheduled', 'intervalSec' => 30],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['email'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-scheduled-bad-interval', $codes);
    }

    public function testScheduledTriggerWithIntervalAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'nightly' => [
                    'trigger' => ['type' => 'scheduled', 'intervalSec' => 86400],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['email'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testThresholdTriggerRequiresAggregationOpAndValue(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'overLimit' => [
                    'trigger' => ['type' => 'threshold'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['email'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-threshold-no-aggregation', $codes);
        $this->assertContains('notification-threshold-bad-op', $codes);
        $this->assertContains('notification-threshold-no-value', $codes);
    }

    public function testThresholdTriggerWithFullSpecAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'overLimit' => [
                    'trigger' => ['type' => 'threshold', 'aggregation' => 'totalCount', 'op' => 'gt', 'value' => 100],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['email'],
                    'subject' => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    // ====================================================================
    // NL/EN i18n — Open spec item:
    //   "Notification messages MUST support i18n in Dutch and English."
    // ====================================================================

    public function testPerLocaleSubjectMapAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'localized' => [
                    'trigger' => ['type' => 'updated'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => [
                        'nl' => 'Object {{title}} bijgewerkt',
                        'en' => 'Object {{title}} updated',
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPerLocaleSubjectMapWithDefaultLocaleAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'localized' => [
                    'trigger' => ['type' => 'updated'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => [
                        'defaultLocale' => 'nl',
                        'nl' => 'NL',
                        'en' => 'EN',
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testPerLocaleSubjectMapWithEmptyLocaleRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'localized' => [
                    'trigger' => ['type' => 'updated'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => [
                        'nl' => '',
                        'en' => 'EN',
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-subject-locale', $codes);
    }

    public function testPerLocaleSubjectMapMissingDefaultLocaleRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'localized' => [
                    'trigger' => ['type' => 'updated'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => [
                        'defaultLocale' => 'fr',
                        'nl' => 'NL',
                        'en' => 'EN',
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-default-locale', $codes);
    }

    public function testPerLocaleSubjectMapWithOnlyDefaultLocaleKeyRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'localized' => [
                    'trigger' => ['type' => 'updated'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => [
                        'defaultLocale' => 'nl',
                    ],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-no-subject', $codes);
    }

    // ====================================================================
    // Organisation gate — Open spec item:
    // "Notifications MUST be scoped to organisations for multi-tenant
    //  deployments." The dispatcher honours an optional `organisation`
    //  field at the rule level (string OR array of strings); the
    //  validator accepts both shapes and surfaces a single
    //  `notification-bad-organisation` error code for malformed inputs.
    // ====================================================================
    public function testOrganisationGateAcceptsSingleStringUuid(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => 'org-uuid-123',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertNotContains('notification-bad-organisation', $codes);
    }

    public function testOrganisationGateAcceptsArrayOfStrings(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => ['org-a', 'org-b'],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertNotContains('notification-bad-organisation', $codes);
    }

    public function testOrganisationGateRejectsEmptyString(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => '',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-organisation', $codes);
    }

    public function testOrganisationGateRejectsEmptyArray(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => [],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-organisation', $codes);
    }

    public function testOrganisationGateRejectsArrayWithEmptyEntry(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => ['org-a', ''],
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-organisation', $codes);
    }

    public function testOrganisationGateRejectsNonStringNonArray(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'pinned' => [
                    'trigger' => ['type' => 'created'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels' => ['nc-notification'],
                    'subject' => 'hi',
                    'organisation' => 12345,
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-bad-organisation', $codes);
    }

    // -----------------------------------------------------------------------
    // calculatedChange trigger validation
    // -----------------------------------------------------------------------

    public function testCalculatedChangeTriggerWithValidSpecProducesNoErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'coverageDrop' => [
                    'trigger' => [
                        'type'       => 'calculatedChange',
                        'field'      => 'coveragePercent',
                        'condition'  => ['lt' => 0.85],
                        'previously' => ['gte' => 0.85],
                    ],
                    'recipients' => [['kind' => 'users', 'users' => ['officer']]],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'Coverage dropped',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors, 'A well-formed calculatedChange spec must have no errors.');
    }

    public function testCalculatedChangeTriggerWithoutFieldIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'noField' => [
                    'trigger' => [
                        'type'      => 'calculatedChange',
                        'condition' => ['lt' => 0.85],
                    ],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-calculated-change-no-field', $codes);
    }

    public function testCalculatedChangeTriggerWithBadConditionOpIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'badOp' => [
                    'trigger' => [
                        'type'      => 'calculatedChange',
                        'field'     => 'score',
                        'condition' => ['between' => 5],
                    ],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-calculated-change-bad-op', $codes);
    }

    public function testCalculatedChangeTriggerWithEmptyConditionArrayIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'emptyClause' => [
                    'trigger' => [
                        'type'      => 'calculatedChange',
                        'field'     => 'score',
                        'condition' => [],
                    ],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'x',
                ],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('notification-calculated-change-bad-clause', $codes);
    }

    public function testCalculatedChangeTriggerWithoutConditionOrPreviouslyIsValid(): void
    {
        // Open-gate variant: field is declared but no condition/previously operators.
        $errors = $this->v->validate([
            'x-openregister-notifications' => [
                'anyChange' => [
                    'trigger' => [
                        'type'  => 'calculatedChange',
                        'field' => 'score',
                    ],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'channels'   => ['nc-notification'],
                    'subject'    => 'score changed',
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors, 'calculatedChange without condition/previously is valid (open gate).');
    }
}
