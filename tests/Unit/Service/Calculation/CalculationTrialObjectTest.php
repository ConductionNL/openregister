<?php

/**
 * Behaviour tests for CalculationTrialService::tryObject() and date results.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationTrialService;
use OCA\OpenRegister\Service\Calculation\TrialObject;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real (final) annotation validator, so it names no covered class.
 */
class CalculationTrialObjectTest extends TestCase
{
    private function schemaWith(array $properties): Schema
    {
        $schema = $this->createPartialMock(Schema::class, ['getProperties']);
        $schema->method('getProperties')->willReturn($properties);
        return $schema;
    }//end schemaWith()

    public function testADeclarationOverAnUnknownPropertyIsRefusedBeforeThePayloadIsBuilt(): void
    {
        $builder = $this->createMock(CalculationPayloadBuilder::class);
        $builder->expects($this->never())->method('build');
        $evaluator = $this->createMock(CalculationEvaluatorInterface::class);
        $evaluator->expects($this->never())->method('evaluate');

        $result = (new CalculationTrialService(new CalculationAnnotationValidator(), $evaluator, $builder))->tryObject(
            ['type' => 'integer', 'expression' => ['frobnicate' => [['prop' => 'aantal']]]],
            new TrialObject(new ObjectEntity(), $this->schemaWith(['aantal' => ['type' => 'integer']]))
        );

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }//end testADeclarationOverAnUnknownPropertyIsRefusedBeforeThePayloadIsBuilt()

    public function testADateResultOnAnObjectIsAnsweredAsAtomTextFromItsPayload(): void
    {
        $object  = new ObjectEntity();
        $schema  = $this->schemaWith(['start' => ['type' => 'string']]);
        $builder = $this->createMock(CalculationPayloadBuilder::class);
        $builder->expects($this->once())->method('build')->with($object, $schema)->willReturn(['start' => '2027-01-01']);
        $evaluator = $this->createMock(CalculationEvaluatorInterface::class);
        $evaluator->method('evaluate')
            ->with(['start' => '2027-01-01'], ['prop' => 'start'])
            ->willReturn(new DateTimeImmutable('2027-01-02T03:04:05', new DateTimeZone('UTC')));
        $evaluator->method('referencedProperties')->willReturn(['start']);

        $result = (new CalculationTrialService(new CalculationAnnotationValidator(), $evaluator, $builder))->tryObject(
            ['type' => 'date', 'expression' => ['prop' => 'start']],
            new TrialObject($object, $schema)
        );

        $this->assertSame(['ok' => true, 'value' => '2027-01-02T03:04:05+00:00', 'dependencies' => ['start']], $result);
    }//end testADateResultOnAnObjectIsAnsweredAsAtomTextFromItsPayload()
}//end class
