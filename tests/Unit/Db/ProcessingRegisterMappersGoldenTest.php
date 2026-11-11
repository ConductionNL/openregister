<?php

/**
 * Characterisation of the processing register mappers: the queries ProcessingPurposeMapper and
 * VerwerkingsactiviteitMapper send, what an empty reference answers, how inserts stamp, and
 * which activities the register accepts and refuses, with the refusal messages.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ProcessingPurpose;
use OCA\OpenRegister\Db\ProcessingPurposeMapper;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden queries, stamps and refusals of the processing register mappers.
 *
 * @covers \OCA\OpenRegister\Db\ProcessingPurposeMapper
 * @covers \OCA\OpenRegister\Db\VerwerkingsactiviteitMapper
 */
final class ProcessingRegisterMappersGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The purpose mapper under test.
	 *
	 * @var ProcessingPurposeMapper
	 */
	private ProcessingPurposeMapper $purposes;

	/**
	 * The activity mapper under test.
	 *
	 * @var VerwerkingsactiviteitMapper
	 */
	private VerwerkingsactiviteitMapper $activities;

	/**
	 * Build both mappers over one recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$db = $this->recordingDb();
		$this->purposes = new ProcessingPurposeMapper($db);
		$this->activities = new VerwerkingsactiviteitMapper($db);
	}//end setUp()

	/**
	 * find() binds the id as an integer, on both tables.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 3, 'code' => 'p-3']];
		$this->results[] = [['id' => 4, 'code' => 'a-4']];

		$this->assertSame('p-3', $this->purposes->find(3)->getCode());
		$this->assertSame('a-4', $this->activities->find(4)->getCode());

		$this->assertSame('select(*) from(openregister_processing_purposes) where(eq(id, :p0)) executeQuery()', $this->sql(0));
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) where(eq(id, :p1)) executeQuery()', $this->sql(1));
		$this->assertSame([3, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertSame([4, IQueryBuilder::PARAM_INT], $this->params[':p1']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * find() of an unknown id throws.
	 *
	 * @return void
	 */
	public function testFindOfUnknownIdThrows(): void {
		$this->expectException(DoesNotExistException::class);
		$this->purposes->find(99);
	}//end testFindOfUnknownIdThrows()

	/**
	 * An empty code, uuid or reference answers null without a query.
	 *
	 * @return void
	 */
	public function testEmptyLookupsAreNullWithoutQuery(): void {
		$this->assertNull($this->purposes->findByCode(''));
		$this->assertNull($this->purposes->findByUuid(''));
		$this->assertNull($this->purposes->resolveReference(''));
		$this->assertNull($this->activities->findByCode(''));
		$this->assertNull($this->activities->findByUuid(''));
		$this->assertNull($this->activities->resolveReference(''));

		$this->assertSame([], $this->queries);
	}//end testEmptyLookupsAreNullWithoutQuery()

	/**
	 * findByCode() and findByUuid() match on their column and return the row.
	 *
	 * @return void
	 */
	public function testLookupsMatchTheirColumn(): void {
		$this->results[] = [['id' => 1, 'code' => 'c-1']];
		$this->results[] = [['id' => 2, 'uuid' => 'u-2']];
		$this->results[] = [['id' => 5, 'code' => 'c-5']];
		$this->results[] = [['id' => 6, 'uuid' => 'u-6']];

		$this->assertSame(1, $this->purposes->findByCode('c-1')?->getId());
		$this->assertSame(2, $this->purposes->findByUuid('u-2')?->getId());
		$this->assertSame(5, $this->activities->findByCode('c-5')?->getId());
		$this->assertSame(6, $this->activities->findByUuid('u-6')?->getId());

		$this->assertSame('select(*) from(openregister_processing_purposes) where(eq(code, :p0)) executeQuery()', $this->sql(0));
		$this->assertSame('select(*) from(openregister_processing_purposes) where(eq(uuid, :p1)) executeQuery()', $this->sql(1));
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) where(eq(code, :p2)) executeQuery()', $this->sql(2));
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) where(eq(uuid, :p3)) executeQuery()', $this->sql(3));
		$this->assertSame(['c-1', 'u-2', 'c-5', 'u-6'], $this->boundValues());
	}//end testLookupsMatchTheirColumn()

	/**
	 * An unknown code or uuid answers null; a reference tries the code, then the uuid.
	 *
	 * @return void
	 */
	public function testReferenceFallsBackFromCodeToUuid(): void {
		$this->results[] = [];
		$this->results[] = [['id' => 8, 'uuid' => 'ref']];

		$this->assertSame(8, $this->activities->resolveReference('ref')?->getId());
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) where(eq(code, :p0)) executeQuery()', $this->sql(0));
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) where(eq(uuid, :p1)) executeQuery()', $this->sql(1));

		$this->assertNull($this->purposes->resolveReference('nothing'));
		$this->assertCount(4, $this->queries);
	}//end testReferenceFallsBackFromCodeToUuid()

	/**
	 * A reference found by code does not look at the uuid.
	 *
	 * @return void
	 */
	public function testReferenceFoundByCodeStops(): void {
		$this->results[] = [['id' => 9, 'code' => 'ref']];

		$this->assertSame(9, $this->purposes->resolveReference('ref')?->getId());
		$this->assertCount(1, $this->queries);
	}//end testReferenceFoundByCodeStops()

	/**
	 * findAll() orders and filters as each mapper always did.
	 *
	 * @return void
	 */
	public function testFindAllQueries(): void {
		$this->purposes->findAll();
		$this->purposes->findAll('active', 'org-1');
		$this->activities->findAll();
		$this->activities->findAll('org-2', 'published');

		$this->assertSame('select(*) from(openregister_processing_purposes) orderBy(code, ASC) executeQuery()', $this->sql(0));
		$this->assertSame(
			'select(*) from(openregister_processing_purposes) orderBy(code, ASC) andWhere(eq(status, :p0)) andWhere(eq(organisation_id, :p1)) executeQuery()',
			$this->sql(1)
		);
		$this->assertSame('select(*) from(openregister_verwerkingsactiviteiten) orderBy(name, ASC) executeQuery()', $this->sql(2));
		$this->assertSame(
			'select(*) from(openregister_verwerkingsactiviteiten) andWhere(eq(organisation_id, :p2)) andWhere(eq(status, :p3)) orderBy(name, ASC) executeQuery()',
			$this->sql(3)
		);
		$this->assertSame(['active', 'org-1', 'org-2', 'published'], $this->boundValues());
	}//end testFindAllQueries()

	/**
	 * A purpose insert mints a uuid and stamps created and updated, in that column order.
	 *
	 * @return void
	 */
	public function testPurposeInsertMintsUuidAndStamps(): void {
		$purpose = new ProcessingPurpose();
		$purpose->setCode('p-new');

		$saved = $this->purposes->insert($purpose);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertSame($saved->getCreated(), $saved->getUpdated());
		$this->assertStringStartsWith(
			'insert(openregister_processing_purposes) setValue(code, :p0) setValue(uuid, :p1) setValue(created, :p2) setValue(updated, :p3)',
			$this->sql()
		);
	}//end testPurposeInsertMintsUuidAndStamps()

	/**
	 * An insert keeps a supplied uuid and created stamp and replaces an empty uuid.
	 *
	 * @return void
	 */
	public function testPurposeInsertKeepsSuppliedUuidAndCreated(): void {
		$created = new DateTime('2026-03-01T00:00:00+00:00');
		$kept = new ProcessingPurpose();
		$kept->setUuid('p-kept');
		$kept->setCreated($created);
		$blank = new ProcessingPurpose();
		$blank->setUuid('');

		$this->purposes->insert($kept);
		$this->purposes->insert($blank);

		$this->assertSame('p-kept', $kept->getUuid());
		$this->assertSame($created, $kept->getCreated());
		$this->assertNotSame($created, $kept->getUpdated());
		$this->assertSame(36, strlen((string) $blank->getUuid()));
	}//end testPurposeInsertKeepsSuppliedUuidAndCreated()

	/**
	 * Updates restamp updated before writing, on both tables.
	 *
	 * @return void
	 */
	public function testUpdatesRestampUpdated(): void {
		$old = new DateTime('2020-01-01T00:00:00+00:00');
		$purpose = new ProcessingPurpose();
		$purpose->setId(7);
		$purpose->setUpdated($old);
		$purpose->resetUpdatedFields();
		$activity = $this->validActivity();
		$activity->setId(8);
		$activity->setUpdated($old);
		$activity->resetUpdatedFields();

		$this->assertNotSame($old, $this->purposes->update($purpose)->getUpdated());
		$this->assertNotSame($old, $this->activities->update($activity)->getUpdated());
		$this->assertStringStartsWith('update(openregister_processing_purposes) set(updated, :p0) where(eq(id, :p1))', $this->sql(0));
		$this->assertStringStartsWith('update(openregister_verwerkingsactiviteiten) set(updated, :p2) where(eq(id, :p3))', $this->sql(1));
	}//end testUpdatesRestampUpdated()

	/**
	 * A valid activity is accepted: uuid minted, status read as concept, stamped, in that column order.
	 *
	 * @return void
	 */
	public function testValidActivityIsAcceptedAndDefaulted(): void {
		$saved = $this->activities->insert($this->validActivity());

		$this->assertSame('concept', $saved->getStatus());
		$this->assertSame(36, strlen((string) $saved->getUuid()));
		$this->assertSame($saved->getCreated(), $saved->getUpdated());
		$this->assertStringStartsWith(
			'insert(openregister_verwerkingsactiviteiten) setValue(name, :p0) setValue(purpose, :p1) setValue(legal_basis, :p2) setValue(uuid, :p3) setValue(created, :p4) setValue(updated, :p5) executeStatement()',
			$this->sql()
		);
	}//end testValidActivityIsAcceptedAndDefaulted()

	/**
	 * An activity with a known status keeps it, on insert and on update.
	 *
	 * @return void
	 */
	public function testKnownStatusIsAccepted(): void {
		foreach (Verwerkingsactiviteit::STATUS_VOCABULARY as $status) {
			$activity = $this->validActivity();
			$activity->setStatus($status);
			$this->assertSame($status, $this->activities->insert($activity)->getStatus());
			$this->assertSame($status, $this->activities->update($activity)->getStatus());
		}

		foreach (Verwerkingsactiviteit::LEGAL_BASIS_VOCABULARY as $basis) {
			$activity = $this->validActivity();
			$activity->setLegalBasis($basis);
			$this->assertSame($basis, $this->activities->insert($activity)->getLegalBasis());
		}
	}//end testKnownStatusIsAccepted()

	/**
	 * The refusals, each with its message, on insert and on update, before any query.
	 *
	 * @return array<string, array{0: array<string, string|null>, 1: string}>
	 */
	public static function refusals(): array {
		$vocabulary = 'consent, contract, legal_obligation, vital_interests, public_task, legitimate_interest';
		return [
			'no name' => [['name' => null], 'Verwerkingsactiviteit MUST have a name (AVG Art 30 §1(a))'],
			'blank name' => [['name' => "  \t"], 'Verwerkingsactiviteit MUST have a name (AVG Art 30 §1(a))'],
			'no name before no purpose' => [['name' => '', 'purpose' => null], 'Verwerkingsactiviteit MUST have a name (AVG Art 30 §1(a))'],
			'no purpose' => [['purpose' => null], 'Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))'],
			'blank purpose' => [['purpose' => ' '], 'Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))'],
			'purpose before legal basis' => [['purpose' => '', 'legalBasis' => 'x'], 'Verwerkingsactiviteit MUST have a purpose (AVG Art 30 §1(b))'],
			'no legal basis' => [['legalBasis' => null], 'Invalid legalBasis ""; expected one of: ' . $vocabulary . ' (AVG Art 6)'],
			'unknown legal basis' => [['legalBasis' => 'whim'], 'Invalid legalBasis "whim"; expected one of: ' . $vocabulary . ' (AVG Art 6)'],
			'legal basis before status' => [['legalBasis' => 'whim', 'status' => 'bogus'], 'Invalid legalBasis "whim"; expected one of: ' . $vocabulary . ' (AVG Art 6)'],
			'unknown status' => [['status' => 'bogus'], 'Invalid status "bogus"; expected one of: concept, published, archived'],
		];
	}//end refusals()

	/**
	 * An invalid activity is refused on insert, without a query.
	 *
	 * @param array<string, string|null> $fields  The fields that make it invalid.
	 * @param string                     $message The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testInvalidActivityIsRefusedOnInsert(array $fields, string $message): void {
		$activity = $this->activityWith($fields);

		try {
			$this->activities->insert($activity);
			$this->fail('The activity was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $this->queries);
		$this->assertNull($activity->getUuid());
	}//end testInvalidActivityIsRefusedOnInsert()

	/**
	 * An invalid activity is refused on update, without a query or a new stamp.
	 *
	 * @param array<string, string|null> $fields  The fields that make it invalid.
	 * @param string                     $message The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testInvalidActivityIsRefusedOnUpdate(array $fields, string $message): void {
		$activity = $this->activityWith($fields);
		$activity->setId(3);

		try {
			$this->activities->update($activity);
			$this->fail('The activity was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $this->queries);
		$this->assertNull($activity->getUpdated());
	}//end testInvalidActivityIsRefusedOnUpdate()

	/**
	 * A valid activity.
	 *
	 * @return Verwerkingsactiviteit
	 */
	private function validActivity(): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setName('Payroll');
		$activity->setPurpose('Paying staff');
		$activity->setLegalBasis('contract');

		return $activity;
	}//end validActivity()

	/**
	 * A valid activity with some fields replaced.
	 *
	 * @param array<string, string|null> $fields The replaced fields.
	 *
	 * @return Verwerkingsactiviteit
	 */
	private function activityWith(array $fields): Verwerkingsactiviteit {
		$activity = $this->validActivity();
		foreach ($fields as $field => $value) {
			$activity->{'set' . ucfirst($field)}($value);
		}

		return $activity;
	}//end activityWith()
}//end class
