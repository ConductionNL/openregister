<?php

/**
 * SystemSchemaRules Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Notification\SystemSchemaRules;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SystemSchemaRules.
 */
class SystemSchemaRulesTest extends TestCase
{

    private SystemSchemaRules $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SystemSchemaRules();
    }//end setUp()

    /**
     * getRules() returns a non-empty array for every declared system slug.
     */
    public function testGetRulesReturnsDeclaredRulesForKnownSlugs(): void
    {
        $slugs = [
            SystemSchemaRules::SLUG_REGISTER,
            SystemSchemaRules::SLUG_SCHEMA,
            SystemSchemaRules::SLUG_CONFIGURATION,
            SystemSchemaRules::SLUG_SOURCE,
            SystemSchemaRules::SLUG_AGENT,
            SystemSchemaRules::SLUG_WEBHOOK,
        ];

        foreach ($slugs as $slug) {
            $rules = $this->registry->getRules(slug: $slug);
            $this->assertIsArray($rules, "Rules must be an array for slug '{$slug}'.");
            $this->assertNotEmpty($rules, "Rules must not be empty for slug '{$slug}'.");
        }
    }//end testGetRulesReturnsDeclaredRulesForKnownSlugs()

    /**
     * getRules() returns null for an unknown slug.
     */
    public function testGetRulesReturnsNullForUnknownSlug(): void
    {
        $this->assertNull($this->registry->getRules(slug: 'not_a_real_slug'));
    }//end testGetRulesReturnsNullForUnknownSlug()

    /**
     * getSlugs() returns all known system schema slugs.
     */
    public function testGetSlugsReturnsAllSystemSlugs(): void
    {
        $slugs = $this->registry->getSlugs();
        $this->assertContains(SystemSchemaRules::SLUG_SOURCE, $slugs);
        $this->assertContains(SystemSchemaRules::SLUG_AGENT, $slugs);
        $this->assertContains(SystemSchemaRules::SLUG_CONFIGURATION, $slugs);
        $this->assertContains(SystemSchemaRules::SLUG_SCHEMA, $slugs);
    }//end testGetSlugsReturnsAllSystemSlugs()

    /**
     * buildSchema() returns a Schema with the rules embedded in configuration.
     */
    public function testBuildSchemaReturnsSyntheticSchemaWithRules(): void
    {
        $schema = $this->registry->buildSchema(slug: SystemSchemaRules::SLUG_SOURCE);
        $this->assertInstanceOf(Schema::class, $schema);

        $config = $schema->getConfiguration();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('x-openregister-notifications', $config);
        $this->assertIsArray($config['x-openregister-notifications']);
        $this->assertNotEmpty($config['x-openregister-notifications']);
    }//end testBuildSchemaReturnsSyntheticSchemaWithRules()

    /**
     * buildSchema() returns null for an unknown slug.
     */
    public function testBuildSchemaReturnsNullForUnknownSlug(): void
    {
        $this->assertNull($this->registry->buildSchema(slug: 'unknown_slug'));
    }//end testBuildSchemaReturnsNullForUnknownSlug()

    /**
     * source-unhealthy rule has an updated trigger with a condition on the status field.
     */
    public function testSourceUnhealthyRuleHasFieldChangeCondition(): void
    {
        $rules = $this->registry->getRules(slug: SystemSchemaRules::SLUG_SOURCE);
        $this->assertIsArray($rules);
        $this->assertArrayHasKey('source-unhealthy', $rules);

        $rule = $rules['source-unhealthy'];
        $this->assertSame('updated', $rule['trigger']['type'] ?? null);
        $this->assertSame('status', $rule['trigger']['condition']['field'] ?? null);
        $this->assertSame('equals', $rule['trigger']['condition']['operator'] ?? null);
    }//end testSourceUnhealthyRuleHasFieldChangeCondition()

    /**
     * Rules declare admin-group recipients so operational events reach admins.
     */
    public function testRulesDeclareAdminGroupRecipients(): void
    {
        $rules = $this->registry->getRules(slug: SystemSchemaRules::SLUG_CONFIGURATION);
        $this->assertIsArray($rules);

        foreach ($rules as $rule) {
            $hasAdminGroup = false;
            foreach (($rule['recipients'] ?? []) as $recipient) {
                if (($recipient['kind'] ?? '') === 'groups'
                    && in_array('admin', (array) ($recipient['groups'] ?? []), true) === true
                ) {
                    $hasAdminGroup = true;
                    break;
                }
            }

            $this->assertTrue($hasAdminGroup, 'Each configuration rule must target the admin group.');
        }
    }//end testRulesDeclareAdminGroupRecipients()

    /**
     * Rules declare bilingual (nl/en) subjects.
     */
    public function testRulesDeclareBilingualSubjects(): void
    {
        foreach ($this->registry->getSlugs() as $slug) {
            $rules = $this->registry->getRules(slug: $slug);
            foreach ((array) $rules as $ruleKey => $rule) {
                $subject = ($rule['subject'] ?? null);
                $this->assertIsArray($subject, "Rule '{$ruleKey}' in '{$slug}' must have a per-locale subject map.");
                $this->assertArrayHasKey('nl', $subject, "Rule '{$ruleKey}' must have an 'nl' subject.");
                $this->assertArrayHasKey('en', $subject, "Rule '{$ruleKey}' must have an 'en' subject.");
            }
        }
    }//end testRulesDeclareBilingualSubjects()

}//end class
