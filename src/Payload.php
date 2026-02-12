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

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Payload class for the Subquery Resolver plugin
 * Detects and parses queries containing subqueries
 */
final class Payload extends BasePayload
{
	public string $query;

	/**
	 * Get description of the plugin
	 * @return string
	 */
	public static function getInfo(): string
	{
		return 'Resolves IN/NOT IN subqueries by executing them separately and injecting results into main query';
	}

	/**
	 * Check if the request matches this plugin
	 * Looks for SELECT queries with IN clause subqueries
	 * Note: Manticore supports FROM clause subqueries, so we only handle IN/NOT IN
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool
	{
		// ALWAYS log to see if we're even being called
		$logFile = '/tmp/subquery-plugin-debug.log';
		$debugInfo = sprintf(
			"[%s] hasMatch() called!\n  command: %s\n  payload: %s\n  error: %s\n  path: %s\n",
			date('Y-m-d H:i:s'),
			isset($request->command) ? $request->command : 'NOT SET',
			isset($request->payload) ? substr($request->payload, 0, 200) : 'NOT SET',
			isset($request->error) ? $request->error : 'NOT SET',
			isset($request->path) ? $request->path : 'NOT SET'
		);
		file_put_contents($logFile, $debugInfo, FILE_APPEND);

		try {
			$query = self::getQuery($request);
			file_put_contents($logFile, "  Extracted query: " . substr($query, 0, 100) . "\n", FILE_APPEND);

			// Check if it's a SELECT query
			if (!preg_match('/^\s*SELECT\s+/i', $query)) {
				file_put_contents($logFile, "  Not a SELECT query\n\n", FILE_APPEND);
				return false;
			}

			// Check for IN clause with subquery pattern: IN (SELECT ...)
			// This matches both IN and NOT IN
			$hasSubquery = preg_match('/\b(?:NOT\s+)?IN\s*\(\s*SELECT\s+/i', $query) > 0;
			file_put_contents($logFile, "  Has subquery: " . ($hasSubquery ? 'YES' : 'NO') . "\n\n", FILE_APPEND);

			return $hasSubquery;
		} catch (\Throwable $e) {
			file_put_contents($logFile, "  ERROR in hasMatch: " . $e->getMessage() . "\n\n", FILE_APPEND);
			return false;
		}
	}

	/**
	 * Create payload from request
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static
	{
		$payload = new static();
		$payload->query = self::getQuery($request);

		return $payload;
	}

	/**
	 * Extract query from request
	 * @param Request $request
	 * @return string
	 */
	protected static function getQuery(Request $request): string
	{
		$payload = $request->payload;

		// Handle different request formats
		if (is_string($payload)) {
			return trim($payload);
		}

		if (is_array($payload) && isset($payload['query'])) {
			return trim($payload['query']);
		}

		return '';
	}
}
