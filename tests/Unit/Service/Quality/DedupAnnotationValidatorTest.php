<?php

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Service\Quality\DedupAnnotationValidator;
use PHPUnit\Framework\TestCase;

class DedupAnnotationValidatorTest extends TestCase
{
    private DedupAnnotationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DedupAnnotationValidator();
    }

    public function testAbsentAnnotationIsValid(): void
    {
        $this->assertSame([], $this->validator->validate(['properties' => []]));
    }

    public function testValidAnnotation(): void
    {
        $shape = [
            'x-openregister-dedup' => [
                'blockingKeys' => ['postalCode'],
                'matchRules'   => [
                    ['field' => 'email', 'method' => 'exact', 'weight' => 0.5],
                    ['field' => 'name', 'method' => 'levenshtein', 'weight' => 0.5],
                ],
                'threshold'    => 0.85,
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }

    public function testNestedDotPathFieldIsValid(): void
    {
        $shape = [
            'x-openregister-dedup' => [
                'blockingKeys' => ['goldenRecord.postalCode'],
                'matchRules'   => [
                    ['field' => 'goldenRecord.email', 'method' => 'exact', 'weight' => 0.5],
                    ['field' => 'goldenRecord.name', 'method' => 'normalized', 'weight' => 0.5],
                ],
                'threshold'    => 0.85,
            ],
        ];
        $this->assertSame([], $this->validator->validate($shape));
    }

    public function testEmptyMatchRules(): void
    {
        $errors = $this->validator->validate(['x-openregister-dedup' => ['matchRules' => []]]);
        $this->assertSame('dedup.no-rules', $errors[0]['code']);
    }

    public function testUnknownMethod(): void
    {
        $shape  = ['x-openregister-dedup' => ['matchRules' => [['field' => 'x', 'method' => 'jaro']]]];
        $errors = $this->validator->validate($shape);
        $this->assertSame('dedup.unknown-method', $errors[0]['code']);
    }

    public function testMissingField(): void
    {
        $shape  = ['x-openregister-dedup' => ['matchRules' => [['method' => 'exact']]]];
        $errors = $this->validator->validate($shape);
        $this->assertSame('dedup.missing-field', $errors[0]['code']);
    }

    public function testBadThresholdAndBlockingKeys(): void
    {
        $shape = [
            'x-openregister-dedup' => [
                'matchRules'   => [['field' => 'x', 'method' => 'exact']],
                'threshold'    => 'high',
                'blockingKeys' => 'postalCode',
            ],
        ];
        $errors = $this->validator->validate($shape);
        $codes  = array_column($errors, 'code');
        $this->assertContains('dedup.bad-threshold', $codes);
        $this->assertContains('dedup.bad-blocking-keys', $codes);
    }
}
