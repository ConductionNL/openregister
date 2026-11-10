<?php

/**
 * RecordTemplateMapper finds visible templates and stamps what it stores.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\RecordTemplate;
use OCA\OpenRegister\Db\RecordTemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RecordTemplateMapper
 * @covers \OCA\OpenRegister\Db\RecordTemplate
 * @uses   \OCA\OpenRegister\Db\ObjectReadState
 */
class RecordTemplateMapperBehaviourTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over the recording query builder.
	 *
	 * @return RecordTemplateMapper
	 */
	private function mapper(): RecordTemplateMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new RecordTemplateMapper($db);
	}//end mapper()

	/**
	 * The whole query log as one string.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * findByUuid returns the template with its values.
	 *
	 * @return void
	 */
	public function testFindByUuidReturnsTheTemplate(): void {
		$this->queuedRows = [[['id' => 1, 'uuid' => 't-1', 'name' => 'Intake', 'template_values' => '{"status":"new"}']]];

		$template = $this->mapper()->findByUuid('t-1');

		$this->assertSame('Intake', $template->getName());
		$this->assertSame(['status' => 'new'], $template->getValues());
		$this->assertStringContainsString('eq(uuid, :p1', $this->log());
		$this->assertStringContainsString('param :p1 = t-1, 2', $this->log());
	}//end testFindByUuidReturnsTheTemplate()

	/**
	 * findByUuid of an unknown template throws.
	 *
	 * @return void
	 */
	public function testFindByUuidOfAnUnknownTemplateThrows(): void {
		$this->queuedRows = [[]];

		$this->expectException(DoesNotExistException::class);

		$this->mapper()->findByUuid('missing');
	}//end testFindByUuidOfAnUnknownTemplateThrows()

	/**
	 * Candidates are owned, public or shared, newest first, narrowed to register and schema.
	 *
	 * @return void
	 */
	public function testFindCandidatesNarrowsToRegisterAndSchema(): void {
		$this->queuedRows = [[['id' => 1], ['id' => 2]]];

		$rows = $this->mapper()->findCandidates('anna', ListQuery::unbounded(filters: ['register' => 'reg', 'schema' => 'sch']));

		$this->assertCount(2, $rows);
		$log = $this->log();
		$this->assertStringContainsString('eq(owner, :p1', $log);
		$this->assertStringContainsString('eq(is_public, :p2', $log);
		$this->assertStringContainsString('isNotNull(shared_with', $log);
		$this->assertStringContainsString('orderBy(created, DESC)', $log);
		$this->assertStringContainsString('eq(register, :p3', $log);
		$this->assertStringContainsString('eq(schema, :p4', $log);
	}//end testFindCandidatesNarrowsToRegisterAndSchema()

	/**
	 * Without register or schema the candidates are not narrowed.
	 *
	 * @return void
	 */
	public function testFindCandidatesWithoutScopeIsNotNarrowed(): void {
		$this->queuedRows = [[]];

		$this->assertSame([], $this->mapper()->findCandidates('anna', ListQuery::unbounded(filters: ['register' => '', 'schema' => null])));
		$log = $this->log();
		$this->assertStringNotContainsString('eq(register', $log);
		$this->assertStringNotContainsString('eq(schema', $log);
	}//end testFindCandidatesWithoutScopeIsNotNarrowed()

	/**
	 * A new template gets a uuid and both timestamps.
	 *
	 * @return void
	 */
	public function testInsertStampsUuidAndTimes(): void {
		$template = new RecordTemplate();
		$template->setName('Intake');

		$saved = $this->mapper()->insert($template);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$saved->getUuid());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertInstanceOf(DateTime::class, $saved->getUpdated());
		$this->assertStringContainsString('insert(openregister_record_templates', $this->log());
	}//end testInsertStampsUuidAndTimes()

	/**
	 * A given uuid is kept on insert.
	 *
	 * @return void
	 */
	public function testInsertKeepsAGivenUuid(): void {
		$template = new RecordTemplate();
		$template->setUuid('given');

		$this->assertSame('given', $this->mapper()->insert($template)->getUuid());
	}//end testInsertKeepsAGivenUuid()

	/**
	 * update stamps updated and leaves created alone.
	 *
	 * @return void
	 */
	public function testUpdateStampsUpdatedOnly(): void {
		$created = new DateTime('2025-01-01T00:00:00+00:00');
		$template = new RecordTemplate();
		$template->setId(3);
		$template->setCreated($created);
		$template->resetUpdatedFields();

		$saved = $this->mapper()->update($template);

		$this->assertSame($created, $saved->getCreated());
		$this->assertGreaterThan($created, $saved->getUpdated());
		$this->assertStringContainsString('update(openregister_record_templates', $this->log());
	}//end testUpdateStampsUpdatedOnly()

	/**
	 * Any other entity is refused on insert, before a query is built.
	 *
	 * @return void
	 */
	public function testInsertRefusesAnotherEntity(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('RecordTemplateMapper stores record templates only.');

		try {
			$this->mapper()->insert(new ObjectReadState());
		} finally {
			$this->assertSame([], $this->queryLog);
		}
	}//end testInsertRefusesAnotherEntity()

	/**
	 * Any other entity is refused on update.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAnotherEntity(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->mapper()->update(new ObjectReadState());
	}//end testUpdateRefusesAnotherEntity()

	/**
	 * The access level is a transient, settable property.
	 *
	 * @return void
	 */
	public function testAccessIsTransient(): void {
		$template = new RecordTemplate();
		$this->assertNull($template->getAccess());

		$template->setAccess('owner');

		$this->assertSame('owner', $template->getAccess());
		$this->assertSame([], $template->getUpdatedFields());
	}//end testAccessIsTransient()
}//end class
