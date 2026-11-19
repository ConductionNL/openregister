<?php

/**
 * Unit tests for the Kernel read contracts the application, configuration and source events carry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Event\ApplicationCreatedEvent;
use OCA\OpenRegister\Event\ApplicationDeletedEvent;
use OCA\OpenRegister\Event\ApplicationUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationDeletedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceDeletedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\ApplicationInterface;
use OCA\OpenRegister\Kernel\Contracts\ConfigurationInterface;
use OCA\OpenRegister\Kernel\Contracts\SourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * The events hand listeners the same entities as before, typed as Kernel read contracts.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class EntityEventContractsTest extends TestCase {

	public function testTheEntitiesImplementTheirContracts(): void {
		self::assertInstanceOf(ApplicationInterface::class, new Application());
		self::assertInstanceOf(ConfigurationInterface::class, new Configuration());
		self::assertInstanceOf(SourceInterface::class, new Source());
	}

	public function testTheApplicationEventsCarryTheEntityTheyWereGiven(): void {
		$new = new Application();
		$new->setName('new');
		$old = new Application();
		$old->setName('old');

		self::assertSame($new, (new ApplicationCreatedEvent(application: $new))->getApplication());
		self::assertSame($new, (new ApplicationDeletedEvent(application: $new))->getApplication());
		$updated = new ApplicationUpdatedEvent(newApplication: $new, oldApplication: $old);
		self::assertSame($new, $updated->getNewApplication());
		self::assertSame($old, $updated->getOldApplication());
		self::assertSame($new->jsonSerialize(), $updated->getNewApplication()->jsonSerialize());
	}

	public function testTheConfigurationEventsCarryTheEntityTheyWereGiven(): void {
		$new = new Configuration();
		$new->setTitle('new');
		$old = new Configuration();
		$old->setTitle('old');

		self::assertSame($new, (new ConfigurationCreatedEvent(configuration: $new))->getConfiguration());
		self::assertSame($new, (new ConfigurationDeletedEvent(configuration: $new))->getConfiguration());
		$updated = new ConfigurationUpdatedEvent(newConfiguration: $new, oldConfiguration: $old);
		self::assertSame($new, $updated->getConfiguration());
		self::assertSame($new, $updated->getNewConfiguration());
		self::assertSame($old, $updated->getOldConfiguration());
	}

	public function testTheSourceEventsCarryTheEntityTheyWereGiven(): void {
		$new = new Source();
		$new->setTitle('new');
		$old = new Source();
		$old->setTitle('old');

		self::assertSame($new, (new SourceCreatedEvent(source: $new))->getSource());
		self::assertSame($new, (new SourceDeletedEvent(source: $new))->getSource());
		$updated = new SourceUpdatedEvent(newSource: $new, oldSource: $old);
		self::assertSame($new, $updated->getSource());
		self::assertSame($new, $updated->getNewSource());
		self::assertSame($old, $updated->getOldSource());
	}
}
