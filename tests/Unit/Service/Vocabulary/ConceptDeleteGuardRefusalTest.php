<?php

/**
 * Edge and message tests for ConceptDeleteGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the guard counts when it cannot look, how many holders it names, and the refusal texts.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard
 */
class ConceptDeleteGuardRefusalTest extends TestCase {

	private const SCHEME = 'https://example.org/scheme';

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * @var MagicMapperInterface&MockObject
	 */
	private MagicMapperInterface $objects;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->objects = $this->createMock(MagicMapperInterface::class);
	}//end setUp()

	/**
	 * The guard.
	 *
	 * @return ConceptDeleteGuard
	 */
	private function guard(): ConceptDeleteGuard {
		return new ConceptDeleteGuard(
			lifecycle: new ConceptLifecycle(),
			tables: new \OCA\OpenRegister\Service\Vocabulary\CodedSchemaTables(schemas: $this->schemas, declarations: new CodedPropertyDeclarationFactory(), objects: $this->objects)
		);
	}//end guard()

	/**
	 * A schema with one coded property on the scheme.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return Schema
	 */
	private function coded(string $slug): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setProperties(['code' => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME]]]);
		return $schema;
	}//end coded()

	/**
	 * A concept with neither uri nor notation is held by nothing, and no schema is read.
	 *
	 * @return void
	 */
	public function testConceptWithoutIdentityIsHeldByNothing(): void {
		$this->schemas->expects($this->never())->method('findAll');

		$this->assertSame(['count' => 0, 'holders' => []], $this->guard()->usage(concept: ['uri' => ' ', 'notation' => ''], schemeUri: self::SCHEME));
	}//end testConceptWithoutIdentityIsHeldByNothing()

	/**
	 * When the schemas cannot be listed, nothing is counted.
	 *
	 * @return void
	 */
	public function testUnlistableSchemasCountNothing(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->objects->expects($this->never())->method('countAll');

		$this->assertSame(['count' => 0, 'holders' => []], $this->guard()->usage(concept: ['uri' => 'u:1'], schemeUri: self::SCHEME));
	}//end testUnlistableSchemasCountNothing()

	/**
	 * The total counts every holder, but only the first ten are named; non-schema
	 * entries are skipped.
	 *
	 * @return void
	 */
	public function testHoldersAreSampledButTotalIsComplete(): void {
		$all = ['not-a-schema'];
		for ($i = 1; $i <= 12; $i++) {
			$all[] = $this->coded(slug: 's' . $i);
		}

		$this->schemas->method('findAll')->willReturn($all);
		$this->objects->method('countAll')->willReturn(2);

		$usage = $this->guard()->usage(concept: ['uri' => 'u:1'], schemeUri: self::SCHEME);

		$this->assertSame(24, $usage['count']);
		$this->assertCount(ConceptDeleteGuard::BLOCKER_SAMPLE, $usage['holders']);
		$this->assertSame(['schema' => 's1', 'property' => 'code', 'count' => 2], $usage['holders'][0]);
	}//end testHoldersAreSampledButTotalIsComplete()

	/**
	 * The in-use refusal names the value, the total and where it is held.
	 *
	 * @return void
	 */
	public function testInUseMessageNamesWhereTheValueIsHeld(): void {
		$this->assertSame(
			'The value "u:1" is held by 5 object(s) and cannot be deleted. Close its validity window to retire it instead, '
			.'which keeps those records readable. Held on: zaak.resultaat (3), besluit.soort (2).',
			$this->guard()->inUseMessage(
				concept: ['uri' => ' u:1 '],
				usage: ['count' => 5, 'holders' => [
					['schema' => 'zaak', 'property' => 'resultaat', 'count' => 3],
					['schema' => 'besluit', 'property' => 'soort', 'count' => 2],
				]]
			)
		);
	}//end testInUseMessageNamesWhereTheValueIsHeld()

	/**
	 * Without holders or a uri, the message falls back to generic wording.
	 *
	 * @return void
	 */
	public function testMessagesFallBackWithoutUriOrHolders(): void {
		$this->assertSame(
			'The value "this value" is held by 0 object(s) and cannot be deleted. Close its validity window to retire it instead, '
			.'which keeps those records readable.',
			$this->guard()->inUseMessage(concept: [], usage: [])
		);
		$this->assertSame(
			'The value "this value" is defined by the system and cannot be deleted. Close its validity window to retire it instead.',
			$this->guard()->systemDefinedMessage(concept: [])
		);
	}//end testMessagesFallBackWithoutUriOrHolders()
}//end class
