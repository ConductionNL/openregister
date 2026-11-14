<?php

/**
 * AppHost GenericActionAuthService — reading, writing and extending the stored matrix.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The stored matrix: malformed entries dropped on read, normalised on write,
 * and addMissing() never touching an entry an administrator already set.
 */
#[CoversClass(GenericActionAuthService::class)]
class GenericActionAuthServiceMatrixTest extends TestCase {

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * A service over a stored matrix.
	 *
	 * @param string $stored The stored JSON.
	 *
	 * @return GenericActionAuthService
	 */
	private function service(string $stored): GenericActionAuthService {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->with('myapp', 'actions', '{}')
			->willReturn($stored);

		return new GenericActionAuthService('myapp', $this->appConfig, $this->createMock(IGroupManager::class));
	}//end service()

	public function testGetMatrixDropsMalformedEntriesAndCleansGroups(): void {
		$svc = $this->service(
			'{"item.publish":["editors","","editors",5,"reviewers"],"item.delete":"admin","0":["x"]}'
		);

		$this->assertSame(['item.publish' => ['editors', 'reviewers']], $svc->getMatrix());
		$this->assertSame(['item.publish'], $svc->getActions());
	}//end testGetMatrixDropsMalformedEntriesAndCleansGroups()

	public function testAJsonScalarReadsAsAnEmptyMatrix(): void {
		$svc = $this->service('"just text"');

		$this->assertSame([], $svc->getMatrix());
		$this->assertSame(['admin'], $svc->getAllowedGroups('item.publish'));
	}//end testAJsonScalarReadsAsAnEmptyMatrix()

	public function testSetMatrixDropsEmptyAndDuplicateGroups(): void {
		$svc = $this->service('{}');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('myapp', 'actions', '{"item.publish":["editors","reviewers"],"item.delete":[]}');

		$svc->setMatrix(['item.publish' => ['editors', '', 'editors', 'reviewers'], 'item.delete' => ['']]);
	}//end testSetMatrixDropsEmptyAndDuplicateGroups()

	public function testAddMissingWritesOnlyAbsentActions(): void {
		$svc = $this->service('{"item.publish":["editors"]}');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('myapp', 'actions', '{"item.publish":["editors"],"flow.run":["@authenticated"]}');

		$added = $svc->addMissing(['item.publish' => ['admin'], 'flow.run' => [GenericActionAuthService::EVERYONE]]);

		$this->assertSame(['flow.run'], $added);
	}//end testAddMissingWritesOnlyAbsentActions()

	public function testAddMissingWritesNothingWhenEveryActionIsPresent(): void {
		$svc = $this->service('{"item.publish":["editors"]}');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->assertSame([], $svc->addMissing(['item.publish' => ['admin']]));
	}//end testAddMissingWritesNothingWhenEveryActionIsPresent()
}//end class
