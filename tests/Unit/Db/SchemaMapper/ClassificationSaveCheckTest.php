<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Schema save keeps the classification block and refuses a broken one.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationDeclarationValidator
 */
final class ClassificationSaveCheckTest extends TestCase {

	/**
	 * A schema carrying a classification block.
	 *
	 * @param mixed $block The block.
	 *
	 * @return Schema
	 */
	private static function schema(mixed $block): Schema {
		$schema = new Schema();
		$schema->setSlug('document');
		$schema->setProperties(['label' => ['type' => 'string']]);
		$schema->setConfiguration([ClassificationScale::ANNOTATION => $block]);
		return $schema;
	}

	public function testTheBlockSurvivesSetConfiguration(): void {
		$block = ['property' => 'label', 'levels' => ['open', 'geheim']];
		$this->assertSame($block, self::schema($block)->getConfiguration()[ClassificationScale::ANNOTATION] ?? null);
	}

	public function testAValidBlockSaves(): void {
		$checks = new AccessDeclarationChecks($this->createMock(LoggerInterface::class));
		$checks->checkClassification(self::schema(['property' => 'label', 'levels' => ['open', 'geheim']]));
		$checks->checkClassification(new Schema());
		$this->addToAssertionCount(1);
	}

	public function testADuplicateLevelIsRefusedNamingTheLabel(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-classification: levels names "open" twice');
		(new AccessDeclarationChecks($this->createMock(LoggerInterface::class)))->checkClassification(
			self::schema(['property' => 'label', 'levels' => ['open', 'geheim', 'open']])
		);
	}

	public function testAnUndeclaredPropertyIsRefused(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('property "vertrouwelijkheid" is not a property of this schema');
		(new AccessDeclarationChecks($this->createMock(LoggerInterface::class)))->checkClassification(
			self::schema(['property' => 'vertrouwelijkheid', 'levels' => ['open', 'geheim']])
		);
	}
}
