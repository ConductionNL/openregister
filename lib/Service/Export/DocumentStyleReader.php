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

use OCA\OpenRegister\Service\Export\Odt\OdtFont;
use OCA\OpenRegister\Service\Export\Odt\OdtLogo;
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
	 * The service class thematiq keeps its uploaded document logo in.
	 *
	 * @var string
	 */
	public const ASSET_SERVICE = 'OCA\\Thematiq\\Service\\DocumentAssetService';

	/**
	 * The service class thematiq keeps its uploaded fonts in.
	 *
	 * @var string
	 */
	public const FONT_SERVICE = 'OCA\\Thematiq\\Service\\FontService';

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

	/**
	 * The profile's logo, read in-process and checked for embedding, or null.
	 *
	 * The uploaded document logo is read from thematiq's asset store; a token
	 * set's bundled logo (a URL under thematiq's `img/`) is read from thematiq's
	 * install folder. Nothing is fetched over HTTP. A logo that cannot be read
	 * or does not pass {@see OdtLogo::fromBytes()} gives null. Never throws.
	 *
	 * @param array<string, mixed>|null $profile The profile {@see forUser()} returned, or null.
	 *
	 * @return OdtLogo|null The logo, or null.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public function logo(?array $profile): ?OdtLogo {
		$logo = ($profile['logo'] ?? null);
		if (is_array($logo) === false || is_string($logo['url'] ?? null) === false) {
			return null;
		}

		try {
			$bytes = $this->uploadedLogo();
			if ($bytes === null) {
				$bytes = $this->bundledLogo(url: $logo['url']);
			}
		} catch (Throwable $e) {
			$this->logger->debug('DocumentStyleReader: the document logo could not be read: ' . $e->getMessage());
			return null;
		}

		if ($bytes === null) {
			return null;
		}

		$checked = OdtLogo::fromBytes(bytes: $bytes);
		if ($checked === null) {
			$this->logger->debug('DocumentStyleReader: the document logo is too large or not a safe PNG, JPEG or SVG; the export has no logo.');
		}

		return $checked;
	}//end logo()

	/**
	 * The profile's uploaded fonts that can be embedded, per role, read in-process.
	 *
	 * A role's font is read from thematiq's font store by the id in its URL
	 * (`/thematiq/fonts/<id>.woff2`); nothing is fetched over HTTP. A role
	 * without an uploaded font, a font that cannot be read, or one that
	 * {@see OdtFont::fromBytes()} refuses (WOFF2 among them) is left out, so that
	 * role keeps the export's default font. Never throws.
	 *
	 * @param array<string, mixed>|null $profile The profile {@see forUser()} returned, or null.
	 *
	 * @return list<OdtFont> The fonts, heading first.
	 *
	 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
	 */
	public function fonts(?array $profile): array {
		$section = ($profile['fonts'] ?? null);
		if (is_array($section) === false) {
			return [];
		}

		$fonts = [];
		foreach ([OdtFont::ROLE_HEADING, OdtFont::ROLE_BODY] as $role) {
			$entry = ($section[$role] ?? null);
			if (is_array($entry) === false || is_string($entry['url'] ?? null) === false) {
				continue;
			}

			$font = $this->font(role: $role, family: ($entry['family'] ?? null), url: $entry['url']);
			if ($font !== null) {
				$fonts[] = $font;
			}
		}

		return $fonts;
	}//end fonts()

	/**
	 * One role's uploaded font, or null when it cannot be read or embedded.
	 *
	 * @param string $role   `heading` or `body`.
	 * @param mixed  $family The profile's family name.
	 * @param string $url    The profile's font URL.
	 *
	 * @return OdtFont|null
	 */
	private function font(string $role, mixed $family, string $url): ?OdtFont {
		$path = (string) parse_url($url, PHP_URL_PATH);
		if (preg_match('#/' . self::THEMATIQ_APP . '/fonts/(custom-[a-z0-9-]+)\.woff2$#', $path, $matches) !== 1
			|| class_exists(self::FONT_SERVICE) === false
		) {
			return null;
		}

		try {
			$bytes = $this->container->get(self::FONT_SERVICE)->readFontBytes($matches[1]);
		} catch (Throwable $e) {
			$this->logger->debug('DocumentStyleReader: the ' . $role . ' font could not be read: ' . $e->getMessage());
			return null;
		}

		if (is_string($bytes) === false) {
			return null;
		}

		$font = OdtFont::fromBytes(role: $role, family: $family, bytes: $bytes);
		if ($font === null) {
			$this->logger->debug(
				'DocumentStyleReader: the ' . $role . ' font is not a TrueType, OpenType or WOFF font that can be embedded; that role keeps the default font.'
			);
		}

		return $font;
	}//end font()

	/**
	 * The uploaded document logo from thematiq's asset store, or null when there is none.
	 *
	 * @return string|null The bytes, or null.
	 */
	private function uploadedLogo(): ?string {
		if (class_exists(self::ASSET_SERVICE) === false) {
			return null;
		}

		$asset = $this->container->get(self::ASSET_SERVICE)->read('logo');
		if (is_array($asset) === false || is_string($asset['bytes'] ?? null) === false) {
			return null;
		}

		return $asset['bytes'];
	}//end uploadedLogo()

	/**
	 * A token set's bundled logo, read from thematiq's `img/` folder, or null.
	 *
	 * The URL must point under thematiq's `img/`; the resolved file must stay
	 * inside that folder and be no larger than {@see OdtLogo::MAX_BYTES}.
	 *
	 * @param string $url The profile's logo URL.
	 *
	 * @return string|null The bytes, or null.
	 */
	private function bundledLogo(string $url): ?string {
		$path = (string) parse_url($url, PHP_URL_PATH);
		$marker = '/' . self::THEMATIQ_APP . '/img/';
		$position = strpos($path, $marker);
		if ($position === false) {
			return null;
		}

		$folder = realpath($this->appManager->getAppPath(self::THEMATIQ_APP) . '/img');
		$file = realpath($folder . '/' . rawurldecode(substr($path, ($position + strlen($marker)))));
		if ($folder === false || $file === false || str_starts_with($file, $folder . DIRECTORY_SEPARATOR) === false
			|| is_file($file) === false || filesize($file) > OdtLogo::MAX_BYTES
		) {
			return null;
		}

		$bytes = file_get_contents($file);
		if ($bytes === false) {
			return null;
		}

		return $bytes;
	}//end bundledLogo()
}//end class
