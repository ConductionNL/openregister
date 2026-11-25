<?php

/**
 * Unit tests for ViewNamingDocuments.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
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

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Search\Service\ViewNamingDocuments;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\ViewNamingDocuments
 * @uses \OCA\OpenRegister\Search\Db\View
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class ViewNamingDocumentsTest extends TestCase {

	public function testEverySavedViewIsADocumentOfItsQueryAndPresentation(): void {
		$view = new View();
		$view->setUuid('v-1');
		$view->setName('Open meldingen');
		$view->setQuery(['_order' => ['omschrijving' => 'asc']]);
		$view->setPresentation(['columns' => ['omschrijving']]);
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('findAll')->willReturn([$view, 'not-a-view']);

		$this->assertSame(
			[[
				'kind' => 'view',
				'id' => 'v-1',
				'name' => 'Open meldingen',
				'content' => ['query' => ['_order' => ['omschrijving' => 'asc']], 'presentation' => ['columns' => ['omschrijving']]],
			]],
			(new ViewNamingDocuments($mapper, $this->createMock(LoggerInterface::class)))->namingDocuments()
		);
	}

	public function testAnUnreadableListGivesNoDocumentsAndIsLogged(): void {
		$mapper = $this->createMock(ViewMapperInterface::class);
		$mapper->method('findAll')->willThrowException(new \Exception('no read permission'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame([], (new ViewNamingDocuments($mapper, $logger))->namingDocuments());
	}
}
