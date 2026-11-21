<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Listener\SemanticProviderCacheListener}.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/cross-app-semantic-references/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\SemanticProviderCacheListener;
use OCA\OpenRegister\Service\SemanticProviderCatalogue;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The provider list is cleared on schema and app changes, and only then.
 */
#[CoversClass(SemanticProviderCacheListener::class)]
#[UsesClass(SchemaUpdatedEvent::class)]
#[UsesClass(Schema::class)]
class SemanticProviderCacheListenerTest extends TestCase {

	public function testASchemaUpdateClearsTheList(): void {
		$catalogue = $this->createMock(SemanticProviderCatalogue::class);
		$catalogue->expects($this->once())->method('clear');

		(new SemanticProviderCacheListener(catalogue: $catalogue))->handle(new SchemaUpdatedEvent(new Schema(), new Schema()));
	}//end testASchemaUpdateClearsTheList()

	public function testEnablingAndDisablingAnAppClearTheList(): void {
		$catalogue = $this->createMock(SemanticProviderCatalogue::class);
		$catalogue->expects($this->exactly(2))->method('clear');
		$listener = new SemanticProviderCacheListener(catalogue: $catalogue);

		$listener->handle(new AppEnableEvent('shillinq'));
		$listener->handle(new AppDisableEvent('shillinq'));
	}//end testEnablingAndDisablingAnAppClearTheList()

	public function testAnUnrelatedEventLeavesTheListAlone(): void {
		$catalogue = $this->createMock(SemanticProviderCatalogue::class);
		$catalogue->expects($this->never())->method('clear');

		(new SemanticProviderCacheListener(catalogue: $catalogue))->handle(new Event());
	}//end testAnUnrelatedEventLeavesTheListAlone()
}//end class
