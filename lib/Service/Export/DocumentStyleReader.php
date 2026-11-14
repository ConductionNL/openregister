<?php

/**
 * DocumentStyleReader — read thematiq's document house style for a PDF export.
 *
 * Thematiq, when installed, publishes `OCA\Thematiq\Service\DocumentStyleService`
 * with `forUser(?string $uid): array` returning the logo, colours, fonts and
 * footer the export should wear (its design D1). This reader duck-types that
 * service: it checks the app is enabled and the class exists, resolves it from
 * the server container and calls it. Any miss or throw returns `null` and logs
 * once, so the export never fails because of the house style
 * (export-pdf-house-style, design D-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads thematiq's document house-style profile, never failing the export.
 */
class DocumentStyleReader {

	/**
	 * The optional app that supplies the house style.
	 *
	 * @var string
	 */
	public const THEMATIQ_APP = 'thematiq';

	/**
	 * The service class thematiq publishes for the profile.
	 *
	 * @var string
	 */
	public const STYLE_SERVICE = 'OCA\\Thematiq\\Service\\DocumentStyleService';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Whether thematiq is enabled.
	 * @param ContainerInterface $container  The server container that resolves thematiq's service.
	 * @param LoggerInterface    $logger     Logs a miss or a throw, once.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The house-style profile for the requesting user, or `null` when thematiq
	 * is not installed, not enabled, or cannot answer.
	 *
	 * @param string|null $uid The requesting user, or null for the instance default.
	 *
	 * @return array<string, mixed>|null The profile, or null.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function forUser(?string $uid): ?array {
		if ($this->appManager->isEnabledForUser(appId: self::THEMATIQ_APP) === false) {
			return null;
		}

		if (class_exists(self::STYLE_SERVICE) === false) {
			$this->logger->debug('DocumentStyleReader: thematiq is enabled but its DocumentStyleService class is missing.');
			return null;
		}

		try {
			$service = $this->container->get(self::STYLE_SERVICE);
			$profile = $service->forUser($uid);
		} catch (Throwable $e) {
			$this->logger->debug('DocumentStyleReader: the document house style could not be read: ' . $e->getMessage());
			return null;
		}

		if (is_array($profile) === false) {
			return null;
		}

		return $profile;
	}//end forUser()
}//end class
