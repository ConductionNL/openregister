<?php

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use GraphQL\Error\Error;
use OCA\OpenRegister\Service\GraphQL\GraphQLErrorFormatter;
use PHPUnit\Framework\TestCase;

class GraphQLErrorFormatterTest extends TestCase
{
    private GraphQLErrorFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GraphQLErrorFormatter();
    }

    public function testFormatsBasicError(): void
    {
        $error = new Error('Something went wrong');
        $formatted = $this->formatter->format($error);

        $this->assertArrayHasKey('message', $formatted);
        $this->assertSame('Something went wrong', $formatted['message']);
    }

    public function testFormatsNotAuthorizedError(): void
    {
        $previous = $this->createMock(\OCA\OpenRegister\Exception\NotAuthorizedException::class);
        $error = new Error('Access denied', null, null, [], null, $previous);
        $formatted = $this->formatter->format($error);

        $this->assertSame('FORBIDDEN', $formatted['extensions']['code']);
    }

    public function testFormatsErrorWithExtensionCode(): void
    {
        $error = new Error(
            'Rate limited',
            null, null, [], null, null,
            ['code' => 'RATE_LIMITED']
        );
        $formatted = $this->formatter->format($error);

        $this->assertSame('RATE_LIMITED', $formatted['extensions']['code']);
    }

    public function testFieldForbiddenCreatesCorrectError(): void
    {
        $error = GraphQLErrorFormatter::fieldForbidden('bsn', ['inwoner', 'bsn']);

        $this->assertStringContainsString('bsn', $error->getMessage());
        $this->assertSame('FIELD_FORBIDDEN', $error->getExtensions()['code']);
    }

    public function testNotFoundCreatesCorrectError(): void
    {
        $error = GraphQLErrorFormatter::notFound('Melding', 'melding-1');

        $this->assertStringContainsString('Melding', $error->getMessage());
        $this->assertStringContainsString('melding-1', $error->getMessage());
        $this->assertSame('NOT_FOUND', $error->getExtensions()['code']);
    }
}
