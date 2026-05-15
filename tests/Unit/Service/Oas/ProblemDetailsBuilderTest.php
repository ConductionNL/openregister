<?php

/**
 * Unit tests for ProblemDetailsBuilder (RFC 7807).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use PHPUnit\Framework\TestCase;

class ProblemDetailsBuilderTest extends TestCase
{

    private ProblemDetailsBuilder $builder;


    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ProblemDetailsBuilder();

    }//end setUp()


    public function testMinimalProblemHasTypeTitleStatus(): void
    {
        $problem = $this->builder->build(status: 400, title: 'Bad request');

        $this->assertSame('about:blank', $problem['type']);
        $this->assertSame('Bad request', $problem['title']);
        $this->assertSame(400, $problem['status']);
        $this->assertArrayNotHasKey('detail', $problem);
        $this->assertArrayNotHasKey('instance', $problem);

    }//end testMinimalProblemHasTypeTitleStatus()


    public function testProblemIncludesOptionalFields(): void
    {
        $problem = $this->builder->build(
            status: 422,
            title: 'Validation failed',
            detail: 'The "email" field MUST be a valid e-mail.',
            type: 'https://or.example.nl/probs/validation',
            instance: '/api/objects/zaken/abc-123'
        );

        $this->assertSame('https://or.example.nl/probs/validation', $problem['type']);
        $this->assertSame('The "email" field MUST be a valid e-mail.', $problem['detail']);
        $this->assertSame('/api/objects/zaken/abc-123', $problem['instance']);

    }//end testProblemIncludesOptionalFields()


    public function testExtensionsAreIncludedButCannotOverwriteStandardFields(): void
    {
        $problem = $this->builder->build(
            status: 400,
            title: 'Bad request',
            extensions: [
                'code'   => 'OR-INVALID-FILTER',
                'errors' => [['path' => 'foo', 'message' => 'bar']],
                // These MUST be ignored because the standard fields take priority.
                'type'   => 'malicious-override',
                'status' => 200,
            ]
        );

        $this->assertSame('OR-INVALID-FILTER', $problem['code']);
        $this->assertSame([['path' => 'foo', 'message' => 'bar']], $problem['errors']);
        $this->assertSame('about:blank', $problem['type']);
        $this->assertSame(400, $problem['status']);

    }//end testExtensionsAreIncludedButCannotOverwriteStandardFields()


    public function testValidationFailedHelperEmits422WithErrorsExtension(): void
    {
        $problem = $this->builder->validationFailed(
            errors: [
                ['path' => 'email', 'message' => 'must be a valid email'],
                ['path' => 'age', 'message' => 'must be >= 18'],
            ],
            detail: '2 fields failed validation'
        );

        $this->assertSame(422, $problem['status']);
        $this->assertSame('Validation failed', $problem['title']);
        $this->assertSame('2 fields failed validation', $problem['detail']);
        $this->assertCount(2, $problem['errors']);

    }//end testValidationFailedHelperEmits422WithErrorsExtension()


    public function testNotFoundHelperEmits404(): void
    {
        $problem = $this->builder->notFound(detail: 'No object with that UUID');
        $this->assertSame(404, $problem['status']);
        $this->assertSame('Not found', $problem['title']);
        $this->assertSame('No object with that UUID', $problem['detail']);

    }//end testNotFoundHelperEmits404()


    public function testConflictHelperEmits409(): void
    {
        $problem = $this->builder->conflict(detail: 'File is locked by another user');
        $this->assertSame(409, $problem['status']);
        $this->assertSame('Conflict', $problem['title']);
        $this->assertSame('File is locked by another user', $problem['detail']);

    }//end testConflictHelperEmits409()


    public function testContentTypeConstantIsRfc7807(): void
    {
        $this->assertSame('application/problem+json', ProblemDetailsBuilder::CONTENT_TYPE);

    }//end testContentTypeConstantIsRfc7807()


}//end class
