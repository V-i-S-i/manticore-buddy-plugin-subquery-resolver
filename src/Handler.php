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

        $warnings = []; // Accumulate warnings to return to client
        $subqueryCache = []; // Cache: normalized subquery text => extracted values array

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

            // Phase 1: Normalize and deduplicate subqueries
            foreach ($allMatches as &$matchInfo) {
                if ($matchInfo['type'] === 'IN') {
                    $rawSubquery = trim(substr($matchInfo['fullMatch'], 1, -1));
                } else {
                    $rawSubquery = trim(substr($matchInfo['subqueryPart'], 1, -1));
                }
                $rawSubquery = preg_replace('/\s*;\s*SHOW\s+META\s*$/is', '', $rawSubquery);
                $normalizedKey = strtolower(preg_replace('/\s+/', ' ', trim($rawSubquery)));
                $matchInfo['_normalizedKey'] = $normalizedKey;
                $matchInfo['_rawSubquery'] = $rawSubquery;
            }
            unset($matchInfo);

            // Collect unique subquery keys (preserving first occurrence order)
            $uniqueKeys = [];
            foreach ($allMatches as $matchInfo) {
                $key = $matchInfo['_normalizedKey'];
                if (!isset($uniqueKeys[$key])) {
                    $uniqueKeys[$key] = $matchInfo['_rawSubquery'];
                }
            }

            $totalCount = count($allMatches);
            $uniqueCount = count($uniqueKeys);
            if ($uniqueCount < $totalCount) {
                Buddy::debugvv("[SUBQUERY]  Deduplicated: $totalCount subqueries -> $uniqueCount unique");
            }

            // Phase 1.5: Merge subqueries from same table with same filters
            // If multiple subqueries differ only in SELECT column, execute one multi-column query
            $simpleSelectPattern = '/^\s*SELECT\s+(`?\w+`?)\s+FROM\s+(.+)$/is';
            $mergeGroups = []; // normalized_rest => [ ['key' => ..., 'column' => ..., 'rawRest' => ...], ... ]

            foreach ($uniqueKeys as $normalizedKey => $rawSubquery) {
                if (isset($subqueryCache[$normalizedKey])) {
                    continue; // Already cached from prior iteration
                }
                if (preg_match($simpleSelectPattern, $rawSubquery, $selectMatch)) {
                    $column = $selectMatch[1];
                    $rest = $selectMatch[2];
                    $normalizedRest = strtolower(preg_replace('/\s+/', ' ', trim($rest)));
                    $mergeGroups[$normalizedRest][] = [
                        'key' => $normalizedKey,
                        'column' => $column,
                        'rawRest' => $rest,
                    ];
                }
            }

            // Execute merged queries for groups with >1 member
            foreach ($mergeGroups as $normalizedRest => $group) {
                if (count($group) <= 1) {
                    continue;
                }

                $columns = array_map(fn($g) => $g['column'], $group);
                $rawRest = $group[0]['rawRest'];
                $mergedSubquery = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $rawRest;

                Buddy::debugvv("");
                Buddy::debugvv("[SUBQUERY]   Merged " . count($group) . " subqueries into one:");
                Buddy::debugvv("[SUBQUERY]    Query: " . substr($mergedSubquery, 0, 500) . (strlen($mergedSubquery) > 500 ? '...' : ''));

                // Add LIMIT/OPTION if missing (same logic as Phase 2)
                $mergedDefaultLimit = 20000;
                $mergedUserSetLimit = false;
                if (preg_match('/\bLIMIT\s+(\d+)/i', $mergedSubquery, $mLimitMatch)) {
                    $mergedUserSetLimit = true;
                    $mergedEffectiveLimit = (int)$mLimitMatch[1];
                    if (!preg_match('/\bOPTION\b/i', $mergedSubquery)) {
                        $mergedSubquery .= " OPTION max_matches=$mergedEffectiveLimit, cutoff=0";
                    } else {
                        if (!preg_match('/\bmax_matches\s*=/i', $mergedSubquery)) {
                            $mergedSubquery = preg_replace('/(\bOPTION\b)/i', '$1 max_matches=' . $mergedEffectiveLimit . ',', $mergedSubquery);
                        }
                        if (!preg_match('/\bcutoff\s*=/i', $mergedSubquery)) {
                            $mergedSubquery = preg_replace('/(\bOPTION\b.*?)(\s*$|;)/i', '$1, cutoff=0$2', $mergedSubquery);
                        }
                    }
                } else {
                    $mergedEffectiveLimit = $mergedDefaultLimit;
                    $mergedSubquery .= " LIMIT $mergedDefaultLimit OPTION max_matches=$mergedDefaultLimit, cutoff=0";
                }

                // Execute merged query
                try {
                    $mergedResponse = $manticoreClient->sendRequest($mergedSubquery);
                    if ($mergedResponse->hasError()) {
                        Buddy::debugvv("[SUBQUERY]    Merged query failed: " . $mergedResponse->getError() . ", falling back to individual execution");
                        continue; // Phase 2 will handle them individually
                    }
                    $mergedResultData = $mergedResponse->getData();
                    $mergedRowCount = count($mergedResultData);
                    Buddy::debugvv("[SUBQUERY]    Merged result: $mergedRowCount rows");

                    if (!$mergedUserSetLimit && $mergedRowCount >= $mergedEffectiveLimit) {
                        $truncatedQuery = substr($mergedSubquery, 0, 100) . (strlen($mergedSubquery) > 100 ? '...' : '');
                        $warningMsg = 'Merged subquery returned ' . $mergedRowCount
                            . ' rows, which equals the limit (' . $mergedEffectiveLimit . '). Results may be truncated. '
                            . 'Query: ' . $truncatedQuery;
                        $warnings[] = $warningMsg;
                        Buddy::warning($warningMsg);
                    }
                } catch (\Throwable $e) {
                    Buddy::debugvv("[SUBQUERY]    ERROR in merged query: " . $e->getMessage() . ", falling back to individual execution");
                    continue; // Phase 2 will handle them individually
                }

                // Distribute results: extract each column's values into individual cache entries
                foreach ($group as $member) {
                    $colName = trim($member['column'], '`');
                    $values = [];
                    foreach ($mergedResultData as $row) {
                        if (!is_array($row) || !isset($row[$colName])) {
                            continue;
                        }
                        $firstValue = $row[$colName];
                        if (is_string($firstValue) && str_contains($firstValue, ',')) {
                            $values[] = $firstValue; // MVA comma-separated
                        } elseif (is_numeric($firstValue) || is_string($firstValue)) {
                            $values[] = is_numeric($firstValue) ? $firstValue : "'" . addslashes((string)$firstValue) . "'";
                        }
                    }
                    $subqueryCache[$member['key']] = $values;
                    Buddy::debugvv("[SUBQUERY]    Column '$colName': " . count($values) . " value(s) cached");
                }
                unset($mergedResultData);
            }

            // Phase 2: Execute only unique subqueries (skip if already cached from prior iteration or merge)
            $subqueryIndex = 0;
            foreach ($uniqueKeys as $normalizedKey => $rawSubquery) {
                $subqueryIndex++;
                if (isset($subqueryCache[$normalizedKey])) {
                    Buddy::debugvv("");
                    Buddy::debugvv("[SUBQUERY]   Subquery #$subqueryIndex: CACHE HIT (" . count($subqueryCache[$normalizedKey]) . " cached values)");
                    Buddy::debugvv("[SUBQUERY]    Subquery: " . substr($rawSubquery, 0, 500) . (strlen($rawSubquery) > 500 ? '...' : ''));
                    continue;
                }

                Buddy::debugvv("");
                Buddy::debugvv("[SUBQUERY]   Executing unique subquery #$subqueryIndex / $uniqueCount:");
                Buddy::debugvv("[SUBQUERY]    Subquery: " . substr($rawSubquery, 0, 500) . (strlen($rawSubquery) > 500 ? '...' : ''));

                $subquery = $rawSubquery;

                // Add LIMIT + max_matches if subquery has none - Manticore defaults to LIMIT 20.
                // If an explicit LIMIT N is present, honour it and set max_matches=N as well.
                // Also add cutoff=0 to ensure LIMIT works correctly without ORDER BY requirement
                $defaultLimit = 20000;

                $userSetLimit = false;
                if (preg_match('/\bLIMIT\s+(\d+)/i', $subquery, $limitMatch)) {
                    $userSetLimit = true;
                    $effectiveLimit = (int)$limitMatch[1];
                    // Ensure OPTION has max_matches and cutoff=0
                    if (!preg_match('/\bOPTION\b/i', $subquery)) {
                        $subquery .= " OPTION max_matches=$effectiveLimit, cutoff=0";
                    } else {
                        if (!preg_match('/\bmax_matches\s*=/i', $subquery)) {
                            $subquery = preg_replace('/(\bOPTION\b)/i', '$1 max_matches=' . $effectiveLimit . ',', $subquery);
                        }
                        if (!preg_match('/\bcutoff\s*=/i', $subquery)) {
                            $subquery = preg_replace('/(\bOPTION\b.*?)(\s*$|;)/i', '$1, cutoff=0$2', $subquery);
                        }
                    }
                } else {
                    $effectiveLimit = $defaultLimit;
                    $subquery .= " LIMIT $defaultLimit OPTION max_matches=$defaultLimit, cutoff=0";
                }

                // Execute subquery
                Buddy::debugvv("[SUBQUERY]    Executing subquery...");
                try {
                    $response = $manticoreClient->sendRequest($subquery);
                    if ($response->hasError()) {
                        throw new RuntimeException('Unique subquery #' . $subqueryIndex . ' (iteration ' . $iteration . ') failed: ' . $response->getError());
                    }
                    $resultData = $response->getData();
                    $rowCount = count($resultData);
                    Buddy::debugvv("[SUBQUERY]    Result count: $rowCount rows");
                    if (!$userSetLimit && $rowCount >= $effectiveLimit) {
                        $truncatedSubquery = substr($subquery, 0, 100) . (strlen($subquery) > 100 ? '...' : '');
                        $warningMsg = 'Subquery #' . $subqueryIndex . ' (iteration ' . $iteration . ') returned ' . $rowCount
                            . ' rows, which equals the limit (' . $effectiveLimit . '). Results may be truncated. '
                            . 'Add LIMIT <n> inside the subquery to raise it, e.g.: '
                            . 'IN (SELECT ... LIMIT ' . ($effectiveLimit * 5) . '). '
                            . 'Subquery: ' . $truncatedSubquery;
                        $warnings[] = $warningMsg;
                        Buddy::warning($warningMsg);
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
                            // MVA values are always integers, so pass the comma-separated string through directly
                            if (is_string($firstValue) && str_contains($firstValue, ',')) {
                                $values[] = $firstValue;
                            } elseif (is_numeric($firstValue) || is_string($firstValue)) {
                                // Single value
                                $values[] = is_numeric($firstValue) ? $firstValue : "'" . addslashes((string)$firstValue) . "'";
                            }
                        }
                    }
                }
                unset($resultData);
                Buddy::debugvv("[SUBQUERY]    Extracted " . count($values) . " value(s)");

                // Cache the extracted values
                $subqueryCache[$normalizedKey] = $values;
            }

            // Phase 3: Inject results into all match positions (right-to-left)
            usort($allMatches, function ($a, $b) {
                return $b['offset'] - $a['offset'];
            });

            foreach ($allMatches as $matchInfo) {
                $type = $matchInfo['type'];
                $fullMatch = $matchInfo['fullMatch'];
                $offset = $matchInfo['offset'];
                $values = $subqueryCache[$matchInfo['_normalizedKey']];

                // Create replacement string based on subquery type
                if ($type === 'IN') {
                    if (empty($values)) {
                        $replacement = '(NULL)';
                    } else {
                        $replacement = '(' . implode(', ', $values) . ')';
                    }
                } else {
                    if (empty($values)) {
                        $replacement = $matchInfo['operator'] . ' NULL';
                    } else {
                        if (count($values) > 1) {
                            Buddy::debugvv("[SUBQUERY]    WARNING: Comparison subquery returned " . count($values) . " values, using first one only");
                        }
                        $replacement = $matchInfo['operator'] . ' ' . $values[0];
                    }
                }
                Buddy::debugvv("[SUBQUERY]    Replacement at offset $offset: " . substr($replacement, 0, 200) . (strlen($replacement) > 200 ? '...' : ''));

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

        $result = TaskResult::fromResponse($finalResponse);

        // Attach accumulated warnings to the result
        if (!empty($warnings)) {
            $result->warning(implode("\n", $warnings));
        }

        return $result;
    };

    return Task::create(
        $taskFn,
        [$this->payload, $this->manticoreClient]
    )->run();
}

}
