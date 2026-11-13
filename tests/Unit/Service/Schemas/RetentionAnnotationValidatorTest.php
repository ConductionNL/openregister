<?php

/**
 * RetentionAnnotationValidator: inheritAppraisalFrom must name reference properties.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals;
use OCA\OpenRegister\Service\Schemas\RetentionAnnotationValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\RetentionAnnotationValidator
 * @covers \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 */
class RetentionAnnotationValidatorTest extends TestCase {

	private const PROPERTIES = [
		'title'    => ['type' => 'string'],
		'subjects' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/subject']],
		'parent'   => ['type' => 'string', '$ref' => 'subject'],
	];

	private function errors(mixed $annotation): array {
		return (new RetentionAnnotationValidator())->validate($annotation, self::PROPERTIES);
	}

	public function testReferencePropertiesAreAccepted(): void {
		$this->assertSame([], $this->errors(['inheritAppraisalFrom' => ['subjects', 'parent']]));
		$this->assertSame([], $this->errors([]));
		$this->assertSame([], $this->errors(null));
	}

	public function testAnInheritFromANonReferenceIsRefused(): void {
		$this->assertSame(
			["inheritAppraisalFrom names 'title', which does not reference other objects."],
			$this->errors(['inheritAppraisalFrom' => ['title']])
		);
	}

	public function testAnUnknownPropertyIsRefused(): void {
		$this->assertSame(
			["inheritAppraisalFrom names 'topics', which is not a property of this schema."],
			$this->errors(['inheritAppraisalFrom' => ['subjects', 'topics']])
		);
	}

	public function testABadShapeIsRefused(): void {
		$this->assertSame(['The annotation must be an object.'], $this->errors('subjects'));
		$this->assertSame(['The annotation must be an object.'], $this->errors(['subjects']));
		$this->assertSame(['inheritAppraisalFrom must be a list of property names.'], $this->errors(['inheritAppraisalFrom' => 'subjects']));
		$this->assertSame(['inheritAppraisalFrom must name properties by their non-empty name.'], $this->errors(['inheritAppraisalFrom' => [3]]));
	}

	public function testTheSchemaSaveRefusesABadDeclaration(): void {
		$schema = new Schema();
		$schema->setProperties(self::PROPERTIES);
		$schema->setConfiguration(['x-openregister-retention' => ['inheritAppraisalFrom' => ['title']]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("x-openregister-retention: inheritAppraisalFrom names 'title', which does not reference other objects.");

		(new RecordAnnotationRefusals($this->createMock(LoggerInterface::class)))->checkRetention($schema);
	}

	public function testTheSchemaSaveAcceptsASoundOrAbsentDeclaration(): void {
		$refusals = new RecordAnnotationRefusals($this->createMock(LoggerInterface::class));
		$schema   = new Schema();
		$schema->setProperties(self::PROPERTIES);
		$refusals->checkRetention($schema);

		$schema->setConfiguration(['x-openregister-retention' => ['inheritAppraisalFrom' => ['subjects']]]);
		$refusals->checkRetention($schema);

		$this->assertSame(['inheritAppraisalFrom' => ['subjects']], $schema->getConfiguration()['x-openregister-retention']);
	}
}
