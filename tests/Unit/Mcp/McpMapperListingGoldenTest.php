<?php

/**
 * Golden test for the agent and conversation listing reads.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\Service\Chat\TitleClaim;
use OCA\OpenRegister\Mcp\Db\ConversationScope;
use OCA\OpenRegister\Mcp\Db\AgentAudience;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapperPlatform;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SQL and the results of the agent organisation listing and the
 * conversation owner listings, counts and title lookup.
 *
 * The agent listing is an access path: a private agent is returned only to
 * its owner or an invited user, and every agent in the page is returned when
 * no user is given. Both outcomes are pinned.
 */
class McpMapperListingGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/mcp-mapper-listing-golden.json';

	private static function agentRows(): array {
		return [
			['id' => 1, 'uuid' => 'a-public', 'is_private' => 0, 'owner' => 'owner'],
			['id' => 2, 'uuid' => 'a-private', 'is_private' => 1, 'owner' => 'owner', 'invited_users' => '["guest"]'],
		];
	}//end agentRows()

	private static function conversationCalls(): array {
		return [
			'findByUser defaults' => ['findByUser', ['userId' => 'alice'], [[['id' => 1, 'uuid' => 'c1']]]],
			'findByUser org, deleted, page' => ['findByUser', ['userId' => 'alice', 'organisation' => 'org-1', 'includeDeleted' => true, 'limit' => 5, 'offset' => 10], [[]]],
			'findByUser org only' => ['findByUser', ['userId' => 'alice', 'organisation' => 'org-1'], [[]]],
			'findDeletedByUser defaults' => ['findDeletedByUser', ['userId' => 'alice'], [[['id' => 2, 'uuid' => 'c2']]]],
			'findDeletedByUser org, page' => ['findDeletedByUser', ['userId' => 'alice', 'organisation' => 'org-1', 'limit' => 3, 'offset' => 6], [[]]],
			'countByUser defaults' => ['countByUser', ['userId' => 'alice'], [[['count' => 4]]]],
			'countByUser org, deleted' => ['countByUser', ['userId' => 'alice', 'organisation' => 'org-1', 'includeDeleted' => true], [[['count' => 7]]]],
			'countDeletedByUser org' => ['countDeletedByUser', ['userId' => 'alice', 'organisation' => 'org-1'], [[['count' => 2]]]],
			'findTitlesByUserAgent' => ['findTitlesByUserAgent', ['userId' => 'alice', 'agentId' => 9, 'titlePattern' => 'New%'], [[['title' => 'New'], ['title' => null], ['title' => 'New (2)']]]],
		];
	}//end conversationCalls()

	public function testListingsMatchGolden(): void {
		$actual = [];
		$actual['agent findByOrganisation, no user'] = $this->agent(args: ['organisationUuid' => 'org-1']);
		$actual['agent findByOrganisation, stranger'] = $this->agent(args: ['organisationUuid' => 'org-1', 'userId' => 'stranger', 'limit' => 5, 'offset' => 10]);
		$actual['agent findByOrganisation, invited'] = $this->agent(args: ['organisationUuid' => 'org-1', 'userId' => 'guest']);
		foreach (self::conversationCalls() as $name => [$method, $args, $rows]) {
			$actual["conversation {$name}"] = $this->conversation(method: $method, args: $args, rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testListingsMatchGolden()

	public function testAPrivateAgentIsRefusedToAStrangerAndAllowedToTheInvited(): void {
		$this->assertSame(['a-public'], $this->agent(args: ['organisationUuid' => 'org-1', 'userId' => 'stranger'])['result']);
		$this->assertSame(['a-public', 'a-private'], $this->agent(args: ['organisationUuid' => 'org-1', 'userId' => 'guest'])['result']);
		$this->assertSame(['a-public', 'a-private'], $this->agent(args: ['organisationUuid' => 'org-1', 'userId' => 'owner'])['result']);
	}//end testAPrivateAgentIsRefusedToAStrangerAndAllowedToTheInvited()

	private function reset(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}//end reset()

	private function agent(array $args): array {
		$db = $this->reset(rows: [self::agentRows()]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$mapper = new AgentMapper(
			new AgentMapperPlatform($db, $this->createMock(IEventDispatcher::class)),
			$this->createMock(OrganisationMapper::class),
			$session,
			$groups
		);
		$outcome = $this->call(
			mapper: $mapper,
			method: 'findByOrganisation',
			args: [
				new AgentAudience(organisationUuid: $args['organisationUuid'], userId: ($args['userId'] ?? null)),
				new ListQuery(limit: ($args['limit'] ?? 50), offset: ($args['offset'] ?? 0)),
			]
		);
		return $outcome;
	}//end agent()

	private function conversation(string $method, array $args, array $rows): array {
		$mapper = new ConversationMapper(
			db: $this->reset(rows: $rows),
			eventDispatcher: $this->createMock(IEventDispatcher::class)
		);
		return $this->call(mapper: $mapper, method: $method, args: self::conversationArgs(method: $method, args: $args));
	}//end conversation()

	/**
	 * The value objects a conversation lookup takes, built from the fields a case names.
	 *
	 * @param string               $method The lookup.
	 * @param array<string, mixed> $args   The case's fields.
	 *
	 * @return array<int, object>
	 */
	private static function conversationArgs(string $method, array $args): array {
		if ($method === 'findTitlesByUserAgent') {
			return [new TitleClaim(baseTitle: rtrim($args['titlePattern'], '%'), userId: $args['userId'], agentId: $args['agentId'])];
		}

		$scope = new ConversationScope(
			userId: $args['userId'],
			organisation: ($args['organisation'] ?? null),
			includeDeleted: ($args['includeDeleted'] ?? false)
		);
		if (str_starts_with($method, 'count') === true) {
			return [$scope];
		}

		return [$scope, new ListQuery(limit: ($args['limit'] ?? 50), offset: ($args['offset'] ?? 0))];
	}//end conversationArgs()

	private function call(object $mapper, string $method, array $args): array {
		$outcome = [];
		try {
			$result = $mapper->$method(...$args);
			$outcome['result'] = is_array($result) === true ? array_map(fn ($r) => is_object($r) ? $r->getUuid() : $r, $result) : $result;
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end call()
}//end class
