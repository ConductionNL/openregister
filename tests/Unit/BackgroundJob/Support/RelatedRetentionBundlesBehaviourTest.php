<?php

/**
 * Behaviour test for the related-retention service bundles.
 *
 * The recalculation job reads each collaborator from these bundles by name, so
 * each one must hand back exactly the collaborator it was given under that name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob\Support;

use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionLookup;
use OCA\OpenRegister\BackgroundJob\Support\RelatedRetentionRules;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchiveReferenceReaderInterface;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndexInterface;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use PHPUnit\Framework\TestCase;

class RelatedRetentionBundlesBehaviourTest extends TestCase {

	public function testTheLookupHandsBackEachMapperUnderItsName(): void {
		$objects = $this->createMock(MagicMapperInterface::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$registers = $this->createMock(RegisterMapperInterface::class);
		$lookup = new RelatedRetentionLookup($objects, $schemas, $registers);

		$this->assertSame([$objects, $schemas, $registers], [$lookup->objectMapper, $lookup->schemaMapper, $lookup->registerMapper]);
	}

	public function testTheRulesHandBackEachServiceUnderItsName(): void {
		$index = $this->createMock(DependantRetentionIndexInterface::class);
		$calculator = $this->createMock(ArchiveReferenceReaderInterface::class);
		$retention = $this->createMock(RetentionServiceInterface::class);
		$rules = new RelatedRetentionRules($index, $calculator, $retention);

		$this->assertSame([$index, $calculator, $retention], [$rules->index, $rules->calculator, $rules->retention]);
	}
}
