<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Tool;

use BadMethodCallException;
use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Tool\AbstractTool;
use OCA\OpenRegister\Mcp\Tool\AgentTool;
use OCA\OpenRegister\Mcp\Tool\ApplicationTool;
use OCA\OpenRegister\Mcp\Tool\ObjectsTool;
use OCA\OpenRegister\Mcp\Tool\RegisterTool;
use OCA\OpenRegister\Mcp\Tool\SchemaTool;
use OCA\OpenRegister\Service\RegisterServiceInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins who executeFunction() lets through and who it refuses, per tool:
 * the schema, register and objects tools refuse a call without a user
 * context before touching their backend; the agent and application tools
 * have no such gate and leave it to their mapper.
 *
 * @covers \OCA\OpenRegister\Mcp\Tool\AbstractTool
 * @covers \OCA\OpenRegister\Mcp\Tool\SchemaTool
 * @covers \OCA\OpenRegister\Mcp\Tool\RegisterTool
 * @covers \OCA\OpenRegister\Mcp\Tool\ObjectsTool
 * @covers \OCA\OpenRegister\Mcp\Tool\AgentTool
 * @covers \OCA\OpenRegister\Mcp\Tool\ApplicationTool
 */
class ToolDispatchGoldenTest extends TestCase
{

    private const REFUSED = [
        'success' => false,
        'error'   => 'No user context available. Tool cannot execute without user session.',
    ];

    /**
     * The gated tools, each with a function that reaches its backend.
     *
     * @return array<string, array{0: string, 1: string, 2: array<mixed>}>
     */
    public static function gated(): array
    {
        return [
            'schema'           => ['schema', 'get_schema', ['7']],
            'register'         => ['register', 'get_register', ['7']],
            'objects'          => ['objects', 'get_object', ['7']],
            'objects named'    => ['objects', 'search_objects', ['query' => 'x', 'bogus' => 1]],
            'objects positional' => ['objects', 'search_objects', [5, 0]],
        ];
    }//end gated()

    /**
     * A session without a user, nor an explicit user, nor an agent user, is refused before the backend runs.
     *
     * @param string       $kind       The tool
     * @param string       $function   The function name
     * @param array<mixed> $parameters The parameters
     *
     * @return void
     */
    #[DataProvider('gated')]
    public function testGatedToolRefusesWithoutUser(string $kind, string $function, array $parameters): void
    {
        $tool = $this->tool(kind: $kind, user: null, backend: 'never');

        $this->assertSame(self::REFUSED, $tool->executeFunction($function, $parameters));
    }//end testGatedToolRefusesWithoutUser()

    /**
     * A session user is let through; a backend failure becomes an error result.
     *
     * @param string       $kind       The tool
     * @param string       $function   The function name
     * @param array<mixed> $parameters The parameters
     *
     * @return void
     */
    #[DataProvider('gated')]
    public function testGatedToolAllowsSessionUser(string $kind, string $function, array $parameters): void
    {
        $tool = $this->tool(kind: $kind, user: 'alice', backend: 'throw');

        $this->assertSame(['success' => false, 'error' => 'boom'], $tool->executeFunction($function, $parameters));
    }//end testGatedToolAllowsSessionUser()

    /**
     * An explicit user id is let through without a session user.
     *
     * @param string       $kind       The tool
     * @param string       $function   The function name
     * @param array<mixed> $parameters The parameters
     *
     * @return void
     */
    #[DataProvider('gated')]
    public function testGatedToolAllowsExplicitUser(string $kind, string $function, array $parameters): void
    {
        $tool = $this->tool(kind: $kind, user: null, backend: 'throw');

        $this->assertSame(['success' => false, 'error' => 'boom'], $tool->executeFunction($function, $parameters, 'bob'));
    }//end testGatedToolAllowsExplicitUser()

    /**
     * An agent's user is let through without a session user.
     *
     * @param string       $kind       The tool
     * @param string       $function   The function name
     * @param array<mixed> $parameters The parameters
     *
     * @return void
     */
    #[DataProvider('gated')]
    public function testGatedToolAllowsAgentUser(string $kind, string $function, array $parameters): void
    {
        $tool  = $this->tool(kind: $kind, user: null, backend: 'throw');
        $agent = new Agent();
        $agent->setUser('carol');
        $tool->setAgent($agent);

        $this->assertSame(['success' => false, 'error' => 'boom'], $tool->executeFunction($function, $parameters));
    }//end testGatedToolAllowsAgentUser()

    /**
     * An agent without a user does not open the gate.
     *
     * @return void
     */
    public function testAgentWithoutUserStaysRefused(): void
    {
        $tool = $this->tool(kind: 'register', user: null, backend: 'never');
        $tool->setAgent(new Agent());

        $this->assertSame(self::REFUSED, $tool->executeFunction('get_register', ['7']));
    }//end testAgentWithoutUserStaysRefused()

    /**
     * An unknown function on a gated tool becomes an error result.
     *
     * @return void
     */
    public function testGatedToolUnknownFunctionIsAnError(): void
    {
        $tool   = $this->tool(kind: 'schema', user: 'alice', backend: 'never');
        $result = $tool->executeFunction('no_such_function', []);

        $this->assertFalse($result['success']);
        $this->assertSame('Method noSuchFunction (or noSuchFunction) does not exist', $result['error']);
    }//end testGatedToolUnknownFunctionIsAnError()

    /**
     * The ungated tools reach their mapper without any user.
     *
     * @return void
     */
    public function testUngatedToolsRunWithoutUser(): void
    {
        $this->assertSame(
            ['success' => false, 'error' => 'Failed to get agent: boom'],
            $this->tool(kind: 'agent', user: null, backend: 'throw')->executeFunction('get_agent', ['u-1'])
        );
        $this->assertSame(
            ['success' => false, 'error' => 'Failed to get application: boom'],
            $this->tool(kind: 'application', user: null, backend: 'throw')->executeFunction('get_application', ['u-1'])
        );
    }//end testUngatedToolsRunWithoutUser()

    /**
     * An unknown function on an ungated tool throws.
     *
     * @return void
     */
    public function testUngatedToolUnknownFunctionThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->tool(kind: 'agent', user: 'alice', backend: 'never')->executeFunction('no_such_function', []);
    }//end testUngatedToolUnknownFunctionThrows()

    /**
     * Build a tool whose backend either must not be called or throws "boom".
     *
     * @param string      $kind    The tool
     * @param string|null $user    The session user id
     * @param string      $backend "never" or "throw"
     *
     * @return AbstractTool The tool
     */
    private function tool(string $kind, ?string $user, string $backend): AbstractTool
    {
        $session = $this->createMock(IUserSession::class);
        $iUser   = null;
        if ($user !== null) {
            $iUser = $this->createMock(IUser::class);
            $iUser->method('getUID')->willReturn($user);
        }

        $session->method('getUser')->willReturn($iUser);
        $logger = $this->createMock(LoggerInterface::class);

        $class = match ($kind) {
            'schema'      => SchemaMapperInterface::class,
            'register'    => RegisterServiceInterface::class,
            'objects'     => ObjectOperationsInterface::class,
            'agent'       => AgentMapper::class,
            'application' => ApplicationMapperInterface::class,
        };
        $methods = match ($kind) {
            'schema', 'register'   => ['find'],
            'objects'              => ['find', 'findAll'],
            'agent', 'application' => ['findByUuid'],
        };
        $mock = $this->createMock($class);
        foreach ($methods as $name) {
            $this->arm(mock: $mock, method: $name, backend: $backend);
        }

        return match ($kind) {
            'schema'      => new SchemaTool($session, $logger, $mock),
            'register'    => new RegisterTool($session, $logger, $mock),
            'objects'     => new ObjectsTool($session, $logger, $mock),
            'agent'       => new AgentTool($mock, $session, $logger),
            'application' => new ApplicationTool($mock, $session, $logger),
        };
    }//end tool()

    /**
     * Arm one backend method.
     *
     * @param \PHPUnit\Framework\MockObject\MockObject $mock    The backend
     * @param string                                   $method  The method
     * @param string                                   $backend "never" or "throw"
     *
     * @return void
     */
    private function arm(\PHPUnit\Framework\MockObject\MockObject $mock, string $method, string $backend): void
    {
        if ($backend === 'never') {
            $mock->expects($this->never())->method($method);
            return;
        }

        $mock->method($method)->willThrowException(new RuntimeException('boom'));
    }//end arm()
}//end class
