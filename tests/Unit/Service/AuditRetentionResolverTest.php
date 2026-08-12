<?php

/**
 * AuditRetentionResolver policy tests (or#2265).
 *
 * The defect being fixed: every audit row was stamped `+30 days`, so the
 * evidence of a retention decision was destroyed long before the decision
 * expired — 311 of 330 soft-deleted procest cases had lost their delete audit
 * row, and all 258,240 rows carrying an expiry carried exactly a 30-day one.
 *
 * The two controls the fix REQUIRES, and which this file exists to provide,
 * are deliberately opposed:
 *
 *   (a) {@see testLongRetentionRowSurvivesFarPastThirtyDays} — a row whose
 *       object has a long retention must OUTLIVE 30 days.
 *   (b) {@see testGenuinelyElapsedRetentionStillExpires} — a row whose object's
 *       retention has genuinely elapsed must STILL EXPIRE.
 *
 * Without (b), (a) alone would be satisfied by a resolver that simply never
 * expires anything — trading a purge bug for an unbounded-growth bug. Both
 * assert against the SAME method, so neither can pass by the code doing
 * nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class AuditRetentionResolverTest extends TestCase {

	/**
	 * Schema returned by the container-backed SchemaMapper, or null to make
	 * the lookup fail (the "schema not resolvable" path).
	 *
	 * @var Schema|null
	 */
	private ?Schema $schema = null;

	/**
	 * Build a resolver whose SchemaMapper returns {@see self::$schema}.
	 */
	private function makeResolver(): AuditRetentionResolver {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function () {
				if ($this->schema === null) {
					throw new \RuntimeException('schema not found');
				}

				return $this->schema;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($schemaMapper): object {
				if ($class === SchemaMapper::class) {
					return $schemaMapper;
				}

				throw new \RuntimeException('not registered: ' . $class);
			}
		);

		return new AuditRetentionResolver($container, new NullLogger());
	}//end makeResolver()

	/**
	 * Build an object carrying the given retention metadata.
	 *
	 * @param array $retention The `retention` column contents.
	 */
	private function makeObject(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-under-test');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setRetention($retention);

		return $object;
	}//end makeObject()

	// -------------------------------------------------------------------------
	// POSITIVE CONTROL (a) — long retention SURVIVES past 30 days
	// -------------------------------------------------------------------------

	/**
	 * A row describing an object kept 20 years must not be purgeable at 30 days.
	 *
	 * The clock is not waited on; the resolved expiry is compared against the
	 * 30-day horizon directly, which is the same comparison
	 * `clearLogs()` makes (`expires < NOW()`).
	 */
	public function testLongRetentionRowSurvivesFarPastThirtyDays(): void {
		$createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

		$object = $this->makeObject(
			[
				'archiefnominatie' => 'vernietigen',
				'bewaartermijn' => 'P20Y',
				'archiefactiedatum' => '2046-01-01',
			]
		);

		$resolved = $this->makeResolver()->resolve(object: $object, createdAt: $createdAt);

		$this->assertNotNull($resolved['expires'], 'A 20-year retention must produce a finite, far-future expiry.');
		$this->assertSame('object.archiefactiedatum', $resolved['source']);

		// The row must still be alive at the OLD 30-day horizon...
		$thirtyDays = $createdAt->add(new DateInterval('P30D'));
		$this->assertGreaterThan(
			$thirtyDays,
			$resolved['expires'],
			'Row expired at or before 30 days — this is exactly the or#2265 defect.'
		);

		// ...and specifically alive ~20 years out, not merely 31 days.
		$this->assertGreaterThan($createdAt->add(new DateInterval('P19Y')), $resolved['expires']);
	}//end testLongRetentionRowSurvivesFarPastThirtyDays()

	// -------------------------------------------------------------------------
	// POSITIVE CONTROL (b) — genuinely elapsed retention STILL EXPIRES
	// -------------------------------------------------------------------------

	/**
	 * A row whose object's retention genuinely elapsed must still be purgeable.
	 *
	 * The row is dated two years ago and its object's `archiefactiedatum` one
	 * year ago, so the 30-day FLOOR (created + P30D, i.e. two years ago) does
	 * not bind, and the resolved expiry lands in the past — which is what
	 * `clearLogs()`'s `expires < NOW()` predicate selects. Without this
	 * control, control (a) would also pass against a resolver that never
	 * expires anything.
	 */
	public function testGenuinelyElapsedRetentionStillExpires(): void {
		$createdAt = (new DateTimeImmutable('now'))->sub(new DateInterval('P2Y'));
		$elapsed = (new DateTimeImmutable('now'))->sub(new DateInterval('P1Y'));

		$object = $this->makeObject(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefactiedatum' => $elapsed->format('Y-m-d'),
			]
		);

		$resolved = $this->makeResolver()->resolve(object: $object, createdAt: $createdAt);

		$this->assertNotNull($resolved['expires'], 'An elapsed retention must NOT become indefinite.');
		$this->assertSame('object.archiefactiedatum', $resolved['source']);
		$this->assertLessThan(
			new DateTimeImmutable('now'),
			$resolved['expires'],
			'Expiry did not land in the past — the purge bug has been replaced by unbounded growth.'
		);
	}//end testGenuinelyElapsedRetentionStillExpires()

	// -------------------------------------------------------------------------
	// The floor — a row must never be born already expired
	// -------------------------------------------------------------------------

	/**
	 * An object already past its destruction date must not produce an audit row
	 * that the next hourly cron purges immediately.
	 *
	 * This is the reason the 30-day constant survives with its sign reversed:
	 * it used to be the longest an audit row could live, and is now the
	 * shortest.
	 */
	public function testFloorPreventsRowsThatAreBornExpired(): void {
		$createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

		// Destruction date TWO YEARS BEFORE the audit row is written.
		$object = $this->makeObject(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefactiedatum' => '2024-01-01',
			]
		);

		$resolved = $this->makeResolver()->resolve(object: $object, createdAt: $createdAt);

		$this->assertSame(
			$createdAt->add(new DateInterval('P30D'))->format('c'),
			$resolved['expires']->format('c'),
			'The floor did not bind; the row describing this action would be purged on the next sweep.'
		);
		$this->assertSame('object.archiefactiedatum+floor', $resolved['source']);
	}//end testFloorPreventsRowsThatAreBornExpired()

	// -------------------------------------------------------------------------
	// Indefinite-retention branches
	// -------------------------------------------------------------------------

	/**
	 * An active legal hold makes the audit row indefinite.
	 */
	public function testLegalHoldRetainsIndefinitely(): void {
		$object = $this->makeObject(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefactiedatum' => '2024-01-01',
				'legalHold' => ['active' => true, 'reason' => 'litigation'],
			]
		);

		$resolved = $this->makeResolver()->resolve(
			object: $object,
			createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertNull($resolved['expires']);
		$this->assertSame('legal-hold:indefinite', $resolved['source']);
	}//end testLegalHoldRetainsIndefinitely()

	/**
	 * `bewaren` means permanent preservation, so the `archiefactiedatum` on
	 * such an object is a TRANSFER date and must not be read as an expiry.
	 */
	public function testBewarenIsNeverExpiredEvenWithAnArchiefactiedatum(): void {
		$object = $this->makeObject(
			[
				'archiefnominatie' => 'bewaren',
				'archiefactiedatum' => '2024-01-01',
			]
		);

		$resolved = $this->makeResolver()->resolve(
			object: $object,
			createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertNull($resolved['expires']);
		$this->assertSame('archiefnominatie=bewaren:indefinite', $resolved['source']);
	}//end testBewarenIsNeverExpiredEvenWithAnArchiefactiedatum()

	/**
	 * An undetermined nomination must fail TOWARDS keeping: a record whose
	 * retention has not been decided cannot lawfully be destroyed, and neither
	 * can its audit trail.
	 */
	public function testUndeterminedNominationRetainsIndefinitely(): void {
		$resolved = $this->makeResolver()->resolve(
			object: $this->makeObject(['archiefnominatie' => 'nog_niet_bepaald']),
			createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertNull($resolved['expires']);
		$this->assertSame('archiefnominatie=nog_niet_bepaald:indefinite', $resolved['source']);
	}//end testUndeterminedNominationRetainsIndefinitely()

	/**
	 * THE DOCUMENTED DEFAULT: an object with no retention policy anywhere, and
	 * a schema that resolves to nothing, is retained INDEFINITELY — never the
	 * old 30 days.
	 */
	public function testNoRetentionPolicyAnywhereRetainsIndefinitely(): void {
		$this->schema = null;

		$resolved = $this->makeResolver()->resolve(
			object: $this->makeObject([]),
			createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertNull($resolved['expires']);
		$this->assertSame(AuditRetentionResolver::SOURCE_NO_POLICY, $resolved['source']);
	}//end testNoRetentionPolicyAnywhereRetainsIndefinitely()

	// -------------------------------------------------------------------------
	// Schema-level fallbacks
	// -------------------------------------------------------------------------

	/**
	 * With nothing on the object, the schema's archive block supplies the term.
	 */
	public function testSchemaArchiveBewaartermijnIsUsedWhenObjectHasNone(): void {
		$this->schema = new Schema();
		$this->schema->setArchive(['enabled' => true, 'defaultBewaartermijn' => 'P7Y']);

		$createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
		$resolved = $this->makeResolver()->resolve(object: $this->makeObject([]), createdAt: $createdAt);

		$this->assertSame('schema.archive.defaultBewaartermijn', $resolved['source']);
		$this->assertSame(
			$createdAt->add(new DateInterval('P7Y'))->format('c'),
			$resolved['expires']->format('c')
		);
	}//end testSchemaArchiveBewaartermijnIsUsedWhenObjectHasNone()

	/**
	 * A schema-level override beats the schema default.
	 */
	public function testSchemaOverrideBeatsSchemaDefault(): void {
		$this->schema = new Schema();
		$this->schema->setArchive(
			[
				'enabled' => true,
				'defaultBewaartermijn' => 'P7Y',
				'bewaartermijnOverride' => 'P10Y',
			]
		);

		$createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
		$resolved = $this->makeResolver()->resolve(object: $this->makeObject([]), createdAt: $createdAt);

		$this->assertSame('schema.archive.bewaartermijnOverride', $resolved['source']);
		$this->assertSame(
			$createdAt->add(new DateInterval('P10Y'))->format('c'),
			$resolved['expires']->format('c')
		);
	}//end testSchemaOverrideBeatsSchemaDefault()

	/**
	 * The audit row inherits the same horizon the row-level archival annotation
	 * gives the data it describes.
	 */
	public function testArchivalAnnotationDefaultIsUsedAsLastResort(): void {
		$this->schema = new Schema();
		$this->schema->setArchive([]);
		$this->schema->setConfiguration(
			['x-openregister-archival' => ['retention' => ['default' => 'P5Y']]]
		);

		$createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
		$resolved = $this->makeResolver()->resolve(object: $this->makeObject([]), createdAt: $createdAt);

		$this->assertSame('schema.x-openregister-archival.retention.default', $resolved['source']);
		$this->assertSame(
			$createdAt->add(new DateInterval('P5Y'))->format('c'),
			$resolved['expires']->format('c')
		);
	}//end testArchivalAnnotationDefaultIsUsedAsLastResort()

	/**
	 * A malformed duration must not shorten retention silently — it falls
	 * through to the next source, and with none left, to indefinite.
	 */
	public function testMalformedDurationFallsThroughToIndefiniteNotToThirtyDays(): void {
		$this->schema = new Schema();
		$this->schema->setArchive(['enabled' => true, 'defaultBewaartermijn' => 'not-a-duration']);

		$resolved = $this->makeResolver()->resolve(
			object: $this->makeObject([]),
			createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$this->assertNull($resolved['expires']);
		$this->assertSame(AuditRetentionResolver::SOURCE_NO_POLICY, $resolved['source']);
	}//end testMalformedDurationFallsThroughToIndefiniteNotToThirtyDays()
}//end class
