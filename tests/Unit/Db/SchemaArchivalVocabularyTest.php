<?php

/**
 * Unit tests for the ANNOTATION_VOCABULARY round-trip of
 * `x-openregister-archival` and `x-openregister-seed`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-1
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SchemaArchivalVocabularyTest extends TestCase {
	protected function setUp(): void {
		// Schema extends \OCP\AppFramework\Db\Entity. When the test
		// runner does not have a full Nextcloud bootstrap (which is the
		// standalone-bootstrap fallback used for fast local iteration)
		// the parent class is not autoloadable. Skip cleanly rather than
		// hard-error so the rest of the suite still reports green.
		if (class_exists(\OCP\AppFramework\Db\Entity::class) === false) {
			$this->markTestSkipped(
				'Nextcloud bootstrap not available — run inside the docker test:docker target.'
			);
		}
	}//end setUp()

	/**
	 * Helper: invoke the private validateConfigurationArray() so we can
	 * assert directly on the strip behaviour without spinning up the
	 * full setConfiguration → Server::get path the codebase uses at
	 * runtime.
	 *
	 * @param Schema $schema Schema instance.
	 * @param array $rawInput Raw configuration array.
	 *
	 * @return array<string, mixed>
	 */
	private function invokeValidateConfigurationArray(Schema $schema, array $rawInput): array {
		$reflection = new ReflectionClass($schema);
		$method = $reflection->getMethod('validateConfigurationArray');
		$method->setAccessible(true);
		return $method->invoke($schema, $rawInput);
	}//end invokeValidateConfigurationArray()

	public function testArchivalAnnotationSurvivesConfigurationRoundTrip(): void {
		$schema = new Schema();
		$annotation = [
			'retention' => [
				'default' => 'P30D',
				'rules' => [
					['condition' => 'statusCode < 400', 'retention' => 'PT1H'],
				],
			],
		];

		$result = $this->invokeValidateConfigurationArray(
			$schema,
			['x-openregister-archival' => $annotation]
		);

		self::assertArrayHasKey('x-openregister-archival', $result);
		self::assertSame($annotation, $result['x-openregister-archival']);
	}//end testArchivalAnnotationSurvivesConfigurationRoundTrip()

	/**
	 * `x-openregister-seed` is dropped: it is a phantom with no engine.
	 *
	 * This test previously asserted the opposite. Surviving the round-trip was
	 * never evidence the seed was planted — no code ever read the key. OR's
	 * engine-backed seed path is `components.objects` (ImportHandler).
	 */
	public function testSeedAnnotationIsDroppedBecauseItHasNoEngine(): void {
		$schema = new Schema();
		$seed = [
			['name' => 'Sample One', 'data' => ['statusCode' => 200]],
		];

		$result = $this->invokeValidateConfigurationArray(
			$schema,
			['x-openregister-seed' => $seed]
		);

		self::assertArrayNotHasKey('x-openregister-seed', $result);
		self::assertContains('x-openregister-seed', $schema->consumeDroppedAnnotationKeys());
	}//end testSeedAnnotationIsDroppedBecauseItHasNoEngine()

	public function testUnknownAnnotationKeyStillDropped(): void {
		$schema = new Schema();
		$result = $this->invokeValidateConfigurationArray(
			$schema,
			['x-openregister-lifecycl' => ['field' => 'status']]
		);

		// The typo MUST be dropped — vocabulary widening for archival/seed
		// does not relax the strip behaviour for genuine typos.
		self::assertArrayNotHasKey('x-openregister-lifecycl', $result);
		// And the dropped-key buffer must record it.
		self::assertContains('x-openregister-lifecycl', $schema->consumeDroppedAnnotationKeys());
	}//end testUnknownAnnotationKeyStillDropped()

	public function testArchivalAndProcessingNotRecordedAsDroppedKeys(): void {
		$schema = new Schema();
		$this->invokeValidateConfigurationArray(
			$schema,
			[
				'x-openregister-archival' => ['retention' => ['default' => 'P30D']],
				'x-openregister-processing' => ['code' => 'demo', 'logReads' => true],
			]
		);

		$dropped = $schema->consumeDroppedAnnotationKeys();
		self::assertNotContains('x-openregister-archival', $dropped);
		self::assertNotContains('x-openregister-processing', $dropped);
	}//end testArchivalAndProcessingNotRecordedAsDroppedKeys()
}//end class
