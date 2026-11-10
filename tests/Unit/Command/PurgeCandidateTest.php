<?php

/**
 * Unit tests for PurgeCandidate's derivations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Command
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

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\LeafMatch;
use OCA\OpenRegister\Command\PurgeCandidate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * The archival flag and the label a purge reports, and a leaf row's uuid.
 */
final class PurgeCandidateTest extends TestCase {
	/**
	 * An object on schema 172.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('172');
		return $object;
	}//end object()

	/**
	 * A schema with the archival annotation is archival and labelled by slug.
	 *
	 * @return void
	 */
	public function testAnArchivalSchemaIsLabelledBySlug(): void {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => ['default' => 'P30D']]]);

		$candidate = new PurgeCandidate('obj-1', self::object(), $schema);

		$this->assertTrue($candidate->isArchival());
		$this->assertSame('case', $candidate->label());
	}//end testAnArchivalSchemaIsLabelledBySlug()

	/**
	 * Without a schema the candidate is not archival and is labelled by schema id.
	 *
	 * @return void
	 */
	public function testAMissingSchemaIsNotArchivalAndLabelledById(): void {
		$candidate = new PurgeCandidate('obj-1', self::object(), null);

		$this->assertFalse($candidate->isArchival());
		$this->assertSame('172', $candidate->label());
	}//end testAMissingSchemaIsNotArchivalAndLabelledById()

	/**
	 * A leaf row's uuid is read from its fields, '' when it has none.
	 *
	 * @return void
	 */
	public function testALeafMatchReadsItsUuid(): void {
		$this->assertSame('u-1', (new LeafMatch(['uuid' => 'u-1'], null))->uuid());
		$this->assertSame('', (new LeafMatch([], null))->uuid());
	}//end testALeafMatchReadsItsUuid()
}//end class
