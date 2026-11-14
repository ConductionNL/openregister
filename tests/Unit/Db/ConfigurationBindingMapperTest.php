<?php

/**
 * Unit tests for ConfigurationBindingMapper: its reads by subject, bundle
 * and in full, and its save and remove writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ConfigurationBinding;
use OCA\OpenRegister\Db\ConfigurationBindingMapper;
use OCA\OpenRegister\Db\HydrateBySettersTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the configuration binding mapper.
 */
#[CoversClass(ConfigurationBindingMapper::class)]
#[UsesClass(ConfigurationBinding::class)]
#[UsesClass(HydrateBySettersTrait::class)]
class ConfigurationBindingMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over a connection that hands out recording builders.
	 *
	 * @return ConfigurationBindingMapper The mapper.
	 */
	private function mapper(): ConfigurationBindingMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		return new ConfigurationBindingMapper($db);
	}//end mapper()

	/**
	 * A stored binding.
	 *
	 * @return ConfigurationBinding The binding.
	 */
	private function stored(): ConfigurationBinding {
		$binding = new ConfigurationBinding();
		$binding->setId(4);
		$binding->setBundle('zaken');
		$binding->setSubject('schema:zaak');
		$binding->setUpdated(new DateTime('2020-01-01T00:00:00+00:00'));
		$binding->resetUpdatedFields();
		return $binding;
	}//end stored()

	/**
	 * A subject reads its one binding, or null when it has none.
	 *
	 * @return void
	 */
	public function testFindBySubject(): void {
		$this->queuedRows[] = [['id' => 4, 'bundle' => 'zaken', 'subject' => 'schema:zaak']];
		$mapper = $this->mapper();

		$binding = $mapper->findBySubject('schema:zaak');
		$this->assertSame('zaken', $binding?->getBundle());
		$this->assertContains('#1 param :p1 = schema:zaak, 2', $this->queryLog);
		$this->assertContains('#1 where(eq(subject, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 setMaxResults(1)', $this->queryLog);

		$this->assertNull($mapper->findBySubject('schema:none'));
	}//end testFindBySubject()

	/**
	 * A bundle's bindings come ordered by subject; all bindings by bundle, then subject.
	 *
	 * @return void
	 */
	public function testListsAreOrdered(): void {
		$this->queuedRows[] = [['id' => 1, 'bundle' => 'zaken', 'subject' => 'a'], ['id' => 2, 'bundle' => 'zaken', 'subject' => 'b']];
		$mapper = $this->mapper();

		$this->assertSame(['a', 'b'], array_map(static fn (ConfigurationBinding $b): string => (string) $b->getSubject(), $mapper->findByBundle('zaken')));
		$this->assertContains('#1 where(eq(bundle, :p1, NULL))', $this->queryLog);
		$this->assertContains('#1 orderBy(subject, ASC)', $this->queryLog);

		$this->assertSame([], $mapper->findAllBindings());
		$this->assertContains('#2 orderBy(bundle, ASC)', $this->queryLog);
		$this->assertContains('#2 addOrderBy(subject, ASC)', $this->queryLog);
	}//end testListsAreOrdered()

	/**
	 * Saving stamps a fresh updated time and updates by id.
	 *
	 * @return void
	 */
	public function testSaveStampsUpdated(): void {
		$before  = new DateTime('-1 second');
		$binding = $this->mapper()->save($this->stored());

		$this->assertGreaterThanOrEqual($before, $binding->getUpdated());
		$this->assertContains('#1 update(openregister_config_bindings, NULL)', $this->queryLog);
		$this->assertContains('#1 executeStatement()', $this->queryLog);
	}//end testSaveStampsUpdated()

	/**
	 * Removing deletes by id.
	 *
	 * @return void
	 */
	public function testRemoveDeletesById(): void {
		$binding = $this->mapper()->remove($this->stored());

		$this->assertSame(4, $binding->getId());
		$this->assertContains('#1 delete(openregister_config_bindings, NULL)', $this->queryLog);
		$this->assertContains('#1 param :p1 = 4, 1', $this->queryLog);
	}//end testRemoveDeletesById()

	/**
	 * A new binding gets a uuid and both timestamps, and is inserted.
	 *
	 * @return void
	 */
	public function testCreateFromArrayFillsTheDefaults(): void {
		$binding = $this->mapper()->createFromArray(['bundle' => 'zaken', 'subject' => 'schema:zaak', 'subjectType' => 'schema']);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $binding->getUuid());
		$this->assertSame('zaken', $binding->getBundle());
		$this->assertSame('schema', $binding->getSubjectType());
		$this->assertSame($binding->getCreated(), $binding->getUpdated());
		$this->assertContains('#1 insert(openregister_config_bindings)', $this->queryLog);
	}//end testCreateFromArrayFillsTheDefaults()

	/**
	 * A given uuid and creation time are kept.
	 *
	 * @return void
	 */
	public function testCreateFromArrayKeepsGivenValues(): void {
		$created = new DateTime('2020-01-01T00:00:00+00:00');
		$binding = $this->mapper()->createFromArray(['uuid' => 'fixed-uuid', 'created' => $created, 'bundle' => 'zaken']);

		$this->assertSame('fixed-uuid', $binding->getUuid());
		$this->assertSame($created, $binding->getCreated());
		$this->assertNotSame($created, $binding->getUpdated());
	}//end testCreateFromArrayKeepsGivenValues()
}//end class
