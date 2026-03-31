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
use Manticoresearch\Buddy\Core\Tool\Buddy;

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
		return 'Resolves subqueries (IN/NOT IN and comparison operators) by executing them separately and injecting results';
	}

	/**
	 * Check if the request matches this plugin
	 * Looks for SELECT queries with subqueries in:
	 * - IN/NOT IN clauses: IN (SELECT ...)
	 * - Comparison operators: =, !=, <>, <, >, <=, >= (SELECT ...)
	 * Note: Manticore supports FROM clause subqueries, so we only handle WHERE clause subqueries
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool
	{
		Buddy::debugvv(
			sprintf(
				"[SUBQUERY] [%s] hasMatch() called!  command: %s  payload: %s  error: %s  path: %s",
				date('Y-m-d H:i:s'),
				isset($request->command) ? $request->command : 'NOT SET',
				isset($request->payload) ? substr($request->payload, 0, 500) : 'NOT SET',
				isset($request->error) ? $request->error : 'NOT SET',
				isset($request->path) ? $request->path : 'NOT SET'
			)
		);

		try {
			$query = self::getQuery($request);
			Buddy::debugvv("[SUBQUERY]  Extracted query: " . substr($query, 0, 500) . (strlen($query) > 500 ? '...' : ''));

			// Check if it's a SELECT query
			if (!preg_match('/^\s*SELECT\s+/i', $query)) {
				Buddy::debugvv("[SUBQUERY]  Not a SELECT query");
				return false;
			}

			// Check for IN clause with subquery pattern: IN (SELECT ...)
			// This matches both IN and NOT IN
			$hasInSubquery = preg_match('/\b(?:NOT\s+)?IN\s*\(\s*SELECT\s+/i', $query) > 0;

			// Check for comparison operator subqueries: =, !=, <>, <, >, <=, >= (SELECT ...)
			$hasComparisonSubquery = preg_match('/(?:=|!=|<>|<=|>=|<|>)\s*\(\s*SELECT\s+/i', $query) > 0;

			$hasSubquery = $hasInSubquery || $hasComparisonSubquery;

			Buddy::debugvv("[SUBQUERY]  Has IN subquery: " . ($hasInSubquery ? 'YES' : 'NO'));
			Buddy::debugvv("[SUBQUERY]  Has comparison subquery: " . ($hasComparisonSubquery ? 'YES' : 'NO'));
			Buddy::debugvv("[SUBQUERY]  Has any subquery: " . ($hasSubquery ? 'YES' : 'NO') . "");

			return $hasSubquery;
		} catch (\Throwable $e) {
			Buddy::debugvv("[SUBQUERY]  ERROR in hasMatch: " . $e->getMessage() . "");
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
