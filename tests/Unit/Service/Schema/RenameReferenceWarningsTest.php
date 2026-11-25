<?php

/**
 * Unit tests for RenameReferenceWarnings.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\ViewNamingDocumentsInterface;
use OCA\OpenRegister\Service\Schema\RenameReferenceWarnings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Schema\RenameReferenceWarnings
 * @uses \OCA\OpenRegister\Db\Schema
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class RenameReferenceWarningsTest extends TestCase {

	/**
	 * The service over these documents and schemas.
	 *
	 * @param array                $flows   The flow documents.
	 * @param array                $views   The view documents.
	 * @param array                $schemas The schemas.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return RenameReferenceWarnings
	 */
	private function warnings(array $flows = [], array $views = [], array|\Throwable $schemas = [], ?LoggerInterface $logger = null): RenameReferenceWarnings {
		$flowSource = $this->createMock(FlowNamingDocumentsInterface::class);
		$flowSource->method('namingDocuments')->willReturn($flows);
		$viewSource = $this->createMock(ViewNamingDocumentsInterface::class);
		$viewSource->method('namingDocuments')->willReturn($views);
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		if ($schemas instanceof \Throwable) {
			$schemaMapper->method('findAll')->willThrowException($schemas);
		} else {
			$schemaMapper->method('findAll')->willReturn($schemas);
		}

		return new RenameReferenceWarnings(
			flows: $flowSource,
			views: $viewSource,
			schemas: $schemaMapper,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end warnings()

	/**
	 * A schema with a notification rules block.
	 *
	 * @param int   $id    The id.
	 * @param array $rules The rules.
	 *
	 * @return Schema
	 */
	private function schemaWithRules(int $id, array $rules): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('meldingen');
		$schema->setConfiguration(['x-openregister-notifications' => $rules]);
		return $schema;
	}//end schemaWithRules()

	public function testASavedViewNamingTheRenamedPropertyIsWarnedAbout(): void {
		$view = ['kind' => 'view', 'id' => 'v-1', 'name' => 'Open meldingen', 'content' => ['query' => ['_order' => ['omschrijving' => 'asc'], 'status' => 'open']]];
		$other = ['kind' => 'view', 'id' => 'v-2', 'name' => 'Alle', 'content' => ['query' => ['status' => 'open']]];

		$warnings = $this->warnings(views: [$view, $other])->forPlan(plan: [['op' => 'rename', 'from' => 'omschrijving', 'to' => 'toelichting']]);

		$this->assertSame(
			[['kind' => 'view', 'id' => 'v-1', 'name' => 'Open meldingen', 'names' => 'omschrijving', 'op' => 'rename', 'paths' => ['$.query._order.omschrijving']]],
			$warnings
		);
	}//end testASavedViewNamingTheRenamedPropertyIsWarnedAbout()

	public function testFlowsAndNotificationRulesNamingTheOldSlugAreWarnedAbout(): void {
		$flow = ['kind' => 'flow', 'id' => 'f-1', 'name' => 'Triage', 'content' => ['triggerSchema' => 'melding', 'nodes' => [['config' => ['schema' => 'document']]]]];
		$rules = $this->schemaWithRules(id: 4, rules: [['event' => 'created', 'link' => '/apps/x/melding/{uuid}']]);

		$warnings = $this->warnings(flows: [$flow], schemas: [$rules, new Schema()])->forPlan(plan: [['op' => 'renameSchema', 'from' => 'melding', 'to' => 'signaal']]);

		$this->assertSame(['flow', 'notificationRule'], array_column($warnings, 'kind'));
		$this->assertSame(['$.triggerSchema'], $warnings[0]['paths']);
		$this->assertSame(['$.0.link'], $warnings[1]['paths']);
		$this->assertSame(4, $warnings[1]['id']);
		$this->assertSame('renameSchema', $warnings[1]['op']);
	}//end testFlowsAndNotificationRulesNamingTheOldSlugAreWarnedAbout()

	public function testAPlanThatRenamesNothingReadsNothing(): void {
		$flowSource = $this->createMock(FlowNamingDocumentsInterface::class);
		$flowSource->expects($this->never())->method('namingDocuments');
		$viewSource = $this->createMock(ViewNamingDocumentsInterface::class);
		$viewSource->expects($this->never())->method('namingDocuments');
		$schemaMapper = $this->createMock(SchemaMapperInterface::class);
		$schemaMapper->expects($this->never())->method('findAll');

		$service = new RenameReferenceWarnings(flows: $flowSource, views: $viewSource, schemas: $schemaMapper, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $service->forPlan(plan: [['op' => 'remove', 'property' => 'omschrijving'], ['op' => 'rename', 'from' => ' ', 'to' => 'x']]));
	}//end testAPlanThatRenamesNothingReadsNothing()

	public function testUnreadableNotificationRulesLeaveTheOtherWarnings(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$view = ['kind' => 'view', 'id' => 'v-1', 'name' => 'V', 'content' => ['query' => ['omschrijving' => 'x']]];

		$warnings = $this->warnings(views: [$view], schemas: new \RuntimeException('db down'), logger: $logger)
			->forPlan(plan: [['op' => 'rename', 'from' => 'omschrijving', 'to' => 'toelichting']]);

		$this->assertSame(['view'], array_column($warnings, 'kind'));
	}//end testUnreadableNotificationRulesLeaveTheOtherWarnings()

	public function testRenamedNamesAreTheFromOfEachRenameOnce(): void {
		$this->assertSame(
			[['op' => 'rename', 'name' => 'a'], ['op' => 'renameSchema', 'name' => 'melding']],
			RenameReferenceWarnings::renamedNames(plan: [
				['op' => 'rename', 'from' => 'a', 'to' => 'b'],
				['op' => 'rename', 'from' => ' a ', 'to' => 'c'],
				['op' => 'renameSchema', 'from' => 'melding', 'to' => 'signaal'],
				['op' => 'cast', 'property' => 'a'],
				'not-a-step',
				['op' => 'rename', 'from' => 7, 'to' => 'x'],
			])
		);
	}//end testRenamedNamesAreTheFromOfEachRenameOnce()

	public function testATextNamesTheWordOnlyAsAWholeToken(): void {
		$document = [
			'exact' => 'omschrijving',
			'dotted' => 'object.omschrijving',
			'template' => '{{ omschrijving }}',
			'longer' => 'omschrijvingen',
			'prefixed' => 'korte_omschrijving',
			'hyphen' => 'omschrijving-kort',
			'nested' => ['omschrijving' => 1, 'list' => ['x', 'omschrijving']],
			'number' => 5,
		];

		$this->assertSame(
			['$.exact', '$.dotted', '$.template', '$.nested.omschrijving', '$.nested.list.1'],
			RenameReferenceWarnings::pathsNaming(value: $document, name: 'omschrijving')
		);
		$this->assertSame([], RenameReferenceWarnings::pathsNaming(value: $document, name: ''));
	}//end testATextNamesTheWordOnlyAsAWholeToken()
}//end class
