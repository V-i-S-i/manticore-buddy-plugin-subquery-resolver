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

		try {
			$query = self::getQuery($request);
			
			// Fast bail-out: no WHERE with SELECT after it means no subquery
			$wherePos = stripos($query, 'WHERE');
			if ($wherePos === false) return false;
			if (stripos($query, 'SELECT', $wherePos) === false) return false;

			// Check for subquery pattern: IN/NOT IN or comparison operators followed by (SELECT ...)
			$hasSubquery = preg_match('/(?:\b(?:NOT\s+)?IN|=|!=|<>|<=|>=|<|>)\s*\(\s*SELECT\s+/i', $query) > 0;

			Buddy::debugvv("[SUBQUERY]  Extracted query: " . substr($query, 0, 500) . (strlen($query) > 500 ? '...' : ''));

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
