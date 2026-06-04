<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\SystemSchemaNotificationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SystemSchemaNotificationRegistry.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5.1
 */
class SystemSchemaNotificationRegistryTest extends TestCase
{
    private SystemSchemaNotificationRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SystemSchemaNotificationRegistry();
    }

    public function testGetEntityTypesReturnsExpectedSlugs(): void
    {
        $types = $this->registry->getEntityTypes();
        $this->assertContains('register', $types);
        $this->assertContains('schema', $types);
        $this->assertContains('configuration', $types);
        $this->assertContains('source', $types);
        $this->assertContains('agent', $types);
        $this->assertContains('webhook', $types);
    }

    public function testGetRulesForSchemaReturnsUpdatedRule(): void
    {
        $rules = $this->registry->getRulesForEntityType('schema');
        $this->assertNotEmpty($rules);

        $triggers = array_column($rules, 'trigger');
        $this->assertContains('updated', $triggers);
    }

    public function testGetRulesForConfigurationContainsSyncFailureRule(): void
    {
        $rules = $this->registry->getRulesForEntityType('configuration');
        $this->assertNotEmpty($rules);

        $hasFailedCondition = false;
        foreach ($rules as $rule) {
            $condition = $rule['condition'] ?? null;
            if ($condition !== null
                && ($condition['field'] ?? '') === 'syncStatus'
                && ($condition['value'] ?? '') === 'failed'
            ) {
                $hasFailedCondition = true;
            }
        }

        $this->assertTrue($hasFailedCondition, 'Configuration rules must include a syncStatus=failed condition rule.');
    }

    public function testGetRulesForUnknownTypeReturnsEmptyArray(): void
    {
        $rules = $this->registry->getRulesForEntityType('nonexistent_type');
        $this->assertSame([], $rules);
    }

    public function testEachRuleHasRequiredKeys(): void
    {
        foreach ($this->registry->getEntityTypes() as $entityType) {
            $rules = $this->registry->getRulesForEntityType($entityType);
            foreach ($rules as $rule) {
                $this->assertArrayHasKey('trigger', $rule, "Rule for {$entityType} missing 'trigger'.");
                $this->assertArrayHasKey('recipients', $rule, "Rule for {$entityType} missing 'recipients'.");
                $this->assertArrayHasKey('subject', $rule, "Rule for {$entityType} missing 'subject'.");

                $subject = $rule['subject'];
                $this->assertArrayHasKey('nl', $subject, "Rule for {$entityType} subject missing 'nl'.");
                $this->assertArrayHasKey('en', $subject, "Rule for {$entityType} subject missing 'en'.");
            }
        }
    }

    public function testAllRulesHaveAdminRecipient(): void
    {
        foreach ($this->registry->getEntityTypes() as $entityType) {
            $rules = $this->registry->getRulesForEntityType($entityType);
            foreach ($rules as $rule) {
                $recipients = $rule['recipients'] ?? [];
                $hasAdmin   = false;
                foreach ($recipients as $recipient) {
                    if (in_array('admin', $recipient['groups'] ?? [], true) === true) {
                        $hasAdmin = true;
                    }
                }

                $this->assertTrue($hasAdmin, "Rule for {$entityType} must have 'admin' as a recipient group.");
            }
        }
    }

    public function testGetRulesForSourceReturnsRules(): void
    {
        $rules = $this->registry->getRulesForEntityType('source');
        $this->assertNotEmpty($rules);
    }

    public function testGetRulesForAgentReturnsRules(): void
    {
        $rules = $this->registry->getRulesForEntityType('agent');
        $this->assertNotEmpty($rules);
    }
}
