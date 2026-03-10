<?php

namespace Unit\Repair;

use OCA\OpenRegister\Repair\RegisterRiskLevelMetadata;
use OCA\OpenRegister\Service\RiskLevelService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RegisterRiskLevelMetadataTest extends TestCase
{
    private RiskLevelService&MockObject $riskLevelService;
    private IOutput&MockObject $output;
    private RegisterRiskLevelMetadata $repairStep;

    protected function setUp(): void
    {
        $this->riskLevelService = $this->createMock(RiskLevelService::class);
        $this->output = $this->createMock(IOutput::class);
        $this->repairStep = new RegisterRiskLevelMetadata($this->riskLevelService);
    }

    public function testImplementsIRepairStep(): void
    {
        $this->assertInstanceOf(IRepairStep::class, $this->repairStep);
    }

    public function testGetNameReturnsDescriptiveString(): void
    {
        $name = $this->repairStep->getName();
        $this->assertSame('Register OpenRegister risk level file metadata', $name);
    }

    public function testRunCallsInitMetadataKey(): void
    {
        $this->riskLevelService->expects($this->once())
            ->method('initMetadataKey');

        $this->repairStep->run($this->output);
    }

    public function testRunOutputsInfoMessage(): void
    {
        $this->output->expects($this->once())
            ->method('info')
            ->with('Registered openregister-risk-level metadata key');

        $this->repairStep->run($this->output);
    }

    public function testRunCallsInitBeforeOutput(): void
    {
        $callOrder = [];

        $this->riskLevelService->expects($this->once())
            ->method('initMetadataKey')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'init';
            });

        $this->output->expects($this->once())
            ->method('info')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'info';
            });

        $this->repairStep->run($this->output);

        $this->assertSame(['init', 'info'], $callOrder);
    }
}
