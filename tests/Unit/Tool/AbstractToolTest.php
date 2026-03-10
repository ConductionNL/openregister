<?php

namespace Unit\Tool;

use BadMethodCallException;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Tool\AbstractTool;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AbstractTool via a concrete anonymous subclass.
 */
class AbstractToolTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private AbstractTool $tool;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        // Create a concrete subclass of AbstractTool for testing.
        $this->tool = new class ($this->userSession, $this->logger) extends AbstractTool {
            public function getName(): string
            {
                return 'test_tool';
            }

            public function getDescription(): string
            {
                return 'A test tool';
            }

            public function getFunctions(): array
            {
                return [
                    [
                        'name'        => 'do_something',
                        'description' => 'Does something',
                        'parameters'  => [],
                    ],
                ];
            }

            public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
            {
                return ['executed' => $functionName];
            }

            // Expose protected methods for testing.
            public function publicGetUserId(?string $explicitUserId = null): ?string
            {
                return $this->getUserId($explicitUserId);
            }

            public function publicHasUserContext(?string $explicitUserId = null): bool
            {
                return $this->hasUserContext($explicitUserId);
            }

            public function publicApplyViewFilters(array $params): array
            {
                return $this->applyViewFilters($params);
            }

            public function publicFormatSuccess($data, string $message = 'Success'): array
            {
                return $this->formatSuccess($data, $message);
            }

            public function publicFormatError(string $message, $details = null): array
            {
                return $this->formatError($message, $details);
            }

            public function publicValidateParameters(array $parameters, array $required): void
            {
                $this->validateParameters($parameters, $required);
            }

            public function publicLog(string $functionName, array $parameters, string $level = 'info', string $message = ''): void
            {
                $this->log($functionName, $parameters, $level, $message);
            }

            // Methods for __call testing.
            public function doSomething(int $limit = 10, string $name = 'default'): array
            {
                return ['limit' => $limit, 'name' => $name];
            }

            public function typedMethod(int $intVal, float $floatVal, bool $boolVal, string $strVal, array $arrVal): array
            {
                return [
                    'int'   => $intVal,
                    'float' => $floatVal,
                    'bool'  => $boolVal,
                    'str'   => $strVal,
                    'arr'   => $arrVal,
                ];
            }
        };
    }

    private function createAgentEntity(?string $user = null, ?array $views = null, ?string $owner = null): Agent
    {
        $agent = new Agent();
        if ($user !== null) {
            $agent->setUser($user);
        }
        if ($views !== null) {
            $agent->setViews($views);
        }
        if ($owner !== null) {
            $agent->setOwner($owner);
        }
        return $agent;
    }

    // ------------------------------------------------------------------
    // getName / getDescription / getFunctions / executeFunction
    // ------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('test_tool', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertSame('A test tool', $this->tool->getDescription());
    }

    public function testGetFunctions(): void
    {
        $functions = $this->tool->getFunctions();
        $this->assertCount(1, $functions);
        $this->assertSame('do_something', $functions[0]['name']);
    }

    public function testExecuteFunction(): void
    {
        $result = $this->tool->executeFunction('test_fn', ['a' => 1]);
        $this->assertSame('test_fn', $result['executed']);
    }

    // ------------------------------------------------------------------
    // setAgent
    // ------------------------------------------------------------------

    public function testSetAgentNull(): void
    {
        $this->tool->setAgent(null);
        $params = $this->tool->publicApplyViewFilters(['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $params);
    }

    public function testSetAgentWithAgent(): void
    {
        $agent = $this->createAgentEntity(null, [1, 2]);
        $this->tool->setAgent($agent);

        // Currently view filtering is a no-op (TODO in source), so params return unchanged.
        $params = $this->tool->publicApplyViewFilters(['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $params);
    }

    // ------------------------------------------------------------------
    // getUserId
    // ------------------------------------------------------------------

    public function testGetUserIdWithExplicitUserId(): void
    {
        $this->assertSame('explicit-user', $this->tool->publicGetUserId('explicit-user'));
    }

    public function testGetUserIdFromSession(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('session-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->assertSame('session-user', $this->tool->publicGetUserId());
    }

    public function testGetUserIdFromAgentFallback(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $agent = $this->createAgentEntity('agent-user');
        $this->tool->setAgent($agent);

        $this->assertSame('agent-user', $this->tool->publicGetUserId());
    }

    public function testGetUserIdReturnsNullWhenNoContext(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->assertNull($this->tool->publicGetUserId());
    }

    public function testGetUserIdAgentWithNullUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $agent = $this->createAgentEntity();
        $this->tool->setAgent($agent);

        $this->assertNull($this->tool->publicGetUserId());
    }

    // ------------------------------------------------------------------
    // hasUserContext
    // ------------------------------------------------------------------

    public function testHasUserContextTrue(): void
    {
        $this->assertTrue($this->tool->publicHasUserContext('some-user'));
    }

    public function testHasUserContextFalse(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->assertFalse($this->tool->publicHasUserContext());
    }

    // ------------------------------------------------------------------
    // applyViewFilters
    // ------------------------------------------------------------------

    public function testApplyViewFiltersNoAgent(): void
    {
        $this->tool->setAgent(null);
        $this->assertSame(['x' => 1], $this->tool->publicApplyViewFilters(['x' => 1]));
    }

    public function testApplyViewFiltersAgentNullViews(): void
    {
        $agent = $this->createAgentEntity();
        $this->tool->setAgent($agent);
        $this->assertSame(['x' => 1], $this->tool->publicApplyViewFilters(['x' => 1]));
    }

    public function testApplyViewFiltersAgentEmptyViews(): void
    {
        $agent = $this->createAgentEntity(null, []);
        $this->tool->setAgent($agent);
        $this->assertSame(['x' => 1], $this->tool->publicApplyViewFilters(['x' => 1]));
    }

    // ------------------------------------------------------------------
    // formatSuccess / formatError
    // ------------------------------------------------------------------

    public function testFormatSuccessDefault(): void
    {
        $result = $this->tool->publicFormatSuccess(['id' => 1]);
        $this->assertTrue($result['success']);
        $this->assertSame('Success', $result['message']);
        $this->assertSame(['id' => 1], $result['data']);
    }

    public function testFormatSuccessCustomMessage(): void
    {
        $result = $this->tool->publicFormatSuccess('data', 'Custom');
        $this->assertSame('Custom', $result['message']);
    }

    public function testFormatErrorWithoutDetails(): void
    {
        $result = $this->tool->publicFormatError('Something failed');
        $this->assertFalse($result['success']);
        $this->assertSame('Something failed', $result['error']);
        $this->assertArrayNotHasKey('details', $result);
    }

    public function testFormatErrorWithDetails(): void
    {
        $result = $this->tool->publicFormatError('fail', ['trace' => 'xxx']);
        $this->assertFalse($result['success']);
        $this->assertSame(['trace' => 'xxx'], $result['details']);
    }

    // ------------------------------------------------------------------
    // validateParameters
    // ------------------------------------------------------------------

    public function testValidateParametersSuccess(): void
    {
        $this->tool->publicValidateParameters(['a' => 1, 'b' => 2], ['a', 'b']);
        $this->assertTrue(true);
    }

    public function testValidateParametersMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: b');
        $this->tool->publicValidateParameters(['a' => 1], ['a', 'b']);
    }

    public function testValidateParametersNullValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter: a');
        $this->tool->publicValidateParameters(['a' => null], ['a']);
    }

    // ------------------------------------------------------------------
    // log
    // ------------------------------------------------------------------

    public function testLogInfo(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('test_tool'));

        $this->tool->publicLog('myFunc', ['p' => 1]);
    }

    public function testLogError(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('myFunc'));

        $this->tool->publicLog('myFunc', [], 'error', 'oh no');
    }

    public function testLogWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('myFunc'));

        $this->tool->publicLog('myFunc', [], 'warning', 'watch out');
    }

    public function testLogCustomMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Custom msg'));

        $this->tool->publicLog('fn', [], 'info', 'Custom msg');
    }

    public function testLogDefaultMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Executing function'));

        $this->tool->publicLog('fn', []);
    }

    // ------------------------------------------------------------------
    // __call — snake_case to camelCase dispatch
    // ------------------------------------------------------------------

    public function testCallSnakeCaseToCamelCase(): void
    {
        $result = $this->tool->do_something(5, 'test');
        $decoded = json_decode($result, true);
        $this->assertSame(5, $decoded['limit']);
        $this->assertSame('test', $decoded['name']);
    }

    public function testCallWithDefaultValues(): void
    {
        $result = json_decode($this->tool->do_something(), true);
        $this->assertSame(10, $result['limit']);
        $this->assertSame('default', $result['name']);
    }

    public function testCallNullStringConvertedToDefault(): void
    {
        $result = json_decode($this->tool->do_something('null', 'null'), true);
        $this->assertSame(10, $result['limit']);
        $this->assertSame('default', $result['name']);
    }

    public function testCallTypeCastingInt(): void
    {
        $result = json_decode($this->tool->typed_method('42', '3.14', 'true', 123, 'not-array'), true);
        $this->assertSame(42, $result['int']);
        $this->assertSame(3.14, $result['float']);
        $this->assertTrue($result['bool']);
        $this->assertSame('123', $result['str']);
        $this->assertSame([], $result['arr']);
    }

    public function testCallTypeCastingBoolFalse(): void
    {
        $result = json_decode($this->tool->typed_method('1', '1.0', 'false', 'x', [1, 2]), true);
        $this->assertFalse($result['bool']);
        $this->assertSame([1, 2], $result['arr']);
    }

    public function testCallAssociativeArguments(): void
    {
        $result = json_decode($this->tool->do_something(...['name' => 'hello', 'limit' => 99]), true);
        $this->assertSame(99, $result['limit']);
        $this->assertSame('hello', $result['name']);
    }

    public function testCallNonExistentMethodThrows(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('does not exist');
        $this->tool->non_existent_method();
    }

    public function testCallReturnsNonArrayAsIs(): void
    {
        $tool = new class ($this->userSession, $this->logger) extends AbstractTool {
            public function getName(): string
            {
                return 'str_tool';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getFunctions(): array
            {
                return [];
            }

            public function executeFunction(string $functionName, array $parameters, ?string $userId = null): array
            {
                return [];
            }

            public function returnString(): string
            {
                return 'hello';
            }
        };

        $result = $tool->return_string();
        $this->assertSame('hello', $result);
    }

    // ------------------------------------------------------------------
    // Data provider tests for log levels
    // ------------------------------------------------------------------

    public static function logLevelProvider(): array
    {
        return [
            'info level'    => ['info', 'info'],
            'error level'   => ['error', 'error'],
            'warning level' => ['warning', 'warning'],
            'unknown level' => ['unknown', 'info'],
        ];
    }

    #[DataProvider('logLevelProvider')]
    public function testLogLevels(string $inputLevel, string $expectedMethod): void
    {
        $this->logger->expects($this->once())
            ->method($expectedMethod);

        $this->tool->publicLog('fn', [], $inputLevel, 'msg');
    }
}
