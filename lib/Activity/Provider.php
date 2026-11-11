<?php

/**
 * OpenRegister Activity Provider.
 *
 * Provider for parsing and rendering OpenRegister activity events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Activity
 * @package  OCA\OpenRegister\Activity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Activity;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;

/**
 * Activity provider for parsing OpenRegister events.
 */
class Provider implements IProvider {
	/**
	 * Subjects that are handled by this provider.
	 *
	 * @var string[]
	 */
	private const HANDLED_SUBJECTS = [
		'object_created',
		'object_updated',
		'object_deleted',
		'register_created',
		'register_updated',
		'register_deleted',
		'schema_created',
		'schema_updated',
		'schema_deleted',
	];

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator          $urlGenerator   The URL generator.
	 * @param ProviderSubjectHandler $subjectHandler The subject handler, which also translates.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly ProviderSubjectHandler $subjectHandler,
	) {
	}//end __construct()

	/**
	 * Parse an activity event into a human-readable format.
	 *
	 * @param string $language The language code.
	 * @param IEvent $event The event to parse.
	 * @param ?IEvent $previousEvent The previous event or null.
	 *
	 * @return IEvent The parsed event.
	 *
	 * @throws UnknownActivityException If the event cannot be parsed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $previousEvent required by IProvider interface
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		$this->assertHandled(event: $event);
		$this->subjectHandler->applySubjectText(event: $event, language: $language);
		$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(AppIdentity::APP_ID, 'app-dark.svg')));
		return $event;
	}//end parse()

	/**
	 * Refuse an event this provider does not render.
	 *
	 * @param IEvent $event The event to check.
	 *
	 * @return void
	 *
	 * @throws UnknownActivityException If the event is another app's or carries an unknown subject.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	private function assertHandled(IEvent $event): void {
		if ($event->getApp() !== AppIdentity::APP_ID) {
			throw new UnknownActivityException();
		}

		if (in_array($event->getSubject(), self::HANDLED_SUBJECTS, true) === false) {
			throw new UnknownActivityException();
		}
	}//end assertHandled()
}//end class
