<?php

/**
 * Stand-ins for phpseclib4, for tests that run in a separate process.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace phpseclib4\Crypt {

	class PublicKeyLoader {

		/**
		 * Wrap the key text so a test can see it passed through here.
		 *
		 * @param string $key The key text.
		 *
		 * @return string The loaded key.
		 */
		public static function load($key): string {
			return 'loaded:' . $key;
		}//end load()
	}//end class
}

namespace phpseclib4\Net {

	class SFTP {

		public const SOURCE_LOCAL_FILE = 1;

		/**
		 * Every call on any connection, as `[method, ...args]`.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		public static array $calls = [];

		public static bool $loginResult = true;

		public static bool $putResult = true;

		public static mixed $size = null;

		public function __construct(string $host, int $port) {
			self::$calls[] = ['connect', $host, $port];
		}

		public function getServerPublicHostKey(): mixed {
			self::$calls[] = ['hostKey'];
			return 'ssh-ed25519 ' . base64_encode('server key');
		}

		public function login(string $username, mixed $credential): bool {
			self::$calls[] = ['login', $username, $credential];
			return self::$loginResult;
		}

		public function put(string $remote, string $local, int $mode): void {
			self::$calls[] = ['put', $remote, $local, $mode];
			if (self::$putResult === false) {
				throw new \RuntimeException('disk full');
			}
		}

		public function filesize(string $remote): mixed {
			self::$calls[] = ['filesize', $remote];
			return self::$size;
		}

		public function pwd(): string {
			self::$calls[] = ['pwd'];
			return '/';
		}
	}//end class
}
