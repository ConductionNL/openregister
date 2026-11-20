<?php

/**
 * The dashboard and the map overview ask Search ports; Integrations answers them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\IntegrationsMapperBindings;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\PageWidgetDeclarationInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\WebhookLogStatisticsInterface;
use OCA\OpenRegister\Search\Service\DashboardMappers;
use OCA\OpenRegister\Search\Service\MapsOverviewSources;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\IntegrationWiring;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Each port is bound by Integrations to the class that already answers the old contract.
 */
final class SearchReadPortsBindingTest extends TestCase {

	public function testIntegrationsBindsThePortsToTheClassesBehindTheOldContracts(): void {
		$this->assertSame(WebhookLogMapper::class, IntegrationsMapperBindings::BINDINGS[WebhookLogStatisticsInterface::class]);
		$this->assertSame(WebhookLogMapper::class, IntegrationsMapperBindings::BINDINGS[WebhookLogMapperInterface::class]);
		$this->assertSame(IntegrationRegistry::class, IntegrationWiring::BINDINGS[PageWidgetDeclarationInterface::class]);
		$this->assertSame(IntegrationRegistry::class, IntegrationWiring::BINDINGS[PageWidgetRegistryInterface::class]);
	}

	public function testTheOldContractsAreThePorts(): void {
		$this->assertTrue(is_subclass_of(WebhookLogMapperInterface::class, WebhookLogStatisticsInterface::class));
		$this->assertTrue(is_subclass_of(PageWidgetRegistryInterface::class, PageWidgetDeclarationInterface::class));
		$this->assertTrue(method_exists(PageWidgetRegistryInterface::class, 'listPageWidgets'));
	}

	public function testTheSearchBagsTypeAgainstThePorts(): void {
		$this->assertSame(WebhookLogStatisticsInterface::class, (string)(new ReflectionProperty(DashboardMappers::class, 'webhookLogMapper'))->getType());
		$this->assertSame(PageWidgetDeclarationInterface::class, (string)(new ReflectionProperty(MapsOverviewSources::class, 'registry'))->getType());
	}
}//end class
