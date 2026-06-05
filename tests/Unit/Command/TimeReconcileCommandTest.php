<?php

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenRegister\Command\TimeReconcileCommand;
use OCA\OpenRegister\Db\TimeLink;
use OCA\OpenRegister\Db\TimeLinkMapper;
use OCA\OpenRegister\Service\TimeEntryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests for TimeReconcileCommand.
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-5
 */
class TimeReconcileCommandTest extends TestCase
{

    private TimeLinkMapper&MockObject $mapper;
    private TimeEntryService&MockObject $timeEntryService;
    private LoggerInterface&MockObject $logger;
    private TimeReconcileCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper           = $this->getMockBuilder(TimeLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'findDistinctObjectUuids', 'sumDurationByObjectUuid',
                'findByObjectUuid', 'updateTotalForObject',
            ])
            ->getMock();
        $this->timeEntryService = $this->createMock(TimeEntryService::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->command = new TimeReconcileCommand(
            timeLinkMapper: $this->mapper,
            timeEntryService: $this->timeEntryService,
            logger: $this->logger
        );
    }//end setUp()

    private function executeCommand(array $options = []): array
    {
        $input  = new ArrayInput($options, $this->command->getDefinition());
        $output = new BufferedOutput();
        $code   = $this->command->run($input, $output);
        return [$code, $output->fetch()];
    }//end run()

    public function testCommandName(): void
    {
        $this->assertSame('openregister:time:reconcile', $this->command->getName());
    }//end testCommandName()

    public function testSuccessWhenNoObjects(): void
    {
        $this->mapper->method('findDistinctObjectUuids')->willReturn([]);

        [$code, $out] = $this->executeCommand();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('0 corrected', $out);
    }//end testSuccessWhenNoObjects()

    public function testCorrectsDriftedTotal(): void
    {
        $uuid = 'obj-drifted';
        $this->mapper->method('findDistinctObjectUuids')->willReturn([$uuid]);
        $this->mapper->method('sumDurationByObjectUuid')->with($uuid)->willReturn(120);

        $staleLink = new TimeLink();
        $staleLink->setTotalMinutes(60);
        $this->mapper->method('findByObjectUuid')->with($uuid)->willReturn([$staleLink]);

        $this->mapper->expects($this->once())
            ->method('updateTotalForObject')
            ->with($uuid, 120);

        $this->logger->expects($this->once())->method('info');

        [$code, $out] = $this->executeCommand();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('1 corrected', $out);
    }//end testCorrectsDriftedTotal()

    public function testSkipsAlreadyCorrectObjects(): void
    {
        $uuid = 'obj-ok';
        $this->mapper->method('findDistinctObjectUuids')->willReturn([$uuid]);
        $this->mapper->method('sumDurationByObjectUuid')->with($uuid)->willReturn(90);

        $okLink = new TimeLink();
        $okLink->setTotalMinutes(90);
        $this->mapper->method('findByObjectUuid')->with($uuid)->willReturn([$okLink]);

        $this->mapper->expects($this->never())->method('updateTotalForObject');

        [$code, $out] = $this->executeCommand();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('0 corrected', $out);
    }//end testSkipsAlreadyCorrectObjects()

    public function testDryRunDoesNotWrite(): void
    {
        $uuid = 'obj-drift';
        $this->mapper->method('findDistinctObjectUuids')->willReturn([$uuid]);
        $this->mapper->method('sumDurationByObjectUuid')->with($uuid)->willReturn(200);

        $staleLink = new TimeLink();
        $staleLink->setTotalMinutes(100);
        $this->mapper->method('findByObjectUuid')->with($uuid)->willReturn([$staleLink]);

        $this->mapper->expects($this->never())->method('updateTotalForObject');

        [$code, $out] = $this->executeCommand(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Dry-run', $out);
        $this->assertStringContainsString('would correct', $out);
    }//end testDryRunDoesNotWrite()
}//end class
