<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Audit\ReadRuleResolver}.
 *
 * The real per-entry matcher and condition matcher decide; only the session, the group
 * manager and the schema mapper are doubles.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Audit\ReadRuleCaller;
use OCA\OpenRegister\Service\Audit\ReadRuleResolver;
use OCA\OpenRegister\Service\Audit\RuleDigest;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\ConditionUserResolvers;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Audit\ReadRuleResolver
 * @covers \OCA\OpenRegister\Service\Audit\ReadRuleCaller
 * @covers \OCA\OpenRegister\Service\Audit\RuleDigest
 */
class ReadRuleResolverTest extends TestCase {

	/**
	 * The row-level rule of the scenarios: a handler reads the meldingen assigned to them.
	 *
	 * @var array<string, mixed>
	 */
	private const HANDLER_RULE = ['group' => 'behandelaars', 'match' => ['behandelaar' => '$userId']];

	private ?string $uid = 'alice';

	/** @var array<int, string> */
	private array $groups = ['behandelaars'];

	/** Nextcloud's administrator verdict; null answers as inside allowed_admin_ranges. */
	private ?bool $administrator = null;

	private MockObject&SchemaMapperInterface $schemas;

	private MockObject&LoggerInterface $logger;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function resolver(): ReadRuleResolver {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->uid === null) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			return $user;
		});
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(fn (): array => $this->groups);
		$groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->administrator ?? in_array('admin', $this->groups, true));

		$conditions = new ConditionMatcher(
			new ConditionUserResolvers($session),
			$this->createMock(ContainerInterface::class),
			new OperatorEvaluator(logger: new NullLogger()),
			new NullLogger()
		);

		return new ReadRuleResolver(
			schemas: $this->schemas,
			userSession: $session,
			groupManager: $groupManager,
			conditionMatcher: $conditions,
			logger: $this->logger
		);
	}

	/**
	 * A melding in schema 7, assigned to a handler, owned by someone else.
	 *
	 * @param string $handler The assigned handler.
	 * @param string $owner   The owner.
	 */
	private function melding(string $handler, string $owner = 'creator'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('m-123');
		$object->setSchema('7');
		$object->setOwner($owner);
		$object->setObject(['behandelaar' => $handler, 'titel' => 'Lantaarnpaal']);

		return $object;
	}

	/**
	 * Let schema 7 carry this authorization block at this version.
	 *
	 * @param array<string, mixed> $authorization The block.
	 * @param string               $version       The schema version.
	 */
	private function schemaWith(array $authorization, string $version = '1.4.0'): void {
		$schema = new Schema();
		$schema->setAuthorization($authorization);
		$schema->setVersion($version);
		$this->schemas->method('find')->willReturn($schema);
	}

	public function testAReadUnderARowLevelRuleRecordsThatRuleAndTheSchemaVersion(): void {
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);

		$match = $this->resolver()->resolve($this->melding(handler: 'alice'));

		$this->assertNotNull($match);
		$this->assertSame(self::HANDLER_RULE, $match->rule);
		$this->assertSame(RuleDigest::ofEntry(self::HANDLER_RULE), $match->digest);
		$this->assertSame(64, strlen($match->digest));
		$this->assertSame('1.4.0', $match->version);
	}

	public function testTheDigestDoesNotDependOnTheReader(): void {
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);
		$alice = $this->resolver()->resolve($this->melding(handler: 'alice'));
		$this->uid = 'bob';
		$bob = $this->resolver()->resolve($this->melding(handler: 'bob'));

		$this->assertNotNull($alice);
		$this->assertNotNull($bob);
		$this->assertSame($alice->digest, $bob->digest);
	}

	public function testTheFirstMatchingRuleInDeclarationOrderIsNamed(): void {
		$other = ['group' => 'behandelaars', 'match' => ['behandelaar' => 'someone-else']];
		$broad = 'behandelaars';
		$this->schemaWith(['read' => [$other, self::HANDLER_RULE, $broad]]);

		$match = $this->resolver()->resolve($this->melding(handler: 'alice'));

		$this->assertSame(self::HANDLER_RULE, $match?->rule);
	}

	public function testDifferentRulesGiveDifferentDigests(): void {
		$this->schemaWith(['read' => ['behandelaars']]);
		$byGroup = $this->resolver()->resolve($this->melding(handler: 'nobody'));
		$this->setUp();
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);
		$byRow = $this->resolver()->resolve($this->melding(handler: 'alice'));

		$this->assertSame('behandelaars', $byGroup?->rule);
		$this->assertNotSame($byGroup?->digest, $byRow?->digest);
		$this->assertNotSame(RuleDigest::ofEntry('behandelaars'), RuleDigest::ofEntry(['group' => 'behandelaars']));
	}

	public function testKeyOrderOfTheDeclaredRuleDoesNotChangeTheDigest(): void {
		$this->schemaWith(['read' => [['match' => ['behandelaar' => '$userId'], 'group' => 'behandelaars']]]);

		$this->assertSame(RuleDigest::ofEntry(self::HANDLER_RULE), $this->resolver()->resolve($this->melding(handler: 'alice'))?->digest);
	}

	public function testAnAdministratorsReadNamesNoRule(): void {
		$this->groups = ['admin', 'behandelaars'];
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'alice')));
	}

	/**
	 * An `admin` group member Nextcloud does not count as an administrator reads under the rule, which is named.
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testAnAdminGroupMemberOutsideTheAllowedRangeReadsUnderTheRule(): void {
		$this->groups = ['admin', 'behandelaars'];
		$this->administrator = false;
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);

		$this->assertNotNull($this->resolver()->resolve($this->melding(handler: 'alice')));
	}

	public function testTheOwnersReadNamesNoRule(): void {
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'alice', owner: 'alice')));
	}

	/**
	 * @dataProvider unruledBlocks
	 *
	 * @param array<string, mixed> $authorization The block.
	 */
	public function testASchemaWithoutReadRulesNamesNoRule(array $authorization): void {
		$this->schemaWith($authorization);

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'alice')));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function unruledBlocks(): array {
		return [
			'no authorization' => [[]],
			'no read key' => [['update' => ['behandelaars']]],
			'an empty read list' => [['read' => []]],
			'public opt-in' => [['public' => true, 'read' => ['behandelaars']]],
		];
	}

	public function testAReaderNoRuleAdmitsNamesNoRule(): void {
		$this->groups = ['burgers'];
		$this->schemaWith(['read' => [self::HANDLER_RULE]]);

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'bob')));
	}

	public function testAnAnonymousReadUnderThePublicRuleNamesIt(): void {
		$this->uid = null;
		$this->groups = [];
		$this->schemaWith(['read' => ['behandelaars', 'public']]);

		$this->assertSame('public', $this->resolver()->resolve($this->melding(handler: 'alice'))?->rule);
	}

	public function testTheAgentScopeEntryIsNeverNamed(): void {
		$this->groups = ['mcp'];
		$this->schemaWith(['read' => [['scope' => 'mcp'], 'mcp']]);

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'alice')));
	}

	public function testAnUnreadableSchemaNamesNoRuleAndSaysSo(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull($this->resolver()->resolve($this->melding(handler: 'alice')));
	}

	public function testAnObjectWithoutASchemaNamesNoRule(): void {
		$this->schemas->expects($this->never())->method('find');
		$object = new ObjectEntity();

		$this->assertNull($this->resolver()->resolve($object));
	}

	public function testAnAuditorRecomputesTheDigestFromTheStoredSchemaVersion(): void {
		// The schema as it stood at version 1.4.0, as stored by the schema history.
		$stored = (string) json_encode(['version' => '1.4.0', 'authorization' => ['read' => [self::HANDLER_RULE]]]);
		$this->schemaWith(['read' => [self::HANDLER_RULE]], '1.4.0');
		$entry = $this->resolver()->resolve($this->melding(handler: 'alice'));

		$atVersion = json_decode($stored, true);
		$this->assertSame($entry?->version, $atVersion['version']);
		$this->assertSame($entry?->digest, RuleDigest::ofEntry($atVersion['authorization']['read'][0]));
	}

	public function testTheCallerHoldsItsPrincipalsInThePermissionChecksOrder(): void {
		$this->assertSame(['public'], (new ReadRuleCaller(userId: null, groups: ['x']))->principals());
		$this->assertSame(['authenticated', '', 'a', 'b', 'public'], (new ReadRuleCaller(userId: 'u', groups: ['a', 'b']))->principals());
	}
}
