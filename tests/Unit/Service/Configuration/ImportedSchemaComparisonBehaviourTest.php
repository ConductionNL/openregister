<?php

/**
 * Behaviour tests for ImportedSchemaComparison::differs(), called directly.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stored schema is a real entity whose configuration getter alone is stubbed,
 * so the configuration validator (not under test) does not run.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ImportedSchemaComparisonBehaviourTest extends TestCase
{
    private function stored(?array $configuration = null): Schema
    {
        $schema = $this->createPartialMock(Schema::class, ['getConfiguration']);
        $schema->method('getConfiguration')->willReturn($configuration);
        $schema->setProperties(['name' => ['type' => 'string', 'title' => 'Name'], 'tags' => ['type' => 'array']]);
        $schema->setRequired(['name', 'tags']);
        $schema->setAuthorization(['read' => ['public'], 'update' => ['admin', 'editors']]);
        return $schema;
    }//end stored()

    private function same(): array
    {
        return [
            'properties'    => ['tags' => ['type' => 'array'], 'name' => ['title' => 'Name', 'type' => 'string']],
            'required'      => ['tags', 'name'],
            'authorization' => ['update' => ['editors', 'admin'], 'read' => ['public']],
        ];
    }//end same()

    public function testKeyOrderAndListOrderDoNotCountAsADifference(): void
    {
        $this->assertFalse((new ImportedSchemaComparison())->differs($this->same(), $this->stored()));
    }//end testKeyOrderAndListOrderDoNotCountAsADifference()

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function changedFieldProvider(): array
    {
        return [
            'a property type'     => ['properties', ['name' => ['type' => 'integer', 'title' => 'Name'], 'tags' => ['type' => 'array']]],
            'a required field'    => ['required', ['name']],
            'authorization gone'  => ['authorization', null],
            'a scalar for a list' => ['required', 'name'],
        ];
    }//end changedFieldProvider()

    #[DataProvider('changedFieldProvider')]
    public function testAChangedCoreFieldDiffers(string $field, mixed $value): void
    {
        $data         = $this->same();
        $data[$field] = $value;

        $this->assertTrue((new ImportedSchemaComparison())->differs($data, $this->stored()));
    }//end testAChangedCoreFieldDiffers()

    public function testAnEmptyListAndAMissingValueAreTheSame(): void
    {
        $schema = $this->createPartialMock(Schema::class, ['getConfiguration']);
        $schema->method('getConfiguration')->willReturn(null);
        $schema->setProperties([]);
        $schema->setRequired([]);
        $schema->setAuthorization([]);

        $this->assertFalse((new ImportedSchemaComparison())->differs(['required' => []], $schema));
    }//end testAnEmptyListAndAMissingValueAreTheSame()

    public function testAComparedConfigurationKeyDiffersWhetherTopLevelOrNested(): void
    {
        $comparison = new ImportedSchemaComparison();
        $stored     = $this->stored(['partyKinds' => ['person'], 'x-openregister-lifecycle' => ['states' => ['open']]]);

        $this->assertFalse($comparison->differs($this->same() + ['partyKinds' => ['person']], $stored));
        $this->assertTrue($comparison->differs($this->same() + ['partyKinds' => ['person', 'company']], $stored));
        $this->assertTrue(
            $comparison->differs($this->same() + ['configuration' => ['x-openregister-lifecycle' => ['states' => ['closed']]]], $stored)
        );
        $this->assertTrue($comparison->differs($this->same() + ['linkRoles' => ['owner']], $stored));
    }//end testAComparedConfigurationKeyDiffersWhetherTopLevelOrNested()

    public function testUncomparedAndUnknownKeysAndANonArrayConfigurationAreIgnored(): void
    {
        $data = $this->same() + [
            'title'                  => 'Renamed',
            'x-openregister-unknown' => ['anything'],
            0                        => 'positional',
            'configuration'          => 'not an array',
        ];

        $this->assertFalse((new ImportedSchemaComparison())->differs($data, $this->stored(['title' => 'Old'])));
    }//end testUncomparedAndUnknownKeysAndANonArrayConfigurationAreIgnored()
}//end class
