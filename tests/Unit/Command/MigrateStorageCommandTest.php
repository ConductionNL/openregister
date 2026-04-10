<?php

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenRegister\Command\MigrateStorageCommand;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\MigrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class MigrateStorageCommandTest extends TestCase
{
    private MigrateStorageCommand $command;
    private MigrationService&MockObject $migrationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationService = $this->createMock(MigrationService::class);
        $this->command = new MigrateStorageCommand($this->migrationService);
    }

    private function execute(array $args): array
    {
        $input = new ArrayInput($args, $this->command->getDefinition());
        $output = new BufferedOutput();
        $code = $this->command->run($input, $output);
        return [$code, $output->fetch()];
    }

    private function makeResolved(): array
    {
        $register = new class extends Register {
            public function getName(): string
            {
                return 'Test Register';
            }
        };
        $schema = new class extends Schema {
            public function getName(): string
            {
                return 'Test Schema';
            }
        };
        return ['register' => $register, 'schema' => $schema];
    }

    public function testCommandName(): void
    {
        $this->assertSame('openregister:migrate-storage', $this->command->getName());
    }

    public function testInvalidDirectionReturnsFailure(): void
    {
        [$code] = $this->execute([
            'direction' => 'invalid',
            'register' => '1',
            'schema' => '1',
        ]);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testResolveExceptionReturnsFailure(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willThrowException(new \Exception('Not found'));

        [$code] = $this->execute([
            'direction' => 'to-magic',
            'register' => 'missing',
            'schema' => 'missing',
        ]);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testStatusOnlyReturnsSuccess(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($this->makeResolved());
        $this->migrationService->method('getStorageStatus')
            ->willReturn([
                'blobStorage' => ['count' => 10],
                'magicTable' => ['exists' => true, 'count' => 5],
            ]);

        [$code] = $this->execute([
            'direction' => 'to-magic',
            'register' => '1',
            'schema' => '1',
            '--status' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testToMagicMigrationCallsService(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($this->makeResolved());
        $this->migrationService->method('getStorageStatus')
            ->willReturn([
                'blobStorage' => ['count' => 5],
                'magicTable' => ['exists' => false, 'count' => 0],
            ]);

        $this->migrationService->expects($this->once())
            ->method('migrateToMagicTable')
            ->willReturn([
                'total' => 5,
                'migrated' => 5,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ]);

        [$code] = $this->execute([
            'direction' => 'to-magic',
            'register' => '1',
            'schema' => '1',
        ]);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testToBlobMigrationCallsService(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($this->makeResolved());
        $this->migrationService->method('getStorageStatus')
            ->willReturn([
                'blobStorage' => ['count' => 0],
                'magicTable' => ['exists' => true, 'count' => 5],
            ]);

        $this->migrationService->expects($this->once())
            ->method('migrateToBlobStorage')
            ->willReturn([
                'total' => 5,
                'migrated' => 5,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
            ]);

        [$code] = $this->execute([
            'direction' => 'to-blob',
            'register' => '1',
            'schema' => '1',
        ]);
        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testMigrationWithFailuresReturnsFailure(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($this->makeResolved());
        $this->migrationService->method('getStorageStatus')
            ->willReturn([
                'blobStorage' => ['count' => 5],
                'magicTable' => ['exists' => false, 'count' => 0],
            ]);

        $this->migrationService->method('migrateToMagicTable')
            ->willReturn([
                'total' => 5,
                'migrated' => 3,
                'skipped' => 0,
                'failed' => 2,
                'errors' => [
                    ['uuid' => 'abc-1', 'message' => 'type mismatch'],
                    ['uuid' => 'abc-2', 'message' => 'constraint'],
                ],
            ]);

        [$code] = $this->execute([
            'direction' => 'to-magic',
            'register' => '1',
            'schema' => '1',
        ]);
        $this->assertSame(Command::FAILURE, $code);
    }

    public function testMigrationExceptionReturnsFailure(): void
    {
        $this->migrationService->method('resolveRegisterAndSchema')
            ->willReturn($this->makeResolved());
        $this->migrationService->method('getStorageStatus')
            ->willReturn([
                'blobStorage' => ['count' => 5],
                'magicTable' => ['exists' => false, 'count' => 0],
            ]);

        $this->migrationService->method('migrateToMagicTable')
            ->willThrowException(new \Exception('Migration crashed'));

        [$code] = $this->execute([
            'direction' => 'to-magic',
            'register' => '1',
            'schema' => '1',
        ]);
        $this->assertSame(Command::FAILURE, $code);
    }
}
