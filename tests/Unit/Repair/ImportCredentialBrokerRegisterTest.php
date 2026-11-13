<?php

/**
 * Unit tests for ImportCredentialBrokerRegister.
 *
 * The step materialises the shipped credential-broker register descriptor,
 * including the organisation-scope properties of the credential schema, and it
 * never lets a failure escape: it runs on install and upgrade, where an
 * escaping exception aborts the app.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-broker-organisation-scope/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\AbstractRegisterImportStep;
use OCA\OpenRegister\Repair\ImportCredentialBrokerRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ImportCredentialBrokerRegister::class)]
#[CoversClass(AbstractRegisterImportStep::class)]
#[CoversClass(RegisterDescriptorImport::class)]
class ImportCredentialBrokerRegisterTest extends TestCase {

	/**
	 * @var ConfigurationService&MockObject
	 */
	private ConfigurationService&MockObject $configurationService;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->configurationService = $this->createMock(ConfigurationService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->output = $this->createMock(IOutput::class);

	}//end setUp()

	/**
	 * The step, with the app living at a path.
	 *
	 * @param string $appPath Where the app is installed.
	 *
	 * @return ImportCredentialBrokerRegister
	 */
	private function step(string $appPath): ImportCredentialBrokerRegister {
		$this->appManager->method('getAppPath')->with('openregister')->willReturn($appPath);

		return new ImportCredentialBrokerRegister(
			import: new RegisterDescriptorImport(
				configurationService: $this->configurationService,
				appManager: $this->appManager,
				logger: $this->createMock(LoggerInterface::class)
			)
		);

	}//end step()

	/**
	 * The app root of this checkout.
	 *
	 * @return string
	 */
	private function appRoot(): string {
		return (string)realpath(__DIR__ . '/../../..');

	}//end appRoot()

	/**
	 * The shipped descriptor reaches the importer with the credential schema
	 * carrying `scope` (personal|organisation, default personal) and the
	 * optional `organisation` UUID, unforced, under openregister.
	 *
	 * @return void
	 */
	public function testTheShippedDescriptorCarriesTheOrganisationScope(): void {
		$seen = null;
		$this->configurationService->expects($this->once())
			->method('importFromApp')
			->with(
				$this->equalTo('openregister'),
				$this->callback(
					static function (array $data) use (&$seen): bool {
						$seen = $data;
						return true;
					}
				),
				$this->equalTo('1.0.0'),
				$this->equalTo(false)
			);
		$this->output->expects($this->once())->method('info')
			->with($this->stringContains('Credential-broker register imported'));
		$this->output->expects($this->never())->method('warning');

		$this->step(appPath: $this->appRoot())->run($this->output);

		$this->assertIsArray($seen);
		$this->assertArrayHasKey('credential-broker', $seen['components']['registers']);
		$properties = $seen['components']['schemas']['brokeredcredential']['properties'];
		$this->assertSame(['personal', 'organisation'], $properties['scope']['enum']);
		$this->assertSame('personal', $properties['scope']['default']);
		$this->assertSame('string', $properties['organisation']['type']);
		$required = ($seen['components']['schemas']['brokeredcredential']['required'] ?? []);
		$this->assertNotContains('organisation', $required, 'organisation is only required for organisation scope');

	}//end testTheShippedDescriptorCarriesTheOrganisationScope()

	/**
	 * The descriptor and the credential schema were bumped past their first
	 * release when the organisation scope was added, so an upgrade re-imports
	 * the schema instead of treating it as unchanged.
	 *
	 * @return void
	 */
	public function testTheDescriptorAndSchemaVersionsWereBumped(): void {
		$data = json_decode((string)file_get_contents($this->appRoot() . '/lib/Settings/credential_broker_register.json'), true);

		$this->assertTrue(version_compare($data['info']['version'], '1.0.0', '>'), 'descriptor version ' . $data['info']['version']);
		$schemaVersion = (string)$data['components']['schemas']['brokeredcredential']['version'];
		$this->assertTrue(version_compare($schemaVersion, '1.0.0', '>'), 'schema version ' . $schemaVersion);

	}//end testTheDescriptorAndSchemaVersionsWereBumped()

	/**
	 * A missing descriptor is a warning, not an import.
	 *
	 * @return void
	 */
	public function testAMissingDescriptorWarnsAndImportsNothing(): void {
		$this->configurationService->expects($this->never())->method('importFromApp');
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('Credential-broker register descriptor not found'));

		$this->step(appPath: sys_get_temp_dir() . '/no-such-openregister-' . getmypid())->run($this->output);

	}//end testAMissingDescriptorWarnsAndImportsNothing()

	/**
	 * A descriptor that is not JSON is a warning, not an import.
	 *
	 * @return void
	 */
	public function testADescriptorThatIsNotJsonWarns(): void {
		$root = sys_get_temp_dir() . '/or-cb-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($root . '/lib/Settings', 0777, true);
		file_put_contents($root . '/lib/Settings/credential_broker_register.json', '{not json');

		$this->configurationService->expects($this->never())->method('importFromApp');
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('not valid JSON'));

		try {
			$this->step(appPath: $root)->run($this->output);
		} finally {
			unlink($root . '/lib/Settings/credential_broker_register.json');
			rmdir($root . '/lib/Settings');
			rmdir($root . '/lib');
			rmdir($root);
		}

	}//end testADescriptorThatIsNotJsonWarns()

	/**
	 * A failing import is caught and reported, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingImportIsReportedNotThrown(): void {
		$this->configurationService->method('importFromApp')->willThrowException(new RuntimeException('database gone'));
		$this->output->expects($this->never())->method('info');
		$this->output->expects($this->once())->method('warning')
			->with($this->stringContains('Credential-broker register import skipped: database gone'));

		$this->step(appPath: $this->appRoot())->run($this->output);

	}//end testAFailingImportIsReportedNotThrown()

	/**
	 * The step names itself in the repair output.
	 *
	 * @return void
	 */
	public function testTheStepNamesTheRegister(): void {
		$this->assertSame(
			'Import OpenRegister credential-broker register (credential schema + example objects)',
			$this->step(appPath: $this->appRoot())->getName()
		);

	}//end testTheStepNamesTheRegister()
}//end class
