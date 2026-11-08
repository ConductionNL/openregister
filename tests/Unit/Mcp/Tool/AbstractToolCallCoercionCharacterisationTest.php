<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\Tool;

use BadMethodCallException;
use OCA\OpenRegister\Mcp\Tool\AbstractTool;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins how AbstractTool::__call matches and coerces LLM arguments.
 *
 * @covers \OCA\OpenRegister\Mcp\Tool\AbstractTool
 */
class AbstractToolCallCoercionCharacterisationTest extends TestCase
{

    private AbstractTool $tool;

    protected function setUp(): void
    {
        $this->tool = new class($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)) extends AbstractTool {
            public function getName(): string
            {
                return 'probe';
            }

            public function getDescription(): string
            {
                return 'probe';
            }

            public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
            {
                return [];
            }

            public function echoArgs($untyped = 'u', int|string $union = 'x', ?int $nullable = null, float $ratio = 1.5, bool $flag = true, array $list = ['d'], ?string $text = null): array
            {
                return [$untyped, $union, $nullable, $ratio, $flag, $list, $text];
            }

            public function required(int $count, string $label): array
            {
                return [$count, $label];
            }

            public function plain(): string
            {
                return 'plain';
            }

            public function nothing(): ?array
            {
                return null;
            }
        };
    }

    /** @return array<string, array{0: array, 1: array}> */
    public static function echoProvider(): array
    {
        return [
            'no arguments'             => [[], ['u', 'x', null, 1.5, true, ['d'], null]],
            'positional casts'         => [[7, 9, '12', '2.25', 'false', 'scalar', 42], [7, 9, 12, 2.25, false, [], '42']],
            'string null uses default' => [['null', 'null', 'null', 'null', 'null', 'null', 'null'], ['u', 'x', null, 1.5, true, ['d'], null]],
            'bool spellings'           => [[null, null, null, null, 'yes'], ['u', 'x', null, 1.5, true, ['d'], null]],
            'bool garbage is false'    => [[null, null, null, null, 'maybe'], ['u', 'x', null, 1.5, false, ['d'], null]],
            'bool zero'                => [[null, null, null, null, 0], ['u', 'x', null, 1.5, false, ['d'], null]],
            'named arguments'          => [['ratio' => '3', 'text' => 5, 'list' => ['a']], ['u', 'x', null, 3.0, true, ['a'], '5']],
            'named null keeps default' => [['flag' => null, 'nullable' => 'null'], ['u', 'x', null, 1.5, true, ['d'], null]],
            'mixed keys'               => [[0 => 'p0', 'union' => 'named', 2 => '4'], ['p0', 'named', 4, 1.5, true, ['d'], null]],
            'gap in positions'         => [[1 => 'second'], ['u', 'second', null, 1.5, true, ['d'], null]],
            // Not cast here; the non-strict call site coerces 12.0 to int (strict_types would refuse it).
            'union is not cast'        => [[null, 12.0], ['u', 12, null, 1.5, true, ['d'], null]],
            'untyped untouched'        => [[['raw']], [['raw'], 'x', null, 1.5, true, ['d'], null]],
        ];
    }

    #[DataProvider('echoProvider')]
    public function testEchoArgs(array $arguments, array $expected): void
    {
        $this->assertSame(json_encode($expected), $this->tool->__call('echo_args', $arguments));
    }

    public function testCamelCaseNameIsAlsoAccepted(): void
    {
        $this->assertSame('[3,"lbl"]', $this->tool->__call('required', ['3', 'lbl']));
        $this->assertSame('[3,"lbl"]', $this->tool->__call('Required', [3, 'lbl']));
    }

    public function testMissingRequiredArgumentPassesNull(): void
    {
        $this->expectException(\TypeError::class);
        $this->tool->__call('required', []);
    }

    public function testNonArrayResultsReturnAsIs(): void
    {
        $this->assertSame('plain', $this->tool->__call('plain', []));
        $this->assertNull($this->tool->__call('nothing', []));
    }

    public function testUnknownMethodThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method no_such_thing (or noSuchThing) does not exist');
        $this->tool->__call('no_such_thing', []);
    }
}
