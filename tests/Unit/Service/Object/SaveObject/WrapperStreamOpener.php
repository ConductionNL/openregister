<?php

/**
 * A URL opener for tests that serve `http://` from a userspace stream wrapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\File\UrlStreamOpener;

/**
 * Opens a URL the way the shared opener does, through whatever wrapper serves `http://`.
 *
 * A userspace wrapper carries no HTTP status line, so an opened stream is answered as
 * status 200, as a served file would be; a stream that does not open answers as the
 * real opener does.
 */
class WrapperStreamOpener extends UrlStreamOpener {

	/**
	 * Open the URL, answering 200 when it opens.
	 *
	 * @param string $url The URL.
	 *
	 * @return array{stream: resource|null, status: int|null}
	 */
	public function open(string $url): array {
		$stream = @fopen($url, 'rb');
		if ($stream === false) {
			return ['stream' => null, 'status' => null];
		}

		return ['stream' => $stream, 'status' => 200];
	}//end open()
}//end class
