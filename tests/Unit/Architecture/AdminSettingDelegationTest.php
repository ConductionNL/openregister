<?php

/**
 * Every admin-only endpoint is delegated through the OpenRegister admin setting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Architecture guard for the admin-setting delegation id, no feature behaviour.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\AppHost\Controller\GenericSettingsControllerBase;
use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;
use OCA\OpenRegister\Controller\ActionRightsController;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Nextcloud's security middleware matches the `settings` argument of
 * `#[AuthorizedAdminSetting]` against the settings an administrator delegated
 * to a group. Controllers write it through the Kernel id
 * `AdminSettingDelegation::OPEN_REGISTER_ADMIN`, so they do not name the
 * settings panel class. This guard reads every attribute by reflection and
 * asserts the string the middleware sees is still `OpenRegisterAdmin::class`.
 *
 * The one other value is the AppHost generic settings base, which hosted apps
 * extend: it is delegated through the hosted app's own panel.
 */
class AdminSettingDelegationTest extends TestCase {

	/**
	 * Controllers whose attributes name another setting, with that setting.
	 *
	 * @var array<class-string, class-string>
	 */
	private const OTHER_SETTINGS = [
		GenericSettingsControllerBase::class => GenericAdminSettings::class,
	];

	/**
	 * Every `#[AuthorizedAdminSetting]` as `Class::method => settings`.
	 *
	 * @return array<string, string>
	 */
	private function delegations(): array {
		$found = [];
		foreach (ControllerSources::files() as $file) {
			$class = $this->classIn(file: $file);
			if ($class === null || class_exists($class) === false) {
				continue;
			}

			$methods = (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC);
			foreach ($methods as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}

				foreach ($method->getAttributes(AuthorizedAdminSetting::class) as $attribute) {
					$arguments = $attribute->getArguments();
					$found[$class . '::' . $method->getName()] = (string) ($arguments['settings'] ?? $arguments[0] ?? '');
				}
			}
		}

		ksort($found);
		return $found;
	}//end delegations()

	/**
	 * The fully qualified class a controller file declares, or null.
	 *
	 * @param string $file Absolute path of the PHP file.
	 *
	 * @return string|null
	 */
	private function classIn(string $file): ?string {
		$source = (string) file_get_contents($file);
		if (preg_match('/^namespace\s+([^;]+);/m', $source, $match) !== 1) {
			return null;
		}

		return $match[1] . '\\' . basename($file, '.php');
	}//end classIn()

	/**
	 * Every admin-only endpoint is delegated through the OpenRegister admin setting.
	 *
	 * @return void
	 */
	public function testEveryAttributeNamesTheOpenRegisterAdminSetting(): void {
		$wrong = [];
		foreach ($this->delegations() as $endpoint => $settings) {
			$class    = explode('::', $endpoint)[0];
			$expected = (self::OTHER_SETTINGS[$class] ?? OpenRegisterAdmin::class);
			if ($settings !== $expected) {
				$wrong[] = $endpoint . ' names ' . $settings . ', expected ' . $expected;
			}
		}

		$this->assertSame(expected: [], actual: $wrong);
	}//end testEveryAttributeNamesTheOpenRegisterAdminSetting()

	/**
	 * The scan sees the action-rights endpoints, so it does not pass on an empty list.
	 *
	 * @return void
	 */
	public function testTheScanSeesTheActionRightsEndpoints(): void {
		$delegations = $this->delegations();

		$this->assertSame(expected: OpenRegisterAdmin::class, actual: ($delegations[ActionRightsController::class . '::index'] ?? null));
		$this->assertSame(expected: OpenRegisterAdmin::class, actual: ($delegations[ActionRightsController::class . '::update'] ?? null));
	}//end testTheScanSeesTheActionRightsEndpoints()

	/**
	 * Every class with another setting still carries it, so the exemption list stays true.
	 *
	 * @return void
	 */
	public function testTheExemptionsAreStillInUse(): void {
		$classes = array_map(
			static fn (string $endpoint): string => explode('::', $endpoint)[0],
			array_keys($this->delegations())
		);

		foreach (array_keys(self::OTHER_SETTINGS) as $class) {
			$this->assertContains(needle: $class, haystack: $classes);
		}
	}//end testTheExemptionsAreStillInUse()
}//end class
