<?php

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

class LifecycleAnnotationValidatorTest extends TestCase
{
    private LifecycleAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new LifecycleAnnotationValidator();
    }

    public function testNoAnnotationIsValid(): void
    {
        $this->assertSame([], $this->v->validate(['properties' => []]));
    }

    public function testMissingFieldIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $this->assertNotEmpty($errors);
        $this->assertSame('lifecycle-missing-key', $errors[0]['code']);
    }

    public function testFieldNotInPropertiesIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-field-missing', $codes);
    }

    public function testFieldNotStringIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'count', 'initial' => '0', 'transitions' => ['x' => ['from' => ['0'], 'to' => '1']]],
            'properties' => ['count' => ['type' => 'integer']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-field-not-string', $codes);
    }

    public function testInitialNotInEnumIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'unknown', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-initial-not-in-enum', $codes);
    }

    public function testFinalNotInEnumIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'draft',
                'final' => ['nonexistent'],
                'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open']],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-final-not-in-enum', $codes);
    }

    public function testTransitionFromNotInEnumIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['unknown'], 'to' => 'open']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-from-not-in-enum', $codes);
    }

    public function testTransitionToNotInEnumIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'unknown']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-to-not-in-enum', $codes);
    }

    public function testRequiresMustBeNonEmptyString(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => ['field' => 'lifecycle', 'initial' => 'draft', 'transitions' => ['x' => ['from' => ['draft'], 'to' => 'open', 'requires' => '']]],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','open']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-requires-malformed', $codes);
    }

    public function testValidAnnotationProducesNoErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'draft',
                'final' => ['closed'],
                'transitions' => [
                    'open'  => ['from' => ['draft'], 'to' => 'opened', 'requires' => 'app.guard'],
                    'close' => ['from' => ['opened'], 'to' => 'closed'],
                ],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['draft','opened','closed']]],
        ]);
        $this->assertSame([], $errors);
    }
}
