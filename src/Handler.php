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

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * Handler class for resolving subqueries
 * Executes subqueries separately and injects results into main query
 */
final class Handler extends BaseHandlerWithClient
{
	/**
	 * Initialize the handler
	 * @param Payload $payload
	 */
	public function __construct(public Payload $payload)
	{
	}

	/**
	 * Process the request and return response
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task
	{
		$taskFn = static function (
			Payload $payload,
			HTTPClient $manticoreClient
		): TaskResult {
			$logFile = '/tmp/subquery-handler-debug.log';
			file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Handler started\n", FILE_APPEND);

			$query = $payload->query;
			file_put_contents($logFile, "  Original query: " . substr($query, 0, 150) . "\n", FILE_APPEND);

			// Two patterns to handle:
			// 1. IN/NOT IN clause subqueries: IN (SELECT ...)
			$inPattern = '/\b(?:NOT\s+)?IN\s*(\(\s*SELECT\s+[^()]+(?:\([^()]*\)[^()]*)*\))/is';

			// 2. Comparison operator subqueries: =, !=, <>, <, >, <=, >= (SELECT ...)
			// These return scalar values, not lists
			$comparisonPattern = '/((?:=|!=|<>|<=|>=|<|>)\s*)(\(\s*SELECT\s+[^()]+(?:\([^()]*\)[^()]*)*\))/is';

			$finalQuery = $query;
			$iteration = 0;
			$maxIterations = 10; // Safety limit to prevent infinite loops

			// Iteratively resolve subqueries from innermost to outermost
			// This loop handles nested subqueries by processing them layer by layer
			while ((preg_match($inPattern, $finalQuery) || preg_match($comparisonPattern, $finalQuery)) && $iteration < $maxIterations) {
				$iteration++;
				file_put_contents($logFile, "\n=== Iteration $iteration ===\n", FILE_APPEND);
				file_put_contents($logFile, "  Current query: " . substr($finalQuery, 0, 200) . "\n", FILE_APPEND);

				// Collect all subquery matches from both patterns
				$allMatches = [];

				// Find IN clause subqueries
				if (preg_match_all($inPattern, $finalQuery, $inMatches, PREG_OFFSET_CAPTURE)) {
					foreach ($inMatches[1] as $match) {
						$allMatches[] = [
							'type' => 'IN',
							'fullMatch' => $match[0], // (SELECT ...)
							'offset' => $match[1],
							'operator' => '', // No operator needed for IN
						];
					}
				}

				// Find comparison operator subqueries
				if (preg_match_all($comparisonPattern, $finalQuery, $compMatches, PREG_OFFSET_CAPTURE)) {
					foreach ($compMatches[0] as $idx => $match) {
						$allMatches[] = [
							'type' => 'COMPARISON',
							'fullMatch' => $match[0], // = (SELECT ...) including the operator
							'offset' => $match[1],
							'operator' => trim($compMatches[1][$idx][0]), // The operator part
							'subqueryPart' => $compMatches[2][$idx][0], // Just the (SELECT ...) part
						];
					}
				}

				if (empty($allMatches)) {
					break; // No more subqueries found
				}

				$subqueryCount = count($allMatches);
				file_put_contents($logFile, "  Found $subqueryCount subquery(ies) in this iteration\n", FILE_APPEND);

				// Process subqueries from right to left (reverse order by offset)
				// This ensures that replacing later subqueries doesn't affect the positions of earlier ones
				usort($allMatches, function ($a, $b) {
					return $b['offset'] - $a['offset']; // Sort by offset descending
				});

				foreach ($allMatches as $index => $matchInfo) {
					$type = $matchInfo['type'];
					$fullMatch = $matchInfo['fullMatch'];
					$offset = $matchInfo['offset'];

					// Extract the subquery SQL (without outer parentheses)
					if ($type === 'IN') {
						$subquery = trim($matchInfo['fullMatch'], '() '); // (SELECT ...) -> SELECT ...
					} else {
						// COMPARISON type
						$subquery = trim($matchInfo['subqueryPart'], '() '); // (SELECT ...) -> SELECT ...
					}

					file_put_contents($logFile, "  \n  Processing subquery #" . ($index + 1) . " (type: $type) at offset $offset:\n", FILE_APPEND);
					file_put_contents($logFile, "    Subquery: " . substr($subquery, 0, 100) . (strlen($subquery) > 100 ? '...' : '') . "\n", FILE_APPEND);

					// Execute subquery
					file_put_contents($logFile, "    Executing subquery...\n", FILE_APPEND);
					try {
						$response = $manticoreClient->sendRequest($subquery);
						if ($response->hasError()) {
							throw new RuntimeException('Subquery #' . ($index + 1) . ' (iteration ' . $iteration . ') failed: ' . $response->getError());
						}
						$resultData = $response->getData();
						file_put_contents($logFile, "    Result count: " . count($resultData) . " rows\n", FILE_APPEND);
					} catch (\Throwable $e) {
						file_put_contents($logFile, "    ERROR executing subquery: " . $e->getMessage() . "\n\n", FILE_APPEND);
						throw $e;
					}

					// Extract values from result (first column only)
					$values = [];
					if (is_array($resultData) && !empty($resultData)) {
						foreach ($resultData as $row) {
							if (is_array($row) && !empty($row)) {
								$firstValue = reset($row);

								// Handle comma-separated MVA (multi-value attribute) strings from Manticore
								if (is_string($firstValue) && str_contains($firstValue, ',')) {
									// Split comma-separated values
									$mvaValues = explode(',', $firstValue);
									foreach ($mvaValues as $val) {
										$val = trim($val);
										if ($val !== '') {
											$values[] = is_numeric($val) ? $val : "'" . addslashes($val) . "'";
										}
									}
								} elseif (is_numeric($firstValue) || is_string($firstValue)) {
									// Single value
									$values[] = is_numeric($firstValue) ? $firstValue : "'" . addslashes((string)$firstValue) . "'";
								}
							}
						}
					}
					file_put_contents($logFile, "    Extracted " . count($values) . " value(s)\n", FILE_APPEND);

					// Create replacement string based on subquery type
					if ($type === 'IN') {
						// IN clause: wrap values in parentheses
						if (empty($values)) {
							$replacement = '(NULL)';
						} else {
							$replacement = '(' . implode(', ', $values) . ')';
						}
					} else {
						// COMPARISON operator: return scalar value (first value only)
						if (empty($values)) {
							$replacement = $matchInfo['operator'] . ' NULL';
						} else {
							// Take first value only for scalar comparison
							if (count($values) > 1) {
								file_put_contents($logFile, "    WARNING: Comparison subquery returned " . count($values) . " values, using first one only\n", FILE_APPEND);
							}
							$replacement = $matchInfo['operator'] . ' ' . $values[0];
						}
					}
					file_put_contents($logFile, "    Replacement: " . substr($replacement, 0, 100) . (strlen($replacement) > 100 ? '...' : '') . "\n", FILE_APPEND);

					// Replace this subquery with values using substr_replace for position-based replacement
					$finalQuery = substr_replace(
						$finalQuery,
						$replacement,
						$offset,
						strlen($fullMatch)
					);
				}

				file_put_contents($logFile, "  Query after iteration $iteration: " . substr($finalQuery, 0, 200) . (strlen($finalQuery) > 200 ? '...' : '') . "\n", FILE_APPEND);
			}

			if ($iteration >= $maxIterations) {
				file_put_contents($logFile, "  WARNING: Max iterations ($maxIterations) reached. Possible infinite loop or extremely deep nesting.\n", FILE_APPEND);
			}

			file_put_contents($logFile, "\n  Final resolved query (after $iteration iteration(s)): " . substr($finalQuery, 0, 250) . (strlen($finalQuery) > 250 ? '...' : '') . "\n", FILE_APPEND);

			// Execute final query
			file_put_contents($logFile, "  Executing final query...\n", FILE_APPEND);
			try {
				$finalResponse = $manticoreClient->sendRequest($finalQuery);
				if ($finalResponse->hasError()) {
					throw new RuntimeException('Final query failed: ' . $finalResponse->getError());
				}
				file_put_contents($logFile, "  SUCCESS! Returning result\n\n", FILE_APPEND);
			} catch (\Throwable $e) {
				file_put_contents($logFile, "  ERROR executing final query: " . $e->getMessage() . "\n\n", FILE_APPEND);
				throw $e;
			}

			return TaskResult::fromResponse($finalResponse);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

}
