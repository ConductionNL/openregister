<?php

/**
 * Regression: a translatable field left its placeholder in the subject.
 *
 * Seen on :8099 and cloud.conduction.nl on 9 October 2026: "Task changed:
 * {{subject}}" and "Lead changed: {{title}}". A translatable property is
 * stored as a language map (`{"nl": "Bel klant"}`), and
 * NotificationTemplating::interpolate() skipped every value that is not a
 * scalar. A language map now renders in the recipient's language, then the
 * register's default language, then its first value.
 *
 * The templating, the dispatcher, the register and the object are real; only
 * the mappers and Nextcloud services are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Locks how a language map fills a placeholder.
 */
class TranslatablePlaceholderTest extends TestCase {

	private function templating(): NotificationTemplating {
		return new NotificationTemplating($this->createMock(LoggerInterface::class));
	}//end templating()

	public function testALanguageMapFillsThePlaceholderInTheRecipientsLanguage(): void {
		$text = $this->templating()->interpolate(
			'Task changed: {{subject}}',
			['subject' => ['nl' => 'Bel klant', 'en' => 'Call client']],
			[],
			['en', 'nl']
		);

		$this->assertSame('Task changed: Call client', $text);
	}//end testALanguageMapFillsThePlaceholderInTheRecipientsLanguage()

	public function testARegionalLanguageFallsBackToItsBase(): void {
		$text = $this->templating()->interpolate('{{title}}', ['title' => ['nl' => 'Offerte', 'en' => 'Quote']], [], ['en-GB']);

		$this->assertSame('Quote', $text);
	}//end testARegionalLanguageFallsBackToItsBase()

	public function testWithoutAChainLanguageTheFirstValueShows(): void {
		$text = $this->templating()->interpolate('Lead changed: {{title}}', ['title' => ['nl' => 'Offerte']], [], ['de']);

		$this->assertSame('Lead changed: Offerte', $text);
		$this->assertSame([], $this->templating()->unanswered('Lead changed: {{title}}', ['title' => ['nl' => 'Offerte']], []));
	}//end testWithoutAChainLanguageTheFirstValueShows()

	public function testAnObjectThatIsNotALanguageMapStaysUnanswered(): void {
		$data = ['address' => ['street' => 'Dam', 'number' => 1], 'tags' => ['a', 'b']];

		$this->assertSame('{{address}} {{tags}}', $this->templating()->interpolate('{{address}} {{tags}}', $data, [], ['nl']));
		$this->assertSame(['address', 'tags'], $this->templating()->unanswered('{{address}} {{tags}}', $data, []));
	}//end testAnObjectThatIsNotALanguageMapStaysUnanswered()

	public function testAMappedValueIsStillEscaped(): void {
		$text = $this->templating()->interpolate('{{title}}', ['title' => ['nl' => '<b>Offerte</b>']], [], ['nl']);

		$this->assertSame('&lt;b&gt;Offerte&lt;/b&gt;', $text);
	}//end testAMappedValueIsStillEscaped()

	public function testTheDispatcherFallsBackToTheRegistersDefaultLanguage(): void {
		// A recipient whose language the map lacks reads the register's
		// default language, not just whichever value came first.
		$register = new Register();
		$register->setId(20);
		$register->setLanguages(['nl', 'en']);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$dispatcher = new AnnotationNotificationDispatcher(
			schemaMapper: $this->createMock(SchemaMapper::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			mailer: $this->createMock(IMailer::class),
			activityManager: $this->createMock(IActivityManager::class),
			httpClient: $this->createMock(IClientService::class),
			serverContainer: $this->createMock(IServerContainer::class),
			registerMapper: $registerMapper,
			templating: $this->templating()
		);

		$object = new ObjectEntity();
		$object->setRegister('20');
		$languages = (new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveRegisterLanguages'))->invoke($dispatcher, $object);
		(new ReflectionProperty(AnnotationNotificationDispatcher::class, 'registerLanguages'))->setValue($dispatcher, $languages);

		$subject = (new ReflectionMethod(AnnotationNotificationDispatcher::class, 'resolveLocalizedSubject'))->invoke(
			$dispatcher,
			['en' => 'Task changed: {{subject}}', 'nl' => 'Taak gewijzigd: {{subject}}'],
			'fr',
			['subject' => ['en' => 'Call client', 'nl' => 'Bel klant']],
			[],
			'task'
		);

		$this->assertSame('Taak gewijzigd: Bel klant', $subject);
	}//end testTheDispatcherFallsBackToTheRegistersDefaultLanguage()
}//end class
