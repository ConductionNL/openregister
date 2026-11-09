<?php

/**
 * Golden test: every branch of the audit-row retention resolution
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\AuditRetentionResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins expires and source for every branch (legal hold, both indefinite
 * nominations, the object's date, the object's duration, the schema's
 * override, default and annotation, no schema, unloadable schema, nothing),
 * the 30-day floor, the fall-through on malformed values and the warnings
 * it logs. The creation instant is a fixed argument, never the clock.
 */
class AuditRetentionResolverBranchGoldenTest extends TestCase {

	private const CREATED = '2026-03-01T10:00:00+00:00';

	/** @var array<int, string> The warnings logged. */
	private array $warnings = [];

	/**
	 * A resolver whose schema lookup answers the given schema, or throws when null.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return AuditRetentionResolver
	 */
	private function resolver(?Schema $schema): AuditRetentionResolver {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			function (mixed $id, ...$rest) use ($schema): Schema {
				if ($schema === null) {
					throw new \RuntimeException('no schema');
				}

				return $schema;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$logger = new class($this->warnings) extends AbstractLogger {
			/**
			 * Collect.
			 *
			 * @param array<int, string> $sink The sink.
			 */
			public function __construct(private array &$sink) {
			}

			/**
			 * Record a log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->sink[] = $level . ':' . $message . ':' . (isset($context['exception']) ? get_class($context['exception']) : '-');
			}
		};

		return new AuditRetentionResolver($container, $logger);
	}//end resolver()

	/**
	 * A schema with an archive block and a configuration.
	 *
	 * @param mixed $archive       The archive block.
	 * @param mixed $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(mixed $archive, mixed $configuration): Schema {
		$schema = new Schema();
		$schema->setArchive($archive);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * Resolve and describe as [expires ATOM or null, source].
	 *
	 * @param mixed       $retention The retention column.
	 * @param string|null $schemaId  The object's schema id.
	 * @param Schema|null $schema    What the schema lookup answers.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	private function resolve(mixed $retention, ?string $schemaId = '2', ?Schema $schema = null): array {
		$object = new ObjectEntity();
		$object->setRetention($retention);
		$object->setSchema($schemaId);
		$result = $this->resolver($schema)->resolve($object, new DateTimeImmutable(self::CREATED));
		return [$result['expires']?->format(DATE_ATOM), $result['source']];
	}//end resolve()

	/**
	 * The object-level branches.
	 *
	 * @return void
	 */
	public function testObjectBranches(): void {
		$keep = $this->schema(['defaultBewaartermijn' => 'P1Y'], null);

		$this->assertSame([null, 'legal-hold:indefinite'], $this->resolve(['legalHold' => ['active' => true], 'archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2030-01-01'], '2', $keep));
		$this->assertSame(['2027-03-01T10:00:00+00:00', 'schema.archive.defaultBewaartermijn'], $this->resolve(['legalHold' => ['active' => 'true']], '2', $keep), 'Only a real true holds.');
		$this->assertSame(['2027-03-01T10:00:00+00:00', 'schema.archive.defaultBewaartermijn'], $this->resolve(['legalHold' => true], '2', $keep), 'A non-array hold is no hold.');
		$this->assertSame([null, 'archiefnominatie=bewaren:indefinite'], $this->resolve(['archiefnominatie' => 'bewaren', 'archiefactiedatum' => '2030-01-01']));
		$this->assertSame([null, 'archiefnominatie=nog_niet_bepaald:indefinite'], $this->resolve(['archiefnominatie' => 'nog_niet_bepaald', 'bewaartermijn' => 'P1D']));
		$this->assertSame(['2030-01-01T00:00:00+00:00', 'object.archiefactiedatum'], $this->resolve(['archiefnominatie' => 'vernietigen', 'archiefactiedatum' => '2030-01-01T00:00:00+00:00', 'bewaartermijn' => 'P1D']));
		$this->assertSame(['2026-03-31T10:00:00+00:00', 'object.archiefactiedatum+floor'], $this->resolve(['archiefactiedatum' => '2020-01-01T00:00:00+00:00']));
		$this->assertSame(['2026-03-31T10:00:00+00:00', 'object.archiefactiedatum'], $this->resolve(['archiefactiedatum' => '2026-03-31T10:00:00+00:00']), 'Exactly the floor does not bind.');
		$this->assertSame(['2036-03-01T10:00:00+00:00', 'object.bewaartermijn'], $this->resolve(['archiefactiedatum' => 'not a date', 'bewaartermijn' => 'P10Y']));
		$this->assertSame(['2036-03-01T10:00:00+00:00', 'object.bewaartermijn'], $this->resolve(['archiefactiedatum' => '', 'bewaartermijn' => 'P10Y']));
		$this->assertSame(['2036-03-01T10:00:00+00:00', 'object.bewaartermijn'], $this->resolve(['archiefactiedatum' => 20300101, 'bewaartermijn' => 'P10Y']));
		$this->assertSame(['2026-03-31T10:00:00+00:00', 'object.bewaartermijn+floor'], $this->resolve(['bewaartermijn' => 'P1D']));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve('not-an-array'));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve(['bewaartermijn' => 'garbage']));

		$this->assertSame(
			[
				'warning:[AuditRetentionResolver] Unparseable archiefactiedatum "not a date".:Exception',
				'warning:[AuditRetentionResolver] Unparseable retention duration "garbage" from object.bewaartermijn; falling through to the next source.:Exception',
			],
			array_map(static fn (string $w): string => preg_replace('/:[A-Za-z\\\\]*Exception$/', ':Exception', $w), $this->warnings)
		);
	}//end testObjectBranches()

	/**
	 * The schema-level branches.
	 *
	 * @return void
	 */
	public function testSchemaBranches(): void {
		$annotation = ['x-openregister-archival' => ['retention' => ['default' => 'P5Y']]];

		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve([], null));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve([], ''));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve([], '2', null), 'An unloadable schema is no policy.');
		$this->assertSame(['2028-03-01T10:00:00+00:00', 'schema.archive.bewaartermijnOverride'], $this->resolve([], '2', $this->schema(['bewaartermijnOverride' => 'P2Y', 'defaultBewaartermijn' => 'P1Y'], $annotation)));
		$this->assertSame(['2027-03-01T10:00:00+00:00', 'schema.archive.defaultBewaartermijn'], $this->resolve([], '2', $this->schema(['bewaartermijnOverride' => 'bad', 'defaultBewaartermijn' => 'P1Y'], $annotation)));
		$this->assertSame(['2031-03-01T10:00:00+00:00', 'schema.x-openregister-archival.retention.default'], $this->resolve([], '2', $this->schema(['bewaartermijnOverride' => '', 'defaultBewaartermijn' => 'bad2'], $annotation)));
		$this->assertSame(['2031-03-01T10:00:00+00:00', 'schema.x-openregister-archival.retention.default'], $this->resolve([], '2', $this->schema(null, $annotation)));
		$this->assertSame(['2026-03-31T10:00:00+00:00', 'schema.x-openregister-archival.retention.default+floor'], $this->resolve([], '2', $this->schema([], ['x-openregister-archival' => ['retention' => ['default' => 'PT1H']]])));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve([], '2', $this->schema(['other' => 1], ['x-openregister-archival' => ['retention' => ['default' => 'bad3']]])));
		$this->assertSame([null, 'no-retention-policy:indefinite'], $this->resolve([], '2', $this->schema(null, null)));

		$this->assertSame(
			[
				'warning:[AuditRetentionResolver] Unparseable retention duration "bad" from schema.archive.bewaartermijnOverride; falling through to the next source.:Exception',
				'warning:[AuditRetentionResolver] Unparseable retention duration "bad2" from schema.archive.defaultBewaartermijn; falling through to the next source.:Exception',
				'warning:[AuditRetentionResolver] Unparseable retention duration "bad3" from schema.x-openregister-archival.retention.default; falling through to the next source.:Exception',
			],
			array_map(static fn (string $w): string => preg_replace('/:[A-Za-z\\\\]*Exception$/', ':Exception', $w), $this->warnings)
		);
	}//end testSchemaBranches()
}//end class
