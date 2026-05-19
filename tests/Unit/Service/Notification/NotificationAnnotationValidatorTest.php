<?php

/**
 * NotificationAnnotationValidatorTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationAnnotationValidator.
 */
class NotificationAnnotationValidatorTest extends TestCase
{

    private NotificationAnnotationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new NotificationAnnotationValidator();
    }

    private function validRule(array $overrides=[]): array
    {
        return array_merge(
            [
                'trigger'    => 'created',
                'subject'    => 'Object created',
                'recipients' => [['kind' => 'groups', 'value' => ['admin']]],
                'channels'   => ['nc-notification'],
            ],
            $overrides
        );
    }

    /**
     * No annotation key means no errors.
     */
    public function testNoAnnotationKeyMeansNoErrors(): void
    {
        self::assertEmpty($this->validator->validate(configuration: []));
    }

    /**
     * Valid rule passes.
     */
    public function testValidRulePasses(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule()]];
        self::assertEmpty($this->validator->validate(configuration: $config));
    }

    /**
     * Missing trigger is flagged.
     */
    public function testMissingTriggerFlagged(): void
    {
        $rule   = $this->validRule(overrides: ['trigger' => null]);
        unset($rule['trigger']);
        $config = ['x-openregister-notifications' => [$rule]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
    }

    /**
     * Unknown trigger is flagged.
     */
    public function testUnknownTriggerFlagged(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['trigger' => 'frobulated'])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('frobulated', $errors[0]);
    }

    /**
     * All five trigger types accepted.
     *
     * @dataProvider validTriggersProvider
     */
    public function testValidTriggersAccepted(string $trigger): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['trigger' => $trigger])]];
        self::assertEmpty($this->validator->validate(configuration: $config));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validTriggersProvider(): array
    {
        return [
            'created'    => ['created'],
            'updated'    => ['updated'],
            'transition' => ['transition'],
            'scheduled'  => ['scheduled'],
            'threshold'  => ['threshold'],
        ];
    }

    /**
     * Missing subject is flagged.
     */
    public function testMissingSubjectFlagged(): void
    {
        $rule = $this->validRule();
        unset($rule['subject']);
        $config = ['x-openregister-notifications' => [$rule]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-no-subject', $errors[0]);
    }

    /**
     * Locale map subject is accepted.
     */
    public function testLocaleMapSubjectAccepted(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['subject' => ['nl' => 'Aangemaakt', 'en' => 'Created']])]];
        self::assertEmpty($this->validator->validate(configuration: $config));
    }

    /**
     * Locale map with valid defaultLocale is accepted.
     */
    public function testLocaleMapWithDefaultLocaleAccepted(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['subject' => ['nl' => 'Aangemaakt', 'en' => 'Created', 'defaultLocale' => 'nl']])]];
        self::assertEmpty($this->validator->validate(configuration: $config));
    }

    /**
     * Empty string locale value is rejected.
     */
    public function testEmptyLocaleValueRejected(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['subject' => ['nl' => '']])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-subject-locale', $errors[0]);
    }

    /**
     * Missing defaultLocale target is rejected.
     */
    public function testMissingDefaultLocaleTargetRejected(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['subject' => ['nl' => 'Aangemaakt', 'defaultLocale' => 'de']])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-default-locale', $errors[0]);
    }

    /**
     * Map with only defaultLocale key is rejected.
     */
    public function testMapWithOnlyDefaultLocaleKeyRejected(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['subject' => ['defaultLocale' => 'nl']])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-no-subject', $errors[0]);
    }

    /**
     * Organisation gate: single string is accepted.
     */
    public function testOrganisationGateSingleStringAccepted(): void
    {
        $rule   = ['organisation' => 'org-uuid-123'];
        self::assertEmpty($this->validator->validateOrganisationGate(rule: $rule));
    }

    /**
     * Organisation gate: array of strings is accepted.
     */
    public function testOrganisationGateArrayAccepted(): void
    {
        $rule = ['organisation' => ['org-1', 'org-2']];
        self::assertEmpty($this->validator->validateOrganisationGate(rule: $rule));
    }

    /**
     * Organisation gate: empty string is rejected.
     */
    public function testOrganisationGateEmptyStringRejected(): void
    {
        $rule   = ['organisation' => ''];
        $errors = $this->validator->validateOrganisationGate(rule: $rule);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-organisation', $errors[0]);
    }

    /**
     * Organisation gate: empty array is rejected.
     */
    public function testOrganisationGateEmptyArrayRejected(): void
    {
        $rule   = ['organisation' => []];
        $errors = $this->validator->validateOrganisationGate(rule: $rule);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-organisation', $errors[0]);
    }

    /**
     * Organisation gate: array with empty entry is rejected.
     */
    public function testOrganisationGateArrayWithEmptyEntryRejected(): void
    {
        $rule   = ['organisation' => ['org-1', '']];
        $errors = $this->validator->validateOrganisationGate(rule: $rule);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-organisation', $errors[0]);
    }

    /**
     * Organisation gate: non-string/non-array value is rejected.
     */
    public function testOrganisationGateNonStringNonArrayRejected(): void
    {
        $rule   = ['organisation' => 42];
        $errors = $this->validator->validateOrganisationGate(rule: $rule);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('notification-bad-organisation', $errors[0]);
    }

    /**
     * Unknown channel is flagged.
     */
    public function testUnknownChannelFlagged(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['channels' => ['fax']])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('fax', $errors[0]);
    }

    /**
     * Unknown recipient kind is flagged.
     */
    public function testUnknownRecipientKindFlagged(): void
    {
        $config = ['x-openregister-notifications' => [$this->validRule(overrides: ['recipients' => [['kind' => 'aliens']]])]];
        $errors = $this->validator->validate(configuration: $config);
        self::assertNotEmpty($errors);
    }
}
