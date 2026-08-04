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

    public function testPropertyAliasIsAcceptedAsField(): void
    {
        // `property` (procest migration shape) is accepted as an alias for `field`.
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'property' => 'lifecycle',
                'initial' => 'concept',
                'transitions' => ['indienen' => ['from' => 'concept', 'to' => 'in_parafering']],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering']]],
        ]);
        $this->assertSame([], $errors);
    }

    public function testStringFromIsAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'concept',
                'transitions' => ['indienen' => ['from' => 'concept', 'to' => 'in_parafering']],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering']]],
        ]);
        $this->assertSame([], $errors);
    }

    public function testTransitionAuthorizationGroupListIsAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'concept',
                'transitions' => [
                    'completeren' => [
                        'from' => 'in_parafering',
                        'to' => 'geparafeerd',
                        'authorization' => ['vergunningverleners', ['role' => 'handler']],
                    ],
                ],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
        ]);
        $this->assertSame([], $errors);
    }

    public function testEmptyTransitionAuthorizationIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'concept',
                'transitions' => [
                    'completeren' => ['from' => 'in_parafering', 'to' => 'geparafeerd', 'authorization' => []],
                ],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-authorization-malformed', $codes);
    }

    public function testMalformedTransitionAuthorizationEntryIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'lifecycle',
                'initial' => 'concept',
                'transitions' => [
                    'completeren' => ['from' => 'in_parafering', 'to' => 'geparafeerd', 'authorization' => [123]],
                ],
            ],
            'properties' => ['lifecycle' => ['type' => 'string', 'enum' => ['concept','in_parafering','geparafeerd']]],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-authorization-entry-malformed', $codes);
    }

    // --- Graph mode (fk-graph-lifecycle-transitions) ---------------------

    /**
     * A well-formed graph block with object-form `initial` passes validation
     * even though the lifecycle field is a `$ref` with no enum.
     */
    public function testValidGraphAnnotationPasses(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field'   => 'status',
                'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
                'graph'   => [
                    'schema'       => 'statustype',
                    'parentField'  => 'caseType',
                    'parentFrom'   => 'caseType',
                    'orderField'   => 'order',
                    'finalField'   => 'isFinal',
                    'allowedMoves' => 'forward',
                ],
            ],
            'properties' => ['status' => ['type' => 'string', 'format' => 'uuid']],
        ]);
        $this->assertSame([], $errors);
    }

    /**
     * Graph mode relaxes the enum requirement: a $ref field without an enum
     * is accepted (would be rejected in static mode).
     */
    public function testGraphFieldWithoutEnumIsAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'status',
                'graph' => [
                    'schema'       => 'statustype',
                    'parentField'  => 'caseType',
                    'parentFrom'   => 'caseType',
                    'orderField'   => 'order',
                    'finalField'   => 'isFinal',
                    'allowedMoves' => 'any',
                ],
            ],
            'properties' => ['status' => ['type' => 'object']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testInvalidAllowedMovesIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'status',
                'graph' => [
                    'schema'       => 'statustype',
                    'parentField'  => 'caseType',
                    'parentFrom'   => 'caseType',
                    'orderField'   => 'order',
                    'finalField'   => 'isFinal',
                    'allowedMoves' => 'sideways',
                ],
            ],
            'properties' => ['status' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-graph-allowedmoves-invalid', $codes);
    }

    public function testMissingGraphKeyIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field' => 'status',
                'graph' => [
                    'schema'      => 'statustype',
                    // parentField missing.
                    'parentFrom'  => 'caseType',
                    'orderField'  => 'order',
                    'finalField'  => 'isFinal',
                    'allowedMoves' => 'forward',
                ],
            ],
            'properties' => ['status' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-graph-missing-key', $codes);
    }

    public function testMalformedObjectInitialIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'field'   => 'status',
                'initial' => ['from' => 'caseType'],
                // `field` key missing from the object-form initial.
                'graph'   => [
                    'schema'       => 'statustype',
                    'parentField'  => 'caseType',
                    'parentFrom'   => 'caseType',
                    'orderField'   => 'order',
                    'finalField'   => 'isFinal',
                    'allowedMoves' => 'forward',
                ],
            ],
            'properties' => ['status' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-initial-malformed', $codes);
    }

    public function testGraphMissingFieldIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-lifecycle' => [
                'graph' => [
                    'schema'       => 'statustype',
                    'parentField'  => 'caseType',
                    'parentFrom'   => 'caseType',
                    'orderField'   => 'order',
                    'finalField'   => 'isFinal',
                    'allowedMoves' => 'forward',
                ],
            ],
            'properties' => ['status' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('lifecycle-missing-key', $codes);
    }
}
