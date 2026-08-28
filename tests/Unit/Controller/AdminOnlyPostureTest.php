<?php

declare(strict_types=1);

/*
 * Endpoints whose bodies enforce admin must not declare otherwise.
 *
 * CredentialController::registerApp, FederatedConfigController::trust and
 * ::setTrust each carried #[NoAdminRequired] while rejecting non-admins in the
 * body. Nothing broke, because the body caught what the attribute let through —
 * which is exactly why it survived: the contradiction was invisible at runtime
 * and only showed up as a mechanical finding.
 *
 * The danger in that shape is that the in-body check is now the ONLY thing
 * standing between a non-admin and the endpoint. Delete or short-circuit it —
 * during a refactor, or by an early return added above it — and the declared
 * posture says the endpoint was always meant to be open, so nothing reads as a
 * regression. Removing #[NoAdminRequired] puts the middleware back in front of
 * it; this test keeps it that way.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Controller\FederatedConfigController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AdminOnlyPostureTest extends TestCase {

	/**
	 * Endpoints that must be reachable by administrators only.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function adminOnlyEndpoints(): array {
		return [
			'Credential::registerApp' => [CredentialController::class, 'registerApp'],
			'FederatedConfig::trust' => [FederatedConfigController::class, 'trust'],
			'FederatedConfig::setTrust' => [FederatedConfigController::class, 'setTrust'],
		];
	}//end adminOnlyEndpoints()

	/**
	 * The endpoint must not carry #[NoAdminRequired].
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDoesNotDeclareNoAdminRequired(string $controller, string $method): void {
		$attributes = (new ReflectionMethod($controller, $method))->getAttributes(NoAdminRequired::class);

		$this->assertSame(
			[],
			$attributes,
			$controller . '::' . $method . '() carries #[NoAdminRequired] while its body requires an admin. '
			. 'That leaves the in-body check as the only barrier, so losing it in a refactor would not '
			. 'read as a regression. Let the middleware reject instead.'
		);
	}//end testEndpointDoesNotDeclareNoAdminRequired()

	/**
	 * The endpoint must not drop authentication entirely.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointIsNotAPublicPage(string $controller, string $method): void {
		$attributes = (new ReflectionMethod($controller, $method))->getAttributes(PublicPage::class);

		$this->assertSame(
			[],
			$attributes,
			$controller . '::' . $method . '() carries #[PublicPage], which removes authentication entirely.'
		);
	}//end testEndpointIsNotAPublicPage()

	/**
	 * The legacy docblock form must be absent too.
	 *
	 * Nextcloud honours `@NoAdminRequired` at docblock-tag position exactly like
	 * the attribute, so checking only attributes would pass over an endpoint
	 * reopened the old way. Tag position means preceded on its line by nothing
	 * but whitespace and comment punctuation, so prose naming the tag in a
	 * sentence does not match.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDoesNotCarryTheLegacyDocblockTag(string $controller, string $method): void {
		$doc = (new ReflectionMethod($controller, $method))->getDocComment();
		if ($doc === false) {
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertDoesNotMatchRegularExpression(
			'/^[[:space:]]*(\/?\*+[[:space:]]*)@NoAdminRequired\b/m',
			$doc,
			$controller . '::' . $method . '() declares @NoAdminRequired at docblock-tag position, which '
			. 'Nextcloud honours exactly like the attribute.'
		);
	}//end testEndpointDoesNotCarryTheLegacyDocblockTag()

	/**
	 * The admin-only posture must be stated with a reason.
	 *
	 * @param class-string $controller The controller class.
	 * @param string $method The method name.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyEndpoints
	 */
	public function testEndpointDeclaresItsAdminOnlyPostureWithAReason(string $controller, string $method): void {
		$doc = (new ReflectionMethod($controller, $method))->getDocComment();

		$this->assertIsString($doc, $controller . '::' . $method . '() has no docblock to declare a posture in.');
		$this->assertMatchesRegularExpression(
			'/^[[:space:]]*(\/?\*+[[:space:]]*)@auth[[:space:]]+admin-only[[:space:]]+.{20,}/m',
			$doc,
			$controller . '::' . $method . '() does not declare `@auth admin-only <reason>`. Admin-only by '
			. 'Nextcloud default is indistinguishable from a forgotten attribute; say which it is.'
		);
	}//end testEndpointDeclaresItsAdminOnlyPostureWithAReason()
}//end class
