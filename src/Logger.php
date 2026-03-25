<?php declare(strict_types=1);

/*
 * Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2 or any later
 * version. You should have received a copy of the GPL license along with this
 * program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Plugin\SubqueryResolver;

final class Logger
{
	private static ?bool $debugEnabled = null;
	private const LOG_FILE = '/tmp/subquery-plugin-debug.log';

	private static function isDebugEnabled(): bool
	{
		if (self::$debugEnabled === null) {
			$cmdline = @file_get_contents('/proc/self/cmdline');
			if ($cmdline !== false) {
				// Arguments are separated by null bytes in /proc/self/cmdline
				$args = explode("\0", $cmdline);
				self::$debugEnabled = in_array('--log-level=debugvv', $args, true)
					|| in_array('--log-level=debugv', $args, true)
					|| in_array('--log-level=debug', $args, true);
			} else {
				self::$debugEnabled = false;
			}
		}
		return self::$debugEnabled;
	}

	public static function debug(string $message): void
	{
		if (!self::isDebugEnabled()) {
			return;
		}
		file_put_contents(self::LOG_FILE, $message, FILE_APPEND);
	}
}
