<?php

/**
 * Behaviour tests for RegisterDocumentLoader against a real temporary app directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\RegisterDocumentLoader;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Missing app, malformed base, malformed fragment and list merging.
 */
class RegisterDocumentLoaderBehaviourTest extends TestCase {

	private string $root;

	/**
	 * Create an empty app directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = (string)tempnam(sys_get_temp_dir(), 'rdl');
		unlink($this->root);
		mkdir($this->root . '/lib/Settings/register.d', 0777, true);
	}//end setUp()

	/**
	 * Remove the app directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->rmTree($this->root);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir The directory.
	 *
	 * @return void
	 */
	private function rmTree(string $dir): void {
		foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) ?: [] as $entry) {
			if (is_dir($entry) === true) {
				$this->rmTree($entry);
				continue;
			}

			unlink($entry);
		}

		rmdir($dir);
	}//end rmTree()

	/**
	 * A loader whose app manager points at the temp directory.
	 *
	 * @return RegisterDocumentLoader
	 */
	private function loader(): RegisterDocumentLoader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('demo')->willReturn($this->root);
		return new RegisterDocumentLoader($appManager);
	}//end loader()

	/**
	 * An app the manager cannot locate yields no document and no version.
	 *
	 * @return void
	 */
	public function testAnUnknownAppYieldsNoDocument(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())
			->method('getAppPath')
			->with('ghost')
			->willThrowException(new AppPathNotFoundException('ghost'));

		$this->assertSame([null, ''], (new RegisterDocumentLoader($appManager))->load('ghost'));
	}//end testAnUnknownAppYieldsNoDocument()

	/**
	 * A base document that is not JSON yields no document.
	 *
	 * @return void
	 */
	public function testAMalformedBaseDocumentYieldsNoDocument(): void {
		file_put_contents($this->root . '/lib/Settings/demo_register.json', '{not json');

		$this->assertSame([null, ''], $this->loader()->load('demo'));
	}//end testAMalformedBaseDocumentYieldsNoDocument()

	/**
	 * A base document that is valid JSON but a scalar yields no document.
	 *
	 * @return void
	 */
	public function testAScalarBaseDocumentYieldsNoDocument(): void {
		file_put_contents($this->root . '/lib/Settings/demo_register.json', '"just a string"');

		$this->assertSame([null, ''], $this->loader()->load('demo'));
	}//end testAScalarBaseDocumentYieldsNoDocument()

	/**
	 * A malformed fragment is skipped, the good one still merges, the result is
	 * flagged incomplete, and only the merged fragment enters the version.
	 *
	 * @return void
	 */
	public function testAMalformedFragmentIsSkippedAndFlagsIncomplete(): void {
		file_put_contents(
			$this->root . '/lib/Settings/demo_register.json',
			json_encode(['info' => ['version' => '1.2.0'], 'tags' => ['a']])
		);
		$good = json_encode(['tags' => ['b'], 'extra' => true]);
		file_put_contents($this->root . '/lib/Settings/register.d/10-good.json', $good);
		file_put_contents($this->root . '/lib/Settings/register.d/20-bad.json', '{broken');

		[$data, $version, $complete] = $this->loader()->load('demo');

		$this->assertSame(['info' => ['version' => '1.2.0'], 'tags' => ['a', 'b'], 'extra' => true], $data);
		$this->assertSame('1.2.0+frag.' . substr(md5('10-good.json:' . md5($good) . ';'), 0, 8), $version);
		$this->assertFalse($complete);
	}//end testAMalformedFragmentIsSkippedAndFlagsIncomplete()

	/**
	 * Paired control: with only good fragments the result is complete.
	 *
	 * @return void
	 */
	public function testOnlyGoodFragmentsAreComplete(): void {
		file_put_contents($this->root . '/lib/Settings/demo_register.json', json_encode(['info' => ['version' => '1.2.0']]));
		file_put_contents($this->root . '/lib/Settings/register.d/10-good.json', json_encode(['x' => 1]));

		[$data, , $complete] = $this->loader()->load('demo');

		$this->assertSame(['info' => ['version' => '1.2.0'], 'x' => 1], $data);
		$this->assertTrue($complete);
	}//end testOnlyGoodFragmentsAreComplete()

	/**
	 * Lists concatenate, maps merge key-by-key, and a map over a list recurses.
	 *
	 * @return void
	 */
	public function testDeepMergeConcatenatesListsAndMergesMaps(): void {
		$merged = RegisterDocumentLoader::deepMergeConfig(
			['list' => [1, 2], 'empty' => [], 'map' => ['a' => 1, 'b' => ['x' => 1]], 'mixed' => [1]],
			['list' => [3], 'empty' => [9], 'map' => ['b' => ['y' => 2]], 'mixed' => ['k' => 'v']]
		);

		$this->assertSame(
			[
				'list' => [1, 2, 3],
				'empty' => [9],
				'map' => ['a' => 1, 'b' => ['x' => 1, 'y' => 2]],
				'mixed' => [0 => 1, 'k' => 'v'],
			],
			$merged
		);
	}//end testDeepMergeConcatenatesListsAndMergesMaps()
}//end class
