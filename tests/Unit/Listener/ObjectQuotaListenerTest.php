<?php

/**
 * OpenRegister per-organisation object quota tests
 *
 * A schema may cap how many of its objects one organisation holds
 * (`x-openregister-quota: {"perOrganisation": N}`). A create past the cap is
 * refused; an update never counts; the count is the organisation's real total,
 * not what the creating user happens to be allowed to read. hermiq's schedule
 * quota is the first consumer (gate 23, DECISIONS row 62).
 *
 * Driven through the real ObjectQuotaListener over the real ObjectQuotaService,
 * with the MagicMapper count doubled at the query it receives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ObjectQuotaListener;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\ObjectQuotaListener
 */
class ObjectQuotaListenerTest extends TestCase {
	private const ORG = '11111111-1111-1111-1111-111111111111';

	private MagicMapper&MockObject $objects;

	private SchemaMapper&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every count query the mapper received, with its flags.
	 *
	 * @var array<int, array{query: array<string, mixed>, rbac: bool, multitenancy: bool}>
	 */
	private array $counts = [];

	private int $existing = 0;

	protected function setUp(): void {
		$this->objects = $this->createMock(MagicMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objects->method('searchObjects')->willReturnCallback(
			function (
				array $query = [],
				?string $_activeOrgUuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array|int {
				$this->counts[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return $this->existing;
			}
		);
	}//end setUp()

	/**
	 * A schema with the given configuration, resolved by the mapper.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 */
	private function schemaWith(array $configuration): Schema {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('schedule');
		$schema->setConfiguration($configuration);
		$this->schemas->method('find')->willReturn($schema);
		return $schema;
	}//end schemaWith()

	/**
	 * A new object of schema 42 in register 7, in the given organisation.
	 */
	private function newObject(?string $organisation = self::ORG): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('22222222-2222-2222-2222-222222222222');
		$object->setRegister('7');
		$object->setSchema('42');
		$object->setOrganisation($organisation);
		$object->setObject(['name' => 'nightly digest']);
		return $object;
	}//end newObject()

	private function service(): ObjectQuotaService {
		return new ObjectQuotaService($this->objects);
	}//end service()

	private function listener(): ObjectQuotaListener {
		return new ObjectQuotaListener($this->service(), $this->schemas, $this->logger);
	}//end listener()

	/**
	 * Run a create through the listener and return the event.
	 */
	private function create(?string $organisation = self::ORG): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent($this->newObject(organisation: $organisation));
		$this->listener()->handle($event);
		return $event;
	}//end create()

	public function testCreateAtTheLimitIsRefused(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 2]]);
		$this->existing = 2;

		$event = $this->create();

		$this->assertTrue($event->isPropagationStopped(), 'A create past the quota must be refused.');
		$errors = $event->getErrors();
		$this->assertSame(ObjectQuotaListener::ERROR_CODE, $errors['code'] ?? null);
		$this->assertSame(2, $errors['limit'] ?? null);
		$this->assertSame(2, $errors['count'] ?? null);
		$this->assertStringContainsString('2', (string)($errors['message'] ?? ''));
	}//end testCreateAtTheLimitIsRefused()

	public function testCreateBelowTheLimitIsAllowed(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 2]]);
		$this->existing = 1;

		$this->assertFalse($this->create()->isPropagationStopped());
	}//end testCreateBelowTheLimitIsAllowed()

	/**
	 * The count is the organisation's real total for this register and schema,
	 * not what the creating user may read: RBAC and multitenancy are off and
	 * the organisation is filtered explicitly.
	 */
	public function testTheCountIsUnrestrictedAndScopedToTheOrganisation(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 5]]);

		$this->create();

		$this->assertCount(1, $this->counts);
		$this->assertFalse($this->counts[0]['rbac']);
		$this->assertFalse($this->counts[0]['multitenancy']);
		$this->assertSame(
			['register' => 7, 'schema' => 42, 'organisation' => self::ORG],
			$this->counts[0]['query']['@self'] ?? null
		);
		$this->assertTrue($this->counts[0]['query']['_count'] ?? false);
	}//end testTheCountIsUnrestrictedAndScopedToTheOrganisation()

	public function testASchemaWithoutAQuotaIsNeverCounted(): void {
		$this->schemaWith([]);

		$this->assertFalse($this->create()->isPropagationStopped());
		$this->assertSame([], $this->counts);
	}//end testASchemaWithoutAQuotaIsNeverCounted()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidLimits(): array {
		return [
			'zero' => [0],
			'negative' => [-3],
			'string' => ['10'],
			'float' => [2.5],
			'missing key' => [null],
		];
	}//end invalidLimits()

	/**
	 * @dataProvider invalidLimits
	 */
	public function testAnInvalidLimitIsNoQuota(mixed $limit): void {
		$annotation = [];
		if ($limit !== null) {
			$annotation['perOrganisation'] = $limit;
		}

		$this->schemaWith(['x-openregister-quota' => $annotation]);
		$this->existing = 1000;

		$this->assertFalse($this->create()->isPropagationStopped());
		$this->assertSame([], $this->counts);
	}//end testAnInvalidLimitIsNoQuota()

	/**
	 * An object outside any organisation is not subject to a per-organisation quota.
	 */
	public function testAnObjectWithoutAnOrganisationIsNotCounted(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 1]]);
		$this->existing = 50;

		$this->assertFalse($this->create(organisation: null)->isPropagationStopped());
		$this->assertSame([], $this->counts);
	}//end testAnObjectWithoutAnOrganisationIsNotCounted()

	public function testAnUpdateNeverCounts(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 1]]);
		$this->existing = 50;

		$event = new ObjectUpdatingEvent($this->newObject(), $this->newObject());
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $this->counts);
	}//end testAnUpdateNeverCounts()

	/**
	 * A count that cannot be made does not block the write, and says so.
	 */
	public function testAFailingCountAllowsTheCreateAndWarns(): void {
		$this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 1]]);
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willThrowException(new RuntimeException('table gone'));
		$this->logger->expects($this->once())->method('warning');

		$event = new ObjectCreatingEvent($this->newObject());
		(new ObjectQuotaListener(new ObjectQuotaService($objects), $this->schemas, $this->logger))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailingCountAllowsTheCreateAndWarns()

	/**
	 * The status read an app shows its administrators.
	 */
	public function testStatusReportsCountLimitAndAtLimit(): void {
		$schema = $this->schemaWith(['x-openregister-quota' => ['perOrganisation' => 3]]);
		$this->existing = 3;

		$this->assertSame(
			['count' => 3, 'limit' => 3, 'atLimit' => true],
			$this->service()->status(registerId: 7, schema: $schema, organisationUuid: self::ORG)
		);
	}//end testStatusReportsCountLimitAndAtLimit()

	public function testStatusWithoutAQuotaHasNoLimit(): void {
		$schema = $this->schemaWith([]);
		$this->existing = 3;

		$this->assertSame(
			['count' => 3, 'limit' => null, 'atLimit' => false],
			$this->service()->status(registerId: 7, schema: $schema, organisationUuid: self::ORG)
		);
	}//end testStatusWithoutAQuotaHasNoLimit()

	/**
	 * The annotation survives a schema save: an x-openregister-* key off the
	 * vocabulary is dropped by Schema::setConfiguration() and the quota would
	 * never fire, which is what this test caught first.
	 */
	public function testTheAnnotationSurvivesTheSchemaConfigurationAllowList(): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-quota' => ['perOrganisation' => 4]]);

		$this->assertSame(['perOrganisation' => 4], $schema->getConfiguration()['x-openregister-quota'] ?? null);
		$this->assertSame(4, $this->service()->limitFor(schema: $schema));
	}//end testTheAnnotationSurvivesTheSchemaConfigurationAllowList()

	/**
	 * Wired from the caller: the create pipeline dispatches ObjectCreatingEvent,
	 * and the listener is registered for it.
	 */
	public function testTheListenerIsSubscribedToObjectCreatingEvent(): void {
		$application = file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertIsString($application);
		$this->assertStringContainsString(
			'registerEventListener(ObjectCreatingEvent::class, ObjectQuotaListener::class)',
			$application
		);
	}//end testTheListenerIsSubscribedToObjectCreatingEvent()
}//end class
