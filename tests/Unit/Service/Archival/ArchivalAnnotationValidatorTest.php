<?php

declare(strict_types=1);

/**
 * ArchivalAnnotationValidator Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2.3
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ArchivalAnnotationValidator.
 */
class ArchivalAnnotationValidatorTest extends TestCase
{

    private ArchivalAnnotationValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ArchivalAnnotationValidator();
    }

    /**
     * Happy path: well-formed annotation with rules passes validation.
     */
    public function testWellFormedAnnotationPasses(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        [
                            'condition' => 'statusCode < 400',
                            'retention' => 'PT1H',
                            'reason'    => 'successful integrations',
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $this->assertEmpty($errors);
    }

    /**
     * No annotation → empty error list (nothing to validate).
     */
    public function testAbsentAnnotationProducesNoErrors(): void
    {
        $errors = $this->validator->validate(schemaConfiguration: []);
        $this->assertEmpty($errors);
    }

    /**
     * Missing retention.default → archival-retention-default-missing.
     */
    public function testMissingDefaultProducesError(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => [],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $codes = array_column($errors, 'code');
        $this->assertContains('archival-retention-default-missing', $codes);
    }

    /**
     * Non-ISO-8601 retention.default → archival-retention-default-malformed.
     */
    public function testMalformedDefaultProducesError(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => ['default' => '30 days'],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $codes = array_column($errors, 'code');
        $this->assertContains('archival-retention-default-malformed', $codes);
    }

    /**
     * Rule with non-string condition → archival-rule-condition-not-string.
     */
    public function testNonStringConditionProducesError(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 42, 'retention' => 'P7D'],
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $codes = array_column($errors, 'code');
        $this->assertContains('archival-rule-condition-not-string', $codes);
    }

    /**
     * Rule with malformed retention → archival-rule-retention-malformed.
     */
    public function testMalformedRuleRetentionProducesError(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => [
                    'default' => 'P30D',
                    'rules'   => [
                        ['condition' => 'statusCode < 400', 'retention' => '1h'],
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $codes = array_column($errors, 'code');
        $this->assertContains('archival-rule-retention-malformed', $codes);
    }

    /**
     * Unknown key under retention → archival-retention-unknown-key mentioning the key.
     */
    public function testUnknownRetentionKeyProducesError(): void
    {
        $config = [
            'x-openregister-archival' => [
                'retention' => ['default' => 'P30D', 'strategy' => 'oldest-first'],
            ],
        ];

        $errors = $this->validator->validate(schemaConfiguration: $config);

        $codes    = array_column($errors, 'code');
        $messages = array_column($errors, 'message');
        $this->assertContains('archival-retention-unknown-key', $codes);

        $mentionsKey = false;
        foreach ($messages as $msg) {
            if (strpos($msg, 'strategy') !== false) {
                $mentionsKey = true;
                break;
            }
        }

        $this->assertTrue($mentionsKey, 'Error message should mention the unknown key name');
    }
}//end class
