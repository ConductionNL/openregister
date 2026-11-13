<?php

declare(strict_types=1);

/**
 * The entity plane's answer to an anonymous caller, pinned by measurement
 * before consolidate-permission-handling moves anything (task 1).
 *
 * Every mapper that decides entity access through `hasRbacPermission()` is
 * named here, so a consolidation that quietly opens ONE of them fails by name,
 * not by a count that stays the same while the wrong entity opened. The two
 * blanket bypasses (CLI, SystemOperationContext) are pinned as the only ones:
 * a third trusted path arriving later breaks
 * testTheTrustedCallerHasExactlyTheTwoBypasses(). An evaluation judged as
 * anonymous is refused even inside a system operation ("narrowing wins").
 *
 * PHPUnit itself runs under the CLI SAPI, which the trait trusts, so the
 * anonymous answer is asked inside AnonymousEvaluationContext, the scope the
 * trait honours for "judge this as an anonymous caller".
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 *
 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Db\Tenancy\EntityRbacTrait;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\EntityRbacTrait
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class EntityPlaneAnonymousPinTest extends TestCase {

	private const VERBS = ['create', 'read', 'update', 'delete'];

	/**
	 * Every mapper that decides entity access through the trait, with the entity type it checks.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function mappers(): array {
		return [
			'register' => [RegisterMapper::class, 'register'],
			'schema' => [SchemaMapper::class, 'schema'],
			'agent' => [AgentMapper::class, 'agent'],
			'webhook' => [WebhookMapper::class, 'webhook'],
			'application' => [ApplicationMapper::class, 'application'],
			'source' => [SourceMapper::class, 'source'],
			'view' => [ViewMapper::class, 'view'],
			'mapping' => [MappingMapper::class, 'mapping'],
			'endpoint' => [EndpointMapper::class, 'endpoint'],
			'configuration' => [ConfigurationMapper::class, 'configuration'],
		];
	}//end mappers()

	/**
	 * The mapper's own decision for a caller without a session user.
	 *
	 * The mapper is built without its constructor, so it has no session at
	 * all: exactly what an anonymous web request looks like to the trait.
	 *
	 * @param class-string $class The mapper.
	 * @param string $verb The action.
	 * @param string $entityType The entity type.
	 *
	 * @return bool The decision.
	 */
	private static function decide(string $class, string $verb, string $entityType): bool {
		$mapper = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod($class, 'hasRbacPermission');
		$method->setAccessible(true);

		return (bool)$method->invoke($mapper, $verb, $entityType);
	}//end decide()

	/**
	 * @dataProvider mappers
	 *
	 * @param class-string $class The mapper.
	 * @param string $entityType The entity type.
	 */
	public function testAnAnonymousCallerIsRefusedEveryVerb(string $class, string $entityType): void {
		foreach (self::VERBS as $verb) {
			$decision = AnonymousEvaluationContext::run(static fn (): bool => self::decide(class: $class, verb: $verb, entityType: $entityType));

			$this->assertFalse($decision, $entityType . ' ' . $verb . ' must refuse an anonymous caller');
		}
	}//end testAnAnonymousCallerIsRefusedEveryVerb()

	/**
	 * Narrowing wins over elevating: an evaluation judged as anonymous is
	 * refused even inside a system operation, because the anonymous scope
	 * makes SystemOperationContext answer inactive.
	 *
	 * @dataProvider mappers
	 *
	 * @param class-string $class The mapper.
	 * @param string $entityType The entity type.
	 */
	public function testAnAnonymousEvaluationIsRefusedEvenInsideASystemOperation(string $class, string $entityType): void {
		foreach (self::VERBS as $verb) {
			$decision = SystemOperationContext::run(
				static fn (): bool => AnonymousEvaluationContext::run(static fn (): bool => self::decide(class: $class, verb: $verb, entityType: $entityType))
			);

			$this->assertFalse($decision, $entityType . ' ' . $verb . ' must refuse an anonymous evaluation inside a system operation');
		}
	}//end testAnAnonymousEvaluationIsRefusedEvenInsideASystemOperation()

	/**
	 * @dataProvider mappers
	 *
	 * @param class-string $class The mapper.
	 * @param string $entityType The entity type.
	 */
	public function testTheCliIsTrustedWhenNotJudgedAsAnonymous(string $class, string $entityType): void {
		$this->assertSame('cli', PHP_SAPI);

		foreach (self::VERBS as $verb) {
			$this->assertTrue(self::decide(class: $class, verb: $verb, entityType: $entityType), $entityType . ' ' . $verb);
		}
	}//end testTheCliIsTrustedWhenNotJudgedAsAnonymous()

	/**
	 * @dataProvider mappers
	 *
	 * @param class-string $class The mapper.
	 * @param string $entityType The entity type.
	 */
	public function testNoMapperDecidesOnItsOwn(string $class, string $entityType): void {
		$traitFile = (new ReflectionClass(EntityRbacTrait::class))->getFileName();

		foreach (['hasRbacPermission', 'isTrustedSystemCaller', 'verifyRbacPermission'] as $method) {
			$this->assertSame($traitFile, (new ReflectionMethod($class, $method))->getFileName(), $class . '::' . $method . ' must be the trait\'s');
		}
	}//end testNoMapperDecidesOnItsOwn()

	/**
	 * The only callers trusted without a session user are the CLI (unless it
	 * asked to be judged as anonymous) and a SystemOperationContext. A third
	 * trusted path changes the identifiers or the returns of the method below.
	 */
	public function testTheTrustedCallerHasExactlyTheTwoBypasses(): void {
		$tokens = self::bodyTokens(method: 'isTrustedSystemCaller');

		$identifiers = array_values(array_unique(array_map(static fn (array $token): string => $token[1], array_filter($tokens, static fn (mixed $token): bool => is_array($token) === true && $token[0] === T_STRING))));
		sort($identifiers);
		$this->assertSame(['AnonymousEvaluationContext', 'PHP_SAPI', 'SystemOperationContext', 'false', 'isActive', 'true'], $identifiers);
		$this->assertSame('cli', self::stringLiterals(tokens: $tokens)[0] ?? null);
		$this->assertCount(2, array_filter($tokens, static fn (mixed $token): bool => is_array($token) === true && $token[0] === T_RETURN));
	}//end testTheTrustedCallerHasExactlyTheTwoBypasses()

	/**
	 * The decision has exactly three ways to answer: an administrator, the
	 * trusted-caller check for no session user, and the organisation policy.
	 */
	public function testTheDecisionHasExactlyThreeAnswers(): void {
		$tokens = self::bodyTokens(method: 'hasRbacPermission');

		$called = [];
		foreach ($tokens as $i => $token) {
			if (is_array($token) === true && $token[0] === T_STRING && ($tokens[$i + 1] ?? null) === '(') {
				$called[] = $token[1];
			}
		}

		$this->assertSame(['isCurrentUserAdmin', 'getCurrentUserId', 'isTrustedSystemCaller', 'organisationPolicyAllows'], $called);
		$this->assertCount(3, array_filter($tokens, static fn (mixed $token): bool => is_array($token) === true && $token[0] === T_RETURN));
	}//end testTheDecisionHasExactlyThreeAnswers()

	/**
	 * The string literals among tokens, unquoted.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $tokens The tokens.
	 *
	 * @return list<string>
	 */
	private static function stringLiterals(array $tokens): array {
		$literals = [];
		foreach ($tokens as $token) {
			if (is_array($token) === true && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
				$literals[] = trim($token[1], '\'"');
			}
		}

		return $literals;
	}//end stringLiterals()

	/**
	 * The tokens of one method body of the trait, without whitespace and comments.
	 *
	 * @param string $method The method.
	 *
	 * @return list<array{0: int, 1: string, 2: int}|string>
	 */
	private static function bodyTokens(string $method): array {
		$reflection = new ReflectionMethod(EntityRbacTrait::class, $method);
		$lines = file((string)$reflection->getFileName());
		$source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
		$tokens = token_get_all('<?php ' . $source);
		$body = array_slice($tokens, (int)array_search('{', $tokens, true) + 1);

		return array_values(array_filter($body, static fn (mixed $token): bool => is_array($token) === false || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true) === false));
	}//end bodyTokens()
}//end class
