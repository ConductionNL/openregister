<?php

declare(strict_types=1);

namespace Unit\Exception;

use Exception;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    // --- NotAuthorizedException ---

    public function testNotAuthorizedExtendsException(): void
    {
        $e = new NotAuthorizedException();
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testNotAuthorizedDefaultMessage(): void
    {
        $e = new NotAuthorizedException();
        $this->assertSame('You are not authorized to perform this action', $e->getMessage());
        $this->assertSame(403, $e->getCode());
    }

    public function testNotAuthorizedCustomMessage(): void
    {
        $e = new NotAuthorizedException('Custom denied');
        $this->assertSame('Custom denied', $e->getMessage());
    }

    public function testNotAuthorizedCustomCode(): void
    {
        $e = new NotAuthorizedException('msg', 401);
        $this->assertSame(401, $e->getCode());
    }

    public function testNotAuthorizedPreviousException(): void
    {
        $prev = new \RuntimeException('root');
        $e = new NotAuthorizedException('msg', 403, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- LockedException ---

    public function testLockedExtendsException(): void
    {
        $e = new LockedException();
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testLockedDefaultMessage(): void
    {
        $e = new LockedException();
        $this->assertSame('Object is locked and cannot be modified', $e->getMessage());
        $this->assertSame(423, $e->getCode());
    }

    public function testLockedCustomMessage(): void
    {
        $e = new LockedException('Custom lock');
        $this->assertSame('Custom lock', $e->getMessage());
    }

    public function testLockedPreviousException(): void
    {
        $prev = new \RuntimeException('root');
        $e = new LockedException('msg', 423, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- RegisterNotFoundException ---

    public function testRegisterNotFoundExtendsException(): void
    {
        $e = new RegisterNotFoundException('my-register');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testRegisterNotFoundMessage(): void
    {
        $e = new RegisterNotFoundException('my-register');
        $this->assertSame("Register not found: 'my-register'", $e->getMessage());
        $this->assertSame(404, $e->getCode());
    }

    public function testRegisterNotFoundWithId(): void
    {
        $e = new RegisterNotFoundException('42');
        $this->assertStringContainsString('42', $e->getMessage());
    }

    public function testRegisterNotFoundCustomCode(): void
    {
        $e = new RegisterNotFoundException('slug', 500);
        $this->assertSame(500, $e->getCode());
    }

    public function testRegisterNotFoundPrevious(): void
    {
        $prev = new \RuntimeException('db error');
        $e = new RegisterNotFoundException('slug', 404, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- SchemaNotFoundException ---

    public function testSchemaNotFoundExtendsException(): void
    {
        $e = new SchemaNotFoundException('person');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testSchemaNotFoundMessage(): void
    {
        $e = new SchemaNotFoundException('person');
        $this->assertSame("Schema not found: 'person'", $e->getMessage());
        $this->assertSame(404, $e->getCode());
    }

    public function testSchemaNotFoundPrevious(): void
    {
        $prev = new \RuntimeException('db error');
        $e = new SchemaNotFoundException('slug', 404, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- CustomValidationException ---

    public function testCustomValidationExtendsException(): void
    {
        $e = new CustomValidationException('Validation failed', []);
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testCustomValidationGetErrors(): void
    {
        $errors = ['name' => 'required', 'email' => 'invalid format'];
        $e = new CustomValidationException('Validation failed', $errors);
        $this->assertSame($errors, $e->getErrors());
        $this->assertSame('Validation failed', $e->getMessage());
    }

    public function testCustomValidationEmptyErrors(): void
    {
        $e = new CustomValidationException('No errors', []);
        $this->assertSame([], $e->getErrors());
    }

    // --- ValidationException ---

    public function testValidationExtendsException(): void
    {
        $e = new ValidationException('Invalid');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testValidationDefaultErrorsNull(): void
    {
        $e = new ValidationException('Invalid');
        $this->assertNull($e->getErrors());
    }

    public function testValidationCustomCodeAndPrevious(): void
    {
        $prev = new \RuntimeException('cause');
        $e = new ValidationException('msg', 422, $prev);
        $this->assertSame(422, $e->getCode());
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- AuthenticationException ---

    public function testAuthenticationExtendsException(): void
    {
        $e = new AuthenticationException('Auth failed', []);
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testAuthenticationGetDetails(): void
    {
        $details = ['reason' => 'token expired', 'realm' => 'api'];
        $e = new AuthenticationException('Auth failed', $details);
        $this->assertSame($details, $e->getDetails());
        $this->assertSame('Auth failed', $e->getMessage());
    }

    public function testAuthenticationEmptyDetails(): void
    {
        $e = new AuthenticationException('Auth failed', []);
        $this->assertSame([], $e->getDetails());
    }

    // --- HookStoppedException ---

    public function testHookStoppedExtendsException(): void
    {
        $e = new HookStoppedException();
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testHookStoppedDefaultMessage(): void
    {
        $e = new HookStoppedException();
        $this->assertSame('Operation blocked by schema hook', $e->getMessage());
    }

    public function testHookStoppedDefaultErrorsEmpty(): void
    {
        $e = new HookStoppedException();
        $this->assertSame([], $e->getErrors());
    }

    public function testHookStoppedCustomMessageAndErrors(): void
    {
        $errors = [
            ['field' => 'status', 'message' => 'Invalid transition', 'code' => 'INVALID_TRANSITION'],
        ];
        $e = new HookStoppedException('Hook rejected', $errors);
        $this->assertSame('Hook rejected', $e->getMessage());
        $this->assertSame($errors, $e->getErrors());
    }

    public function testHookStoppedCustomCode(): void
    {
        $e = new HookStoppedException('msg', [], 422);
        $this->assertSame(422, $e->getCode());
    }

    public function testHookStoppedPreviousException(): void
    {
        $prev = new \RuntimeException('root');
        $e = new HookStoppedException('msg', [], 0, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- DatabaseConstraintException ---

    public function testDatabaseConstraintExtendsException(): void
    {
        $e = new DatabaseConstraintException('Constraint error');
        $this->assertInstanceOf(Exception::class, $e);
    }

    public function testDatabaseConstraintDefaultHttpStatus(): void
    {
        $e = new DatabaseConstraintException('error');
        $this->assertSame(409, $e->getHttpStatusCode());
    }

    public function testDatabaseConstraintCustomHttpStatus(): void
    {
        $e = new DatabaseConstraintException('error', 0, 422);
        $this->assertSame(422, $e->getHttpStatusCode());
    }

    public function testDatabaseConstraintPreviousException(): void
    {
        $prev = new \RuntimeException('db error');
        $e = new DatabaseConstraintException('error', 0, 409, $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    // --- DatabaseConstraintException::fromDatabaseException ---

    public static function constraintErrorProvider(): array
    {
        return [
            'schema slug duplicate' => [
                "Duplicate entry 'test' for key 'schemas_organisation_slug_unique'",
                'schema',
                'A schema with this slug already exists',
            ],
            'register slug duplicate' => [
                "Duplicate entry 'test' for key 'registers_organisation_slug_unique'",
                'register',
                'A register with this slug already exists',
            ],
            'generic unique duplicate' => [
                "Duplicate entry 'test' for key 'some_unique_constraint'",
                'item',
                'already exists',
            ],
            'duplicate without unique keyword' => [
                "Duplicate entry 'val' for key 'some_key'",
                'widget',
                'A widget with these details already exists',
            ],
            'foreign key constraint' => [
                'Cannot add or update: foreign key constraint fails',
                'object',
                "doesn't exist",
            ],
            'foreign key uppercase' => [
                'FOREIGN KEY constraint violation',
                'record',
                "doesn't exist",
            ],
            'not null constraint' => [
                'Column "name" cannot be null',
                'item',
                'Required information is missing',
            ],
            'not null uppercase' => [
                'NOT NULL constraint failed',
                'item',
                'Required information is missing',
            ],
            'check constraint' => [
                'check constraint violated',
                'item',
                "doesn't meet the required format",
            ],
            'check constraint uppercase' => [
                'CHECK constraint failed: age >= 0',
                'item',
                "doesn't meet the required format",
            ],
            'data too long' => [
                'Data too long for column name',
                'item',
                'too long',
            ],
            'too long lowercase' => [
                'value too long for type character varying(255)',
                'item',
                'too long',
            ],
            'sqlstate error' => [
                'SQLSTATE[HY000]: General error',
                'record',
                'There was a problem saving your record',
            ],
            'unknown error' => [
                'Something completely unexpected',
                'widget',
                'database error while saving your widget',
            ],
        ];
    }

    #[DataProvider('constraintErrorProvider')]
    public function testFromDatabaseException(string $dbMessage, string $entityType, string $expectedContains): void
    {
        $dbException = new Exception($dbMessage, 1062);
        $result = DatabaseConstraintException::fromDatabaseException($dbException, $entityType);

        $this->assertInstanceOf(DatabaseConstraintException::class, $result);
        $this->assertStringContainsString($expectedContains, $result->getMessage());
        $this->assertSame(409, $result->getHttpStatusCode());
        $this->assertSame($dbException, $result->getPrevious());
        $this->assertSame(1062, $result->getCode());
    }

    public function testFromDatabaseExceptionDefaultEntityType(): void
    {
        $dbException = new Exception('Something completely unexpected');
        $result = DatabaseConstraintException::fromDatabaseException($dbException);

        $this->assertStringContainsString('item', $result->getMessage());
    }
}
