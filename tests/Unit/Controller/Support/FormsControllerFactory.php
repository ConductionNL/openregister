<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FormsController;

/**
 * Builds FormsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FormsControllerFactory {
	/**
	 * The constructor arguments of FormsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param FormDefinitionResolver $resolver Constructor argument.
	 * @param FormSubmitService $submitter Constructor argument.
	 * @param FormDestinationValidator $validator Constructor argument.
	 * @param FormUploadStore $uploads Constructor argument.
	 * @param SchemaMapper $schemas Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Form\FormDefinitionResolver $resolver,
		\OCA\OpenRegister\Service\Form\FormSubmitService $submitter,
		\OCA\OpenRegister\Service\Form\FormDestinationValidator $validator,
		\OCA\OpenRegister\Service\Form\FormUploadStore $uploads,
		\OCA\OpenRegister\Db\SchemaMapper $schemas,
		\OCP\IUserSession $userSession,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\OCP\IL10N $l10n,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\FormsControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\FlowNodeRunMappers(
					schemas: $schemas,
				),
				domain: new \OCA\OpenRegister\Controller\Support\FormsDomain(
					resolver: $resolver,
					submitter: $submitter,
					validator: $validator,
					uploads: $uploads,
				),
				platform: new \OCA\OpenRegister\Controller\Support\FormsPlatform(
					userSession: $userSession,
					throttler: $throttler,
					l10n: $l10n,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build FormsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FormsController
	 */
	public static function create(mixed ...$args): FormsController {
		return new FormsController(...self::args(...$args));
	}
}
