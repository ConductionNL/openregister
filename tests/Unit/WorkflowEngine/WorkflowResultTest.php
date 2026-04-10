<?php

namespace Unit\WorkflowEngine;

use InvalidArgumentException;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class WorkflowResultTest extends TestCase
{
    public function testConstructorWithValidStatus(): void
    {
        $result = new WorkflowResult('approved');
        $this->assertSame('approved', $result->getStatus());
        $this->assertNull($result->getData());
        $this->assertSame([], $result->getErrors());
        $this->assertSame([], $result->getMetadata());
    }

    public function testConstructorWithAllParameters(): void
    {
        $data = ['key' => 'value'];
        $errors = [['message' => 'err']];
        $metadata = ['engine' => 'test'];

        $result = new WorkflowResult('modified', $data, $errors, $metadata);

        $this->assertSame('modified', $result->getStatus());
        $this->assertSame($data, $result->getData());
        $this->assertSame($errors, $result->getErrors());
        $this->assertSame($metadata, $result->getMetadata());
    }

    #[DataProvider('invalidStatusProvider')]
    public function testConstructorWithInvalidStatusThrows(string $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid workflow result status '$status'");
        new WorkflowResult($status);
    }

    public static function invalidStatusProvider(): array
    {
        return [
            'empty string' => [''],
            'random string' => ['foobar'],
            'uppercase' => ['APPROVED'],
            'partial match' => ['approve'],
        ];
    }

    public function testApprovedFactory(): void
    {
        $result = WorkflowResult::approved();
        $this->assertSame('approved', $result->getStatus());
        $this->assertNull($result->getData());
        $this->assertSame([], $result->getErrors());
        $this->assertSame([], $result->getMetadata());
    }

    public function testApprovedFactoryWithMetadata(): void
    {
        $metadata = ['engine' => 'n8n'];
        $result = WorkflowResult::approved($metadata);
        $this->assertSame($metadata, $result->getMetadata());
    }

    public function testRejectedFactory(): void
    {
        $errors = [['field' => 'name', 'message' => 'required']];
        $metadata = ['engine' => 'n8n'];
        $result = WorkflowResult::rejected($errors, $metadata);

        $this->assertSame('rejected', $result->getStatus());
        $this->assertNull($result->getData());
        $this->assertSame($errors, $result->getErrors());
        $this->assertSame($metadata, $result->getMetadata());
    }

    public function testRejectedFactoryWithoutMetadata(): void
    {
        $errors = [['message' => 'bad']];
        $result = WorkflowResult::rejected($errors);
        $this->assertSame([], $result->getMetadata());
    }

    public function testModifiedFactory(): void
    {
        $data = ['name' => 'updated'];
        $metadata = ['engine' => 'windmill'];
        $result = WorkflowResult::modified($data, $metadata);

        $this->assertSame('modified', $result->getStatus());
        $this->assertSame($data, $result->getData());
        $this->assertSame([], $result->getErrors());
        $this->assertSame($metadata, $result->getMetadata());
    }

    public function testModifiedFactoryWithoutMetadata(): void
    {
        $result = WorkflowResult::modified(['x' => 1]);
        $this->assertSame([], $result->getMetadata());
    }

    public function testErrorFactory(): void
    {
        $result = WorkflowResult::error('Something broke', ['engine' => 'test']);

        $this->assertSame('error', $result->getStatus());
        $this->assertNull($result->getData());
        $this->assertSame([['message' => 'Something broke']], $result->getErrors());
        $this->assertSame(['engine' => 'test'], $result->getMetadata());
    }

    public function testErrorFactoryWithoutMetadata(): void
    {
        $result = WorkflowResult::error('fail');
        $this->assertSame([], $result->getMetadata());
    }

    #[DataProvider('statusCheckProvider')]
    public function testStatusChecks(string $status, bool $isApproved, bool $isRejected, bool $isModified, bool $isError): void
    {
        $args = ['approved' => [], 'rejected' => [[['message' => 'err']]], 'modified' => [['k' => 'v']], 'error' => ['err msg']];
        $result = call_user_func([WorkflowResult::class, $status], ...$args[$status]);

        $this->assertSame($isApproved, $result->isApproved());
        $this->assertSame($isRejected, $result->isRejected());
        $this->assertSame($isModified, $result->isModified());
        $this->assertSame($isError, $result->isError());
    }

    public static function statusCheckProvider(): array
    {
        return [
            'approved' => ['approved', true, false, false, false],
            'rejected' => ['rejected', false, true, false, false],
            'modified' => ['modified', false, false, true, false],
            'error'    => ['error',    false, false, false, true],
        ];
    }

    public function testToArray(): void
    {
        $data = ['name' => 'test'];
        $errors = [['message' => 'oops']];
        $metadata = ['engine' => 'n8n'];
        $result = new WorkflowResult('modified', $data, $errors, $metadata);

        $expected = [
            'status'   => 'modified',
            'data'     => $data,
            'errors'   => $errors,
            'metadata' => $metadata,
        ];
        $this->assertSame($expected, $result->toArray());
    }

    public function testToArrayWithDefaults(): void
    {
        $result = WorkflowResult::approved();
        $array = $result->toArray();

        $this->assertSame('approved', $array['status']);
        $this->assertNull($array['data']);
        $this->assertSame([], $array['errors']);
        $this->assertSame([], $array['metadata']);
    }

    public function testJsonSerialize(): void
    {
        $result = WorkflowResult::error('fail', ['k' => 'v']);
        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testJsonSerializeIsJsonCompatible(): void
    {
        $result = WorkflowResult::modified(['a' => 1], ['b' => 2]);
        $json = json_encode($result);
        $decoded = json_decode($json, true);

        $this->assertSame('modified', $decoded['status']);
        $this->assertSame(['a' => 1], $decoded['data']);
    }

    public function testImplementsJsonSerializable(): void
    {
        $result = WorkflowResult::approved();
        $this->assertInstanceOf(\JsonSerializable::class, $result);
    }

    public function testConstants(): void
    {
        $this->assertSame('approved', WorkflowResult::STATUS_APPROVED);
        $this->assertSame('rejected', WorkflowResult::STATUS_REJECTED);
        $this->assertSame('modified', WorkflowResult::STATUS_MODIFIED);
        $this->assertSame('error', WorkflowResult::STATUS_ERROR);
    }
}
