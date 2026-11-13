<?php

/**
 * Unit tests for BundleFetchRequest and BrokeredPublishTarget — the GitHub requests of a config bundle.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Config;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Config\BrokeredPublishTarget;
use OCA\OpenRegister\Service\Config\BundleFetchRequest;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use PHPUnit\Framework\TestCase;

final class ConfigPublishRequestsTest extends TestCase {

	public function testAFetchNamesTheContentsEndpointOfThePath(): void {
		$request = new BundleFetchRequest('acme/configs', 'bundles/zaak.json', 'cred-1');

		$this->assertSame('/repos/acme/configs/contents/bundles/zaak.json', $request->contentsPath());
		$this->assertSame('bundles/zaak.json in acme/configs', $request->where());
	}

	public function testATargetSplitsIntoOwnerAndName(): void {
		$target = new BrokeredPublishTarget(new ConfigPublishTarget('acme/configs', 'b.json'), 'cred');

		$this->assertSame(['acme', 'configs'], $target->ownerAndName());
	}

	public function testOnlyTheFirstSlashSeparatesOwnerFromName(): void {
		$target = new BrokeredPublishTarget(new ConfigPublishTarget('acme/configs/extra', 'b.json'), 'cred');

		$this->assertSame(['acme', 'configs/extra'], $target->ownerAndName());
	}

	public function testARepoWithoutOwnerPadsAnEmptyName(): void {
		$target = new BrokeredPublishTarget(new ConfigPublishTarget('configs', 'b.json'), 'cred');

		$this->assertSame(['configs', ''], $target->ownerAndName());
	}

	public function testTheCreateBodyAsksForAnInitialisedRepoWithTheTargetsVisibility(): void {
		$public  = new BrokeredPublishTarget(new ConfigPublishTarget('acme/configs', 'b.json'), 'cred');
		$private = new BrokeredPublishTarget(new ConfigPublishTarget('acme/secret', 'b.json', 'main', true), 'cred');

		$this->assertSame(['name' => 'configs', 'private' => false, 'auto_init' => true], json_decode($public->createBody(), true));
		$this->assertSame(['name' => 'secret', 'private' => true, 'auto_init' => true], json_decode($private->createBody(), true));
		$this->assertSame('cred', $private->credentialId);
	}
}
