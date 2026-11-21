<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks;
use OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Schema save keeps the confidentiality block and logs a faulty one at error level.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Service\Confidentiality\ConfidentialityAnnotationValidator
 */
final class ConfidentialitySaveCheckTest extends TestCase {

	/**
	 * A schema carrying a confidentiality block.
	 *
	 * @param mixed $block The block.
	 *
	 * @return Schema
	 */
	private static function schema(mixed $block): Schema {
		$schema = new Schema();
		$schema->setSlug('besluit');
		$schema->setProperties(['confidentialityTier' => ['type' => 'string']]);
		$schema->setConfiguration([ConfidentialityAnnotationValidator::ANNOTATION => $block]);
		return $schema;
	}

	public function testTheBlockSurvivesSetConfiguration(): void {
		$block = ['tiers' => ['public', 'confidential'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['confidential' => 'raadsleden']];
		$this->assertSame($block, self::schema($block)->getConfiguration()[ConfidentialityAnnotationValidator::ANNOTATION] ?? null);
	}

	public function testAValidBlockLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$checks = new AccessDeclarationChecks($logger);
		$checks->checkConfidentiality(self::schema(['tiers' => ['public', 'confidential'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['confidential' => 'raadsleden']]));
		$checks->checkConfidentiality(new Schema());
	}

	public function testAFaultyBlockIsLoggedAtErrorNamingSchemaAndFaultAndStillSaves(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('error')->with(
			$this->logicalAnd(
				$this->stringContains('schema "besluit"'),
				$this->stringContains('fails closed'),
				$this->stringContains('clearance names tier "secret", which is not one of tiers')
			)
		);
		(new AccessDeclarationChecks($logger))->checkConfidentiality(
			self::schema(['tiers' => ['public', 'confidential'], 'tierProperty' => 'confidentialityTier', 'clearance' => ['secret' => 'college']])
		);
	}
}
