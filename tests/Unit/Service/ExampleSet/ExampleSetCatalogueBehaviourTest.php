<?php

/**
 * Behaviour tests for ExampleSetCatalogue: which sets the enabled apps ship, and
 * what gathering a set refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder;
use OCP\App\IAppManager;

/**
 * Behaviour of the example-set catalogue over app folders on disk.
 */
class ExampleSetCatalogueBehaviourTest extends ExampleSetTestCase {

	public function testSetsListsValidSetIdsPerAppSortedAndSkipsAppsWithoutAFolder(): void {
		$this->app(app: 'openregister', set: 'zeta', fragment: ['x' => 1]);
		$this->app(app: 'openregister', set: 'alpha', fragment: ['x' => 1]);
		$this->app(app: 'openregister', set: 'Bad_Id', fragment: ['x' => 1]);
		$this->app(app: 'beta', set: 'alpha', fragment: ['x' => 1]);
		$this->apps['nofolder'] = $this->appsRoot.'/nofolder';

		$this->assertSame(['alpha' => ['beta', 'openregister'], 'zeta' => ['openregister']], $this->catalogue->sets());
	}

	public function testAnAppWhosePathCannotBeResolvedIsLeftOut(): void {
		$this->app(app: 'openregister', set: 'demo', fragment: ['x' => 1]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['openregister', 'broken']);
		$appManager->method('getAppPath')->willReturnCallback(
			function (string $app): string {
				if ($app === 'broken') {
					throw new \RuntimeException('no such app');
				}

				return $this->apps[$app];
			}
		);
		$catalogue = new ExampleSetCatalogue($appManager, new ExampleSetOrder());

		$this->assertSame(['demo' => ['openregister']], $catalogue->sets());
	}

	public function testGatherRefusesAnUnsafeSetId(): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('"../etc" is not a set id: use lower-case letters, digits and dashes.');

		$this->catalogue->gather('../etc');
	}

	public function testGatherRefusesASetWhoseCastFileHasNoCast(): void {
		$cast = $this->castFile();
		unset($cast['cast']);
		$this->app(app: 'openregister', fragment: $cast);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('There is no example set "demo": OpenRegister ships no lib/Settings/example-sets/demo.json with a cast.');

		$this->catalogue->gather('demo');
	}

	public function testGatherHeadsThePlanWithTheCastFileAndDefaultsTitleAndTimezone(): void {
		$cast = $this->castFile();
		unset($cast['title'], $cast['timezone']);
		$this->app(app: 'openregister', fragment: $cast);
		$this->app(app: 'alpha', fragment: ['objects' => []]);

		$plan = $this->catalogue->gather('demo');

		$this->assertSame('demo', $plan['set']);
		$this->assertSame('demo', $plan['title']);
		$this->assertSame('UTC', $plan['timezone']);
		$this->assertSame(['openregister', 'alpha'], array_column($plan['fragments'], 'app'), 'the cast app comes first');
	}

	public function testGatherRefusesAServiceAccountKeyUsedByTwoApps(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['serviceAccounts' => [['key' => 'bot', 'uid' => 'alpha-bot']]]);
		$this->app(app: 'beta', fragment: ['serviceAccounts' => [['key' => 'bot', 'uid' => 'beta-bot']]]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('the service account key "bot" is used twice in this set.');

		$this->catalogue->gather('demo');
	}

	public function testGatherRefusesAServiceAccountKeyThatIsAlsoACastKey(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['serviceAccounts' => [['key' => 'sanne', 'uid' => 'alpha-bot']]]);

		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage('the service account key "sanne" is used twice in this set.');

		$this->catalogue->gather('demo');
	}

	public function testDistinctServiceAccountsAreGathered(): void {
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['serviceAccounts' => [['key' => 'abot', 'uid' => 'alpha-bot']]]);
		$this->app(app: 'beta', fragment: ['serviceAccounts' => [['key' => 'bbot', 'uid' => 'beta-bot']]]);

		$plan     = $this->catalogue->gather('demo');
		$accounts = array_merge(...array_column($plan['fragments'], 'serviceAccounts'));

		$this->assertSame(['abot', 'bbot'], array_column($accounts, 'key'));
	}
}//end class
