<?php

declare(strict_types=1);

/**
 * ArchivalRetentionGuard unit tests.
 *
 * The guard is the fifth consumer of Schema::hasArchivalAnnotation(), not a
 * fifth definition of the rule. These tests build REAL Schema entities and let
 * the production predicate decide, so a guard that grew its own opinion of what
 * "archival" means would fail here rather than agree with itself.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ArchivalRetentionGuard.
 */
class ArchivalRetentionGuardTest extends TestCase {

	/**
	 * Build a guard whose mapper answers with real Schema entities.
	 *
	 * @param array<string, bool> $schemas Map of schema identifier to "is archival".
	 *
	 * @return ArchivalRetentionGuard
	 */
	private function guard(array $schemas): ArchivalRetentionGuard {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function ($id) use ($schemas): Schema {
				$key = (string)$id;
				if (array_key_exists($key, $schemas) === false) {
					throw new DoesNotExistException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug($key);
				$configuration = [];
				if ($schemas[$key] === true) {
					$configuration['x-openregister-archival'] = ['retention' => ['default' => 'P10Y']];
				}

				$schema->setConfiguration($configuration);

				return $schema;
			}
		);

		return new ArchivalRetentionGuard($mapper, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * Build a record on a given schema.
	 *
	 * @param string $uuid Record uuid.
	 * @param string $schema Schema identifier.
	 *
	 * @return ObjectEntity
	 */
	private function record(string $uuid, string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('reg-1');
		$object->setSchema($schema);

		return $object;
	}//end record()

	/**
	 * A record on an ordinary schema may be erased: no refusal.
	 *
	 * @return void
	 */
	public function testAnOrdinaryRecordIsNotRefused(): void {
		$guard = $this->guard(['contact' => false]);

		$this->assertNull($guard->erasureRefusal($this->record('u1', 'contact')));
	}//end testAnOrdinaryRecordIsNotRefused()

	/**
	 * An archival record is refused, and the refusal says why and what to do.
	 *
	 * @return void
	 */
	public function testAnArchivalRecordIsRefusedWithAGroundAndAnAction(): void {
		$guard = $this->guard(['besluit' => true]);

		$refusal = $guard->erasureRefusal($this->record('u2', 'besluit'));

		$this->assertNotNull($refusal);
		$this->assertSame('u2', $refusal['uuid']);
		$this->assertSame('besluit', $refusal['schema']);
		$this->assertSame('reg-1', $refusal['register']);
		$this->assertSame(ArchivalRetentionGuard::GROUND_ARCHIVAL, $refusal['ground']);
		$this->assertSame(ArchivalRetentionGuard::CONTEXT_ERASURE, $refusal['operation']);
		$this->assertSame(
			'The law requires us to keep this record, so we did not erase it.',
			$refusal['message']
		);
		$this->assertSame('GDPR art. 17(3)(b) and the Archiefwet.', $refusal['basis']);
		$this->assertSame(
			'Name this record in your answer to the requester. '
			. 'A records officer decides when it may be destroyed.',
			$refusal['action']
		);
	}//end testAnArchivalRecordIsRefusedWithAGroundAndAnAction()

	/**
	 * `"archival": true` is a typo, not an annotation, so it arms nothing.
	 *
	 * Pinned here because the guard must inherit that judgement from the
	 * predicate rather than making its own. A guard that treated any truthy
	 * value as archival would refuse erasures the four HTTP doors allow.
	 *
	 * @return void
	 */
	public function testANonArrayAnnotationDoesNotRefuse(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function (): Schema {
				$schema = new Schema();
				$schema->setSlug('typo');
				$schema->setConfiguration(['x-openregister-archival' => true]);

				return $schema;
			}
		);
		$guard = new ArchivalRetentionGuard($mapper, $this->createMock(LoggerInterface::class));

		$this->assertNull($guard->erasureRefusal($this->record('u3', 'typo')));
	}//end testANonArrayAnnotationDoesNotRefuse()

	/**
	 * FAILS CLOSED: an unresolvable schema is refused, under its own ground.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsRefusedUnderItsOwnGround(): void {
		$guard = $this->guard([]);

		$refusal = $guard->erasureRefusal($this->record('u4', 'gone'));

		$this->assertNotNull($refusal);
		$this->assertSame(ArchivalRetentionGuard::GROUND_UNRESOLVED, $refusal['ground']);
		$this->assertNotSame(
			ArchivalRetentionGuard::GROUND_ARCHIVAL,
			$refusal['ground'],
			'"We could not tell" must not be reported as a legal obligation.'
		);
		$this->assertStringContainsString('repair the schema', $refusal['action']);
	}//end testAnUnresolvableSchemaIsRefusedUnderItsOwnGround()

	/**
	 * A record with no schema at all is refused rather than erased.
	 *
	 * @return void
	 */
	public function testARecordWithNoSchemaIsRefused(): void {
		$guard = $this->guard(['contact' => false]);
		$object = new ObjectEntity();
		$object->setUuid('u5');

		$refusal = $guard->erasureRefusal($object);

		$this->assertNotNull($refusal);
		$this->assertSame(ArchivalRetentionGuard::GROUND_UNRESOLVED, $refusal['ground']);
	}//end testARecordWithNoSchemaIsRefused()

	/**
	 * The cascade wording differs from the erasure wording, because the reader
	 * is answering a different question: the parent is gone and this record is not.
	 *
	 * @return void
	 */
	public function testTheCascadeRefusalSpeaksAboutTheParent(): void {
		$guard = $this->guard(['besluit' => true]);

		$refusal = $guard->cascadeRefusal('child-1', 'besluit');

		$this->assertNotNull($refusal);
		$this->assertSame(ArchivalRetentionGuard::CONTEXT_CASCADE, $refusal['operation']);
		$this->assertSame(
			'The law requires us to keep this record. Its parent is gone, this record stays.',
			$refusal['message']
		);
		$this->assertArrayNotHasKey('register', $refusal);
	}//end testTheCascadeRefusalSpeaksAboutTheParent()

	/**
	 * Repeated questions about one schema hit the mapper once.
	 *
	 * A sweep asks per record, so an unmemoised guard would turn one retention
	 * pass into one schema query per row.
	 *
	 * @return void
	 */
	public function testTheSchemaLookupIsMemoisedPerIdentifier(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->once())
			->method('find')
			->willReturnCallback(
				static function (): Schema {
					$schema = new Schema();
					$schema->setSlug('besluit');
					$schema->setConfiguration(['x-openregister-archival' => ['retention' => []]]);

					return $schema;
				}
			);
		$guard = new ArchivalRetentionGuard($mapper, $this->createMock(LoggerInterface::class));

		$this->assertNotNull($guard->erasureRefusal($this->record('a', 'besluit')));
		$this->assertNotNull($guard->erasureRefusal($this->record('b', 'besluit')));
		$this->assertNotNull($guard->cascadeRefusal('c', 'besluit'));
	}//end testTheSchemaLookupIsMemoisedPerIdentifier()

	/**
	 * A schema that could not be resolved is not re-queried either.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsAlsoMemoised(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->once())
			->method('find')
			->willThrowException(new DoesNotExistException('gone'));
		$guard = new ArchivalRetentionGuard($mapper, $this->createMock(LoggerInterface::class));

		$this->assertNotNull($guard->erasureRefusal($this->record('a', 'gone')));
		$this->assertNotNull($guard->erasureRefusal($this->record('b', 'gone')));
	}//end testAnUnresolvableSchemaIsAlsoMemoised()

	/**
	 * THE SCHEMA READ IS UNSCOPED, and it has to be.
	 *
	 * Every caller runs either as a cron with no session or as a privacy officer
	 * sweeping across tenants. An RBAC- or tenant-scoped read would answer "not
	 * found" for a schema that plainly exists, and because the guard fails
	 * closed, that miss would refuse every row in the run and stop the sweep
	 * without a single error.
	 *
	 * @return void
	 */
	public function testTheSchemaIsReadWithoutRbacOrTenantScoping(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('besluit'),
				$this->anything(),
				$this->isFalse(),
				$this->isFalse()
			)
			->willReturn(new Schema());
		$guard = new ArchivalRetentionGuard($mapper, $this->createMock(LoggerInterface::class));

		$guard->erasureRefusal($this->record('a', 'besluit'));
	}//end testTheSchemaIsReadWithoutRbacOrTenantScoping()
}//end class
