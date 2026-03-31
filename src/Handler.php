<?php declare(strict_types = 1);

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
use Manticoresearch\Buddy\Core\Tool\Buddy;
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
        Buddy::debugvv("[SUBQUERY] [" . date('Y-m-d H:i:s') . "] Handler started");

        $query = $payload->query;
        // Strip any trailing ;SHOW META that Manticore appends to queries
        $query = preg_replace('/\s*;\s*SHOW\s+META\s*$/is', '', $query);
        Buddy::debugvv("[SUBQUERY]   Original query: " . substr($query, 0, 500) . (strlen($query) > 500 ? '...' : ''));

        // Two patterns to handle:
        // 1. IN/NOT IN clause subqueries: IN (SELECT ...)
        // (?!\bSELECT\b) inside the inner paren group ensures we only match leaf subqueries
        // (those without a nested SELECT), so we always process innermost subqueries first.
        $inPattern = '/\b(?:NOT\s+)?IN\s*(\(\s*SELECT\s+[^()]+(?:\((?!SELECT\b)[^()]*\)[^()]*)*\))/is';

        // 2. Comparison operator subqueries: =, !=, <>, <, >, <=, >= (SELECT ...)
        // These return scalar values, not lists
        $comparisonPattern = '/((?:=|!=|<>|<=|>=|<|>)\s*)(\(\s*SELECT\s+[^()]+(?:\((?!SELECT\b)[^()]*\)[^()]*)*\))/is';

        $finalQuery = $query;
        $iteration = 0;
        $maxIterations = 10; // Safety limit to prevent infinite loops

        // Iteratively resolve subqueries from innermost to outermost
        // This loop handles nested subqueries by processing them layer by layer
        while ((preg_match($inPattern, $finalQuery) || preg_match($comparisonPattern, $finalQuery)) && $iteration < $maxIterations) {
            $iteration++;
            Buddy::debugvv("");
            Buddy::debugvv("[SUBQUERY] === Iteration $iteration ===");
            Buddy::debugvv("[SUBQUERY]  Current query: " . substr($finalQuery, 0, 500) . (strlen($finalQuery) > 500 ? '...' : ''));

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
            Buddy::debugvv("[SUBQUERY]  Found $subqueryCount subquery(ies) in this iteration");

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
                    $subquery = trim(substr($matchInfo['fullMatch'], 1, -1)); // (SELECT ...) -> SELECT ...
                } else {
                    // COMPARISON type
                    $subquery = trim(substr($matchInfo['subqueryPart'], 1, -1)); // (SELECT ...) -> SELECT ...
                }

                Buddy::debugvv("");
                Buddy::debugvv("[SUBQUERY]   Processing subquery #" . ($index + 1) . " (type: $type) at offset $offset:");
                Buddy::debugvv("[SUBQUERY]    Subquery: " . substr($subquery, 0, 500) . (strlen($subquery) > 500 ? '...' : ''));

                // Strip any trailing ;SHOW META before executing (client may also append it)
                $subquery = preg_replace('/\s*;\s*SHOW\s+META\s*$/is', '', $subquery);

                // Add LIMIT + max_matches if subquery has none - Manticore defaults to LIMIT 20.
                // If an explicit LIMIT N is present, honour it and set max_matches=N as well.
                $defaultLimit = 20000;
                if (preg_match('/\bLIMIT\s+(\d+)/i', $subquery, $limitMatch)) {
                    $effectiveLimit = (int)$limitMatch[1];
                    // Ensure OPTION max_matches matches the explicit limit (add if absent)
                    if (!preg_match('/\bOPTION\b.*\bmax_matches\s*=/i', $subquery)) {
                        $subquery .= " OPTION max_matches=$effectiveLimit";
                    }
                } else {
                    $effectiveLimit = $defaultLimit;
                    $subquery .= " LIMIT $defaultLimit OPTION max_matches=$defaultLimit";
                }

                // Execute subquery
                Buddy::debugvv("[SUBQUERY]    Executing subquery...");
                try {
                    $response = $manticoreClient->sendRequest($subquery);
                    if ($response->hasError()) {
                        throw new RuntimeException('Subquery #' . ($index + 1) . ' (iteration ' . $iteration . ') failed: ' . $response->getError());
                    }
                    $resultData = $response->getData();
                    $rowCount = count($resultData);
                    Buddy::debugvv("[SUBQUERY]    Result count: $rowCount rows");
                    if ($rowCount >= $effectiveLimit) {
                        throw new RuntimeException(
                            'Subquery #' . ($index + 1) . ' (iteration ' . $iteration . ') returned ' . $rowCount
                            . ' rows, which equals the limit (' . $effectiveLimit . '). Results may be truncated. '
                            . 'Add LIMIT <n> inside the subquery to raise it, e.g.: '
                            . 'IN (SELECT ... LIMIT ' . ($effectiveLimit * 5) . ')'
                        );
                    }
                } catch (\Throwable $e) {
                    Buddy::debugvv("[SUBQUERY]    ERROR executing subquery: " . $e->getMessage() . "");
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
                Buddy::debugvv("[SUBQUERY]    Extracted " . count($values) . " value(s)");

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
                            Buddy::debugvv("[SUBQUERY]    WARNING: Comparison subquery returned " . count($values) . " values, using first one only");
                        }
                        $replacement = $matchInfo['operator'] . ' ' . $values[0];
                    }
                }
                Buddy::debugvv("[SUBQUERY]    Replacement: " . substr($replacement, 0, 500) . (strlen($replacement) > 500 ? '...' : ''));

                // Replace this subquery with values using substr_replace for position-based replacement
                $finalQuery = substr_replace(
                    $finalQuery,
                    $replacement,
                    $offset,
                    strlen($fullMatch)
                );
            }

            Buddy::debugvv("[SUBQUERY]  Query after iteration $iteration: " . substr($finalQuery, 0, 500) . (strlen($finalQuery) > 500 ? '...' : ''));
        }

        if ($iteration >= $maxIterations) {
            Buddy::debugvv("[SUBQUERY]  WARNING: Max iterations ($maxIterations) reached. Possible infinite loop or extremely deep nesting.");
        }

        Buddy::debugvv("");
        Buddy::debugvv("[SUBQUERY]   Final resolved query (after $iteration iteration(s)): " . substr($finalQuery, 0, 500) . (strlen($finalQuery) > 500 ? '...' : ''));

        // Execute final query
        Buddy::debugvv("[SUBQUERY]  Executing final query...");
        try {
            $finalResponse = $manticoreClient->sendRequest($finalQuery);
            if ($finalResponse->hasError()) {
                throw new RuntimeException('Final query failed: ' . $finalResponse->getError());
            }
            Buddy::debugvv("[SUBQUERY]  SUCCESS! Returning result");
        } catch (\Throwable $e) {
            Buddy::debugvv("[SUBQUERY]  ERROR executing final query: " . $e->getMessage() . "");
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
