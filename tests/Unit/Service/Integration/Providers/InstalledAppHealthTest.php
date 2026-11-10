<?php

/**
 * Health of the providers that need one Nextcloud app.
 *
 * Each provider is built over mocks whose boolean methods all answer the same
 * way, so its backing app reads as available in one run and missing in the
 * other. Available is ok with no message; missing is unavailable with the
 * provider's own message.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the health descriptor shape shared by the native-app providers.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\Providers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Health of the providers that need one Nextcloud app.
 */
class InstalledAppHealthTest extends TestCase {

	/**
	 * Provider class and the message it reports when its app is missing.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function providers(): array {
		return [
			'activity' => [Providers\ActivityProvider::class, 'NC Activity app is not installed'],
			'analytics' => [Providers\AnalyticsProvider::class, 'NC Analytics app is not installed'],
			'bookmarks' => [Providers\BookmarksProvider::class, 'NC Bookmarks app is not installed'],
			'calendar' => [Providers\CalendarProvider::class, 'NC Calendar app is not installed'],
			'collectives' => [Providers\CollectivesProvider::class, 'NC Knowledge app is not installed'],
			'contacts' => [Providers\ContactsProvider::class, 'NC Contacts app is not installed'],
			'cospend' => [Providers\CospendProvider::class, 'NC Cospend app is not installed'],
			'deck' => [Providers\DeckProvider::class, 'NC Deck app is not installed'],
			'email' => [Providers\EmailProvider::class, 'NC Mail app is not installed'],
			'flow' => [Providers\FlowProvider::class, 'NC Flow (workflowengine) app is not installed'],
			'forms' => [Providers\FormsProvider::class, 'NC Forms app is not installed'],
			'maps' => [Providers\MapsProvider::class, 'NC Location app is not installed'],
			'photos' => [Providers\PhotosProvider::class, 'NC Photos app is not installed'],
			'polls' => [Providers\PollsProvider::class, 'NC Polls app is not installed'],
			'talk' => [Providers\TalkProvider::class, 'NC Talk (spreed) is not installed'],
		];
	}//end providers()

	/**
	 * Build a provider whose collaborators' boolean methods all return $flag.
	 *
	 * @param class-string $class The provider class.
	 * @param bool         $flag  What every boolean method answers.
	 *
	 * @return object The provider.
	 */
	private function build(string $class, bool $flag): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			if ((new ReflectionClass($type->getName()))->isFinal() === true) {
				// A dependency bundle: build it from mocks the same way.
				$args[] = $this->build($type->getName(), $flag);
				continue;
			}

			$mock = $this->createMock($type->getName());
			foreach ((new ReflectionClass($type->getName()))->getMethods() as $method) {
				$ret = $method->getReturnType();
				$isBool = ($ret instanceof ReflectionNamedType && $ret->getName() === 'bool');
				$open   = ($method->isPublic() === true && $method->isStatic() === false && $method->isFinal() === false);
				if ($open === true && ($isBool === true || ($ret === null && str_starts_with($method->getName(), 'is') === true))) {
					$mock->method($method->getName())->willReturn($flag);
				}
			}

			$args[] = $mock;
		}

		return new $class(...$args);
	}//end build()

	/**
	 * Available reads ok; missing reads unavailable with the provider's message.
	 *
	 * @param class-string $class   The provider class.
	 * @param string       $missing The missing-app message.
	 *
	 * @return void
	 */
	#[DataProvider('providers')]
	public function testHealthFollowsTheBackingApp(string $class, string $missing): void {
		$this->assertSame(
			['status' => 'ok', 'authStatus' => 'configured', 'message' => null],
			$this->build($class, true)->health()
		);
		$this->assertSame(
			['status' => 'unavailable', 'authStatus' => 'configured', 'message' => $missing],
			$this->build($class, false)->health()
		);
	}//end testHealthFollowsTheBackingApp()
}//end class
