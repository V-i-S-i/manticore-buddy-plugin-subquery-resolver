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
			file_put_contents($logFile, "  Query: " . substr($query, 0, 150) . "\n", FILE_APPEND);

			// Extract IN clause subquery using regex
			// Pattern matches: IN (SELECT ... FROM ...)
			// Note: We only handle IN/NOT IN subqueries, not FROM clause subqueries (Manticore supports those)
			$pattern = '/\b(?:NOT\s+)?IN\s*(\(\s*SELECT\s+[^()]+(?:\([^()]*\)[^()]*)*\))/is';

			if (!preg_match($pattern, $query, $matches)) {
				file_put_contents($logFile, "  ERROR: No subquery pattern matched\n\n", FILE_APPEND);
				throw new RuntimeException('No valid IN clause subquery found in query');
			}

			$fullSubqueryWithParens = $matches[1]; // (SELECT ...)
			$subquery = trim($matches[1], '() '); // SELECT ... (without outer parentheses)
			file_put_contents($logFile, "  Extracted subquery: $subquery\n", FILE_APPEND);

			// Execute subquery
			file_put_contents($logFile, "  Executing subquery...\n", FILE_APPEND);
			try {
				$response = $manticoreClient->sendRequest($subquery);
				if ($response->hasError()) {
					throw new RuntimeException('Subquery failed: ' . $response->getError());
				}
				$resultData = $response->getData();
				file_put_contents($logFile, "  Subquery result data: " . json_encode($resultData) . "\n", FILE_APPEND);
			} catch (\Throwable $e) {
				file_put_contents($logFile, "  ERROR executing subquery: " . $e->getMessage() . "\n\n", FILE_APPEND);
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
			file_put_contents($logFile, "  Extracted values: " . json_encode($values) . "\n", FILE_APPEND);

			// Create replacement string
			if (empty($values)) {
				$replacement = '(NULL)';
			} else {
				$replacement = '(' . implode(', ', $values) . ')';
			}
			file_put_contents($logFile, "  Replacement: $replacement\n", FILE_APPEND);

			// Replace subquery with values
			$finalQuery = str_replace($fullSubqueryWithParens, $replacement, $query);
			file_put_contents($logFile, "  Final query: $finalQuery\n", FILE_APPEND);

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
