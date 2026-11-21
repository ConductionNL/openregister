<?php

/**
 * EntityRelationDecisionTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-2.1
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\DecisionMetadataEdit;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Migration\Version1Date20261114100100;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Decisions on an entity relation are explicit and name who took them.
 *
 * @covers \OCA\OpenRegister\Db\EntityRelationMapper
 * @covers \OCA\OpenRegister\Db\EntityRelation
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100100
 * @uses   \OCA\OpenRegister\Db\DecisionMetadataEdit
 * @uses   \OCA\OpenRegister\Db\DecisionMetadataChange
 * @uses   \OCA\OpenRegister\Db\EntityRelation\EntityRelationFieldTypes
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 * @uses   \OCA\OpenRegister\Exception\CustomValidationException
 * @uses   \OCA\OpenRegister\Service\Registers\EntityEventPublisher
 * @uses   \OCA\OpenRegister\Event\EntityRelationDecisionUpdatedEvent
 * @uses   \OCA\OpenRegister\Kernel\Contracts\RelationDecisionUpdate
 */
class EntityRelationDecisionTest extends TestCase {

	/**
	 * The updates the backfill ran: the decision set and the skip value it selected.
	 *
	 * @var list<array{set: mixed, where: list<mixed>}>
	 */
	private array $updates = [];

	private function mapper(): EntityRelationMapper {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('j.devries');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$mapper = $this->getMockBuilder(EntityRelationMapper::class)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$this->createMock(AuditTrailMapper::class),
				$session,
				new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['update'])
			->getMock();
		$mapper->method('update')->willReturnArgument(0);

		return $mapper;
	}//end mapper()

	public function testTheBackfillMapsSkipToRelease(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$connection->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingQuery());

		(new Version1Date20261114100100(connection: $connection))->postSchemaChange(
			$this->createMock(IOutput::class),
			static fn () => null,
			[]
		);

		$this->assertSame(
			[
				['set' => ['redaction_decision', 'release'], 'where' => [['redaction_decision', 'undecided'], ['skip_anonymization', true], ['decided_at', null]]],
				['set' => ['redaction_decision', 'redact'], 'where' => [['redaction_decision', 'undecided'], ['skip_anonymization', false], ['decided_at', null]]],
			],
			$this->updates
		);
	}//end testTheBackfillMapsSkipToRelease()

	/**
	 * A query builder that records one update: the column set, and each condition as column and value.
	 */
	private function recordingQuery(): IQueryBuilder {
		$index = count($this->updates);
		$this->updates[$index] = ['set' => null, 'where' => []];
		$parameters = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $parameter): string => $column.'='.$parameter);
		$expr->method('isNull')->willReturnCallback(static fn (string $column): string => $column.' IS NULL');

		$query = $this->createMock(IQueryBuilder::class);
		$query->method('expr')->willReturn($expr);
		$query->method('update')->willReturnSelf();
		$query->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$parameters): string {
				$name = ':p'.count($parameters);
				$parameters[$name] = $value;
				return $name;
			}
		);
		$query->method('set')->willReturnCallback(
			function (string $column, string $parameter) use ($query, $index, &$parameters): IQueryBuilder {
				$this->updates[$index]['set'] = [$column, $parameters[$parameter]];
				return $query;
			}
		);
		$where = function (string $condition) use ($query, $index, &$parameters): IQueryBuilder {
			if (str_ends_with($condition, ' IS NULL') === true) {
				$this->updates[$index]['where'][] = [substr($condition, 0, -8), null];
				return $query;
			}

			[$column, $parameter] = explode('=', $condition);
			$this->updates[$index]['where'][] = [$column, $parameters[$parameter]];
			return $query;
		};
		$query->method('where')->willReturnCallback($where);
		$query->method('andWhere')->willReturnCallback($where);
		$query->method('executeStatement')->willReturn(3);

		return $query;
	}//end recordingQuery()

	public function testAPatchRecordsWhoDecided(): void {
		$relation = new EntityRelation();
		$this->assertSame('undecided', $relation->getRedactionDecision());

		$decided = $this->mapper()->updateDecisionMetadata(edit: new DecisionMetadataEdit(relation: $relation, fields: ['decision' => 'release'], actingUser: null));

		$this->assertSame('release', $decided->getRedactionDecision());
		$this->assertTrue($decided->getSkipAnonymization(), 'a release is never redacted');
		$this->assertSame('j.devries', $decided->getDecidedBy());
		$this->assertInstanceOf(\DateTime::class, $decided->getDecidedAt());

		$json = $decided->jsonSerialize();
		$this->assertSame('release', $json['redactionDecision']);
		$this->assertSame('j.devries', $json['decidedBy']);
		$this->assertArrayHasKey('decision', $json, 'the decision record link keeps its key');
	}//end testAPatchRecordsWhoDecided()

	public function testAFlippedSkipFlagIsADecision(): void {
		$decided = $this->mapper()->updateDecisionMetadata(edit: new DecisionMetadataEdit(relation: new EntityRelation(), fields: ['skipAnonymization' => true], actingUser: null));

		$this->assertSame('release', $decided->getRedactionDecision());
		$this->assertSame('j.devries', $decided->getDecidedBy());

		// The same flag again changes nothing, and decides nothing.
		$same = $this->mapper()->updateDecisionMetadata(edit: new DecisionMetadataEdit(relation: new EntityRelation(), fields: ['skipAnonymization' => false], actingUser: null));
		$this->assertSame('undecided', $same->getRedactionDecision());
		$this->assertNull($same->getDecidedBy());
	}//end testAFlippedSkipFlagIsADecision()

	public function testBackToUndecidedNamesNobody(): void {
		$relation = new EntityRelation();
		$relation->setRedactionDecision('redact');
		$relation->setDecidedBy('j.devries');
		$relation->setDecidedAt(new \DateTime('2026-10-01'));

		$decided = $this->mapper()->updateDecisionMetadata(edit: new DecisionMetadataEdit(relation: $relation, fields: ['decision' => 'undecided'], actingUser: null));

		$this->assertSame('undecided', $decided->getRedactionDecision());
		$this->assertNull($decided->getDecidedBy());
		$this->assertNull($decided->getDecidedAt());
	}//end testBackToUndecidedNamesNobody()

	public function testAnUnknownOrContradictingDecisionIsRefused(): void {
		foreach ([['decision' => 'maybe'], ['decision' => 'release', 'skipAnonymization' => false]] as $fields) {
			try {
				$this->mapper()->updateDecisionMetadata(edit: new DecisionMetadataEdit(relation: new EntityRelation(), fields: $fields, actingUser: null));
				$this->fail('Accepted '.json_encode($fields));
			} catch (CustomValidationException $e) {
				$this->assertSame('decision', $e->getErrors()['field']);
			}
		}
	}//end testAnUnknownOrContradictingDecisionIsRefused()
}//end class
