# Manticore Buddy Subquery Resolver Plugin

A Manticore Buddy plugin that enables SQL subquery support for IN/NOT IN clauses in Manticore Search.

## Overview

Manticore Search does not natively support subqueries in WHERE clauses. This plugin intercepts such queries, executes the subqueries separately, and injects the results into the main query - completely transparently to the client.

**Supported subquery types:**
- **IN/NOT IN clauses**: `WHERE id IN (SELECT ...)`
- **Comparison operators**: `WHERE date > (SELECT ...)` using =, !=, <>, <, >, <=, >=

**Note:** Manticore already supports subqueries in FROM clauses (derived tables). This plugin only handles WHERE clause subqueries.

## Features

- Automatic detection and handling of IN/NOT IN clause subqueries
- Comparison operator subqueries (=, !=, <>, <, >, <=, >=)
- Multiple subqueries in a single query
- Nested subqueries (subqueries within subqueries, up to 10 levels)
- Transparent subquery execution and result injection
- Handles empty result sets gracefully (replaces with NULL)
- Supports Manticore MVA (multi-value attributes)
- No client-side changes required
- Default result limit of 20,000 rows per subquery with error if limit is reached
- Override limit per subquery with an explicit LIMIT clause

## Quick Example

**Before (fails in Manticore):**
```sql
-- IN clause subquery
SELECT id FROM rt_today_lt
WHERE ANY(keyword_id) IN (
  SELECT id FROM rt_keywords_customers WHERE customers = 3408
);
-- ERROR: P01: syntax error, unexpected SELECT...

-- Comparison operator subquery
SELECT * FROM rt_today_lt
WHERE date_added > (SELECT archive_start FROM customers WHERE id = 3408);
-- ERROR: P01: syntax error, unexpected SELECT...
```

**After (works with plugin):**
```sql
-- Both queries now work perfectly!
SELECT id FROM rt_today_lt
WHERE ANY(keyword_id) IN (
  SELECT id FROM rt_keywords_customers WHERE customers = 3408
);
-- ✅ Returns results successfully

SELECT * FROM rt_today_lt
WHERE date_added > (SELECT archive_start FROM customers WHERE id = 3408);
-- ✅ Returns results successfully
```

## How It Works

### Basic IN Clause Subquery Resolution

1. Plugin detects the subquery pattern: `IN (SELECT ...)`
2. Extracts and executes: `SELECT id FROM rt_keywords_customers WHERE customers = 3408`
3. Gets results, e.g., `[1, 5, 9, 12, ...]`
4. Rewrites query: `SELECT id FROM rt_today_lt WHERE ANY(keyword_id) IN (1, 5, 9, 12, ...)`
5. Executes final query and returns results

### Comparison Operator Subquery Resolution

1. Plugin detects comparison pattern: `> (SELECT ...)`, `= (SELECT ...)`, etc.
2. Extracts and executes: `SELECT archive_start FROM customers WHERE id = 3408`
3. Gets scalar result, e.g., `2024-01-15`
4. Rewrites query: `SELECT * FROM rt_today_lt WHERE date_added > '2024-01-15'`
5. Executes final query and returns results

**Note:** For comparison operators, only the **first value** is used if multiple rows are returned.

### Nested Subquery Resolution

For nested subqueries, the plugin uses **iterative layer-by-layer resolution**:

1. **Iteration 1**: Finds and executes all innermost subqueries (those without further nesting)
2. Replaces them with their results
3. **Iteration 2**: The previously nested subqueries are now exposed and get resolved
4. **Repeat** until no more subqueries remain (up to 10 levels deep)
5. Execute the fully resolved final query

**Example:**
```sql
-- Original query with 3 levels of nesting:
WHERE product_id IN (SELECT id FROM products WHERE category_id IN (SELECT id FROM categories WHERE group_id IN (SELECT id FROM groups WHERE name = 'Tech')))

-- After iteration 1 (innermost resolved):
WHERE product_id IN (SELECT id FROM products WHERE category_id IN (SELECT id FROM categories WHERE group_id IN (5, 12, 18)))

-- After iteration 2 (middle level resolved):
WHERE product_id IN (SELECT id FROM products WHERE category_id IN (101, 102, 103, 104))

-- After iteration 3 (outermost resolved):
WHERE product_id IN (1001, 1002, 1003, 1004, 1005)

-- Final query executed
```

### Subquery Result Limits

Manticore Search defaults to returning 20 rows per query. The plugin automatically raises this to **20,000 rows** per subquery and sets `OPTION max_matches` accordingly.

If a subquery returns exactly 20,000 rows (i.e. the limit is hit), the plugin throws an error to prevent silently truncated results:

```
Subquery #1 (iteration 2) returned 20000 rows, which equals the limit (20000).
Results may be truncated. Add LIMIT <n> inside the subquery to raise it,
e.g.: IN (SELECT ... LIMIT 100000)
```

**Override the limit** by adding an explicit `LIMIT` inside the subquery:

```sql
-- Raise the limit to 100,000 for this subquery
SELECT aid, COUNT(*) AS cnt
FROM article_relations
WHERE aid IN (
  SELECT id FROM mediamonitoring_all
  WHERE keyword_id IN (
    SELECT keyword_id FROM customers WHERE id = 5180
  ) LIMIT 100000
)
GROUP BY aid ORDER BY cnt DESC LIMIT 500;
```

The `OPTION max_matches` is set automatically to match the explicit `LIMIT`, so no extra syntax is needed.

### Automatic ORDER BY for Proper Limiting

**Important:** Due to a Manticore Search behavior, `LIMIT` clauses may not work correctly without an `ORDER BY` clause. The plugin automatically adds `ORDER BY id` to subqueries that have a `LIMIT` but no ordering specified.

**When ORDER BY id is added automatically:**
- Subquery has `LIMIT` (explicit or default 20,000)
- No existing `ORDER BY` clause
- No `GROUP BY` clause
- No `MATCH()` clause (which orders by relevance weight)

**Example transformations:**
```sql
-- Original subquery (auto-transformed):
SELECT id FROM articles WHERE status = 'active'
-- Becomes:
SELECT id FROM articles WHERE status = 'active' ORDER BY id LIMIT 20000

-- User specified ORDER BY (respected):
SELECT id FROM articles WHERE status = 'active' ORDER BY created_at DESC LIMIT 1000
-- No changes - user's ORDER BY is preserved

-- GROUP BY present (no ORDER BY added):
SELECT category_id FROM articles WHERE status = 'active' GROUP BY category_id
-- No ORDER BY added - would interfere with grouping

-- MATCH() present (no ORDER BY added):
SELECT id FROM articles WHERE MATCH('keyword') LIMIT 5000
-- No ORDER BY added - MATCH() orders by weight/relevance
```

This automatic ordering ensures that LIMIT clauses work correctly in Manticore Search without requiring manual ORDER BY clauses in every subquery.

## Installation

**For Users (Installing from GitHub):**
- See **[INSTALLATION.md](INSTALLATION.md)** for complete installation instructions
- Supports Docker, docker-compose, and manual installations
- Includes troubleshooting guide

**For Developers (Building Your Own Plugin):**
- See **[DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md)** for plugin development tutorial
- Learn Buddy plugin architecture and best practices
- Includes common pitfalls and debugging techniques

## Quick Install

**1. Configure persistent plugin directory** (add to `/etc/manticoresearch/manticore.conf`):

```ini
common {
    plugin_dir = /var/lib/manticore
}
```

**2. Install the plugin:**

```sql
CREATE PLUGIN visi/buddy-plugin-subquery-resolver TYPE 'buddy' VERSION 'dev-main';
```

**3. Verify installation:**

```sql
SHOW Buddy PLUGINS;
```

OR 

```bash
docker logs YOUR_CONTAINER 2>&1 | grep "extra: subquery-resolver"
# Expected: [BUDDY]   extra: subquery-resolver
```

**Note:** The `plugin_dir` setting ensures plugins persist across container restarts. Without it, plugins would be lost on restart.

**See [INSTALLATION.md](INSTALLATION.md) for detailed installation instructions and troubleshooting.**

## Usage Examples

### Basic IN Clause Subquery
```sql
SELECT * FROM products
WHERE id IN (SELECT product_id FROM orders WHERE customer_id = 123);
```

### NOT IN Subquery
```sql
SELECT * FROM users
WHERE id NOT IN (SELECT user_id FROM banned_users);
```

### Comparison Operator Subqueries
```sql
-- Greater than
SELECT * FROM rt_today_lt
WHERE date_added > (SELECT archive_start FROM customers WHERE id = 3408);

-- Equals
SELECT * FROM products
WHERE category_id = (SELECT id FROM categories WHERE name = 'Electronics');

-- Less than or equal
SELECT * FROM orders
WHERE total <= (SELECT credit_limit FROM customers WHERE id = 123);
```

### Multi-Value Attribute (MVA) Support
```sql
-- Works with MVA fields that return comma-separated values
SELECT id FROM articles
WHERE ANY(tag_id) IN (SELECT tag_id FROM popular_tags WHERE views > 1000);
```

### Empty Result Handling
```sql
-- If subquery returns nothing, query returns empty set
SELECT * FROM orders
WHERE product_id IN (SELECT id FROM products WHERE price < 0);
-- Returns: Empty set (0.00 sec)
```

### Multiple Subqueries in One Query
```sql
-- Multiple IN clause subqueries in the same query
SELECT * FROM orders
WHERE product_id IN (SELECT id FROM products WHERE category = 'electronics')
  AND customer_id IN (SELECT id FROM customers WHERE country = 'USA')
  AND status NOT IN (SELECT status_code FROM invalid_statuses);
-- All three subqueries are resolved automatically
```

### Nested Subqueries
```sql
-- Subqueries within subqueries (nesting)
SELECT * FROM orders
WHERE product_id IN (
  SELECT id FROM products
  WHERE category_id IN (
    SELECT id FROM categories
    WHERE parent_id IN (
      SELECT id FROM category_groups WHERE name = 'Electronics'
    )
  )
);
-- Resolves from innermost to outermost automatically
```

### Complex Combinations
```sql
-- Multiple nested subqueries in one query
SELECT * FROM articles
WHERE author_id IN (
  SELECT user_id FROM users
  WHERE department_id IN (SELECT id FROM departments WHERE location = 'NY')
)
AND tag_id NOT IN (
  SELECT tag_id FROM banned_tags
  WHERE category_id IN (SELECT id FROM tag_categories WHERE restricted = 1)
);
-- Handles multiple nesting paths independently
```

### Comparison Operator Subqueries
```sql
-- Scalar subqueries with comparison operators
SELECT * FROM rt_today_lt
WHERE date_added > (SELECT archive_start FROM customers WHERE id = 3408);

-- Works with all comparison operators
SELECT * FROM products
WHERE price <= (SELECT AVG(price) FROM products WHERE category = 'electronics')
  AND stock >= (SELECT MIN(threshold) FROM inventory_settings)
  AND created_at = (SELECT MAX(updated_at) FROM product_updates WHERE status = 'approved');

-- Can be nested too
SELECT * FROM orders
WHERE total > (
  SELECT AVG(total) FROM orders
  WHERE customer_id IN (SELECT id FROM customers WHERE tier = 'premium')
);
```

## Supported Features

✅ **Supported:**
- **IN/NOT IN clause subqueries** in WHERE clause
- **Comparison operator subqueries** (=, !=, <>, <, >, <=, >=) returning scalar values
- **Multiple subqueries in one query**
- **Nested subqueries** (subqueries within subqueries, up to 10 levels deep)
- **Mixed subquery types** (IN and comparison operators in same query)
- Simple SELECT subqueries returning a single column
- MVA (multi-value attribute) fields (for IN clauses)
- Empty result sets (replaced with NULL)
- Numeric and string values

❌ **Not Supported (yet):**
- Subqueries in HAVING, ORDER BY, SELECT columns, etc.
- Correlated subqueries (subqueries that reference outer query columns)
- Multi-column subqueries

⚠️ **Not Needed (Manticore already supports):**
- FROM clause subqueries (derived tables): `SELECT * FROM (SELECT ...) AS t`

## Real-World Example

Here's a comprehensive example demonstrating all plugin features working together:

```sql
-- Complex query with multiple subquery types and nesting
SELECT
  o.id,
  o.customer_id,
  o.total,
  o.created_at
FROM orders o
WHERE
  -- IN clause with nested subquery
  o.customer_id IN (
    SELECT c.id FROM customers c
    WHERE c.tier = 'premium'
      AND c.country_id IN (SELECT id FROM countries WHERE region = 'EU')
  )
  -- Comparison operator subquery
  AND o.created_at > (SELECT last_promotion_date FROM settings WHERE key = 'promo_2024')
  -- NOT IN clause subquery
  AND o.status NOT IN (SELECT code FROM order_statuses WHERE is_cancelled = 1)
  -- Another comparison subquery with nested IN
  AND o.total >= (
    SELECT MIN(min_order_total) FROM tier_settings
    WHERE tier_id IN (SELECT id FROM tiers WHERE name = 'premium')
  );
```

**What happens:**
1. Plugin detects 5 subqueries (2 nested levels)
2. **Iteration 1** resolves innermost subqueries:
   - `SELECT id FROM countries WHERE region = 'EU'` → `(1, 5, 12)`
   - `SELECT id FROM tiers WHERE name = 'premium'` → `(3)`
3. **Iteration 2** resolves mid-level subqueries:
   - `SELECT c.id FROM customers WHERE ... country_id IN (1, 5, 12)` → `(101, 205, 308)`
   - `SELECT MIN(...) FROM tier_settings WHERE tier_id IN (3)` → `500.00`
   - `SELECT last_promotion_date FROM settings ...` → `'2024-01-15'`
   - `SELECT code FROM order_statuses ...` → `('CANC', 'REFUND')`
4. **Final query** executes with all values resolved:
```sql
SELECT o.id, o.customer_id, o.total, o.created_at
FROM orders o
WHERE o.customer_id IN (101, 205, 308)
  AND o.created_at > '2024-01-15'
  AND o.status NOT IN ('CANC', 'REFUND')
  AND o.total >= 500.00;
```

## Testing

### Verify Installation

```sql
-- Connect to Manticore (port 9306)
mysql -h127.0.0.1 -P9306
```

Check plugin is loaded (via Docker logs):
```bash
docker logs YOUR_CONTAINER 2>&1 | grep "local: subquery-resolver"
# Expected: [BUDDY]   local: subquery-resolver
```

### Run Test Queries

```sql
-- Create test tables
CREATE TABLE test_main (id bigint, value text, created_at timestamp);
CREATE TABLE test_sub (ref_id bigint);
CREATE TABLE test_config (max_date timestamp);

-- Insert data
INSERT INTO test_main VALUES (1, 'a', 1640000000), (2, 'b', 1650000000), (3, 'c', 1660000000);
INSERT INTO test_sub VALUES (1), (3);
INSERT INTO test_config VALUES (1655000000);

-- Test IN clause subquery
SELECT * FROM test_main WHERE id IN (SELECT ref_id FROM test_sub);
-- Expected: Returns rows with id=1 and id=3

-- Test comparison operator subquery
SELECT * FROM test_main WHERE created_at > (SELECT max_date FROM test_config);
-- Expected: Returns row with id=3

-- Test mixed subqueries
SELECT * FROM test_main
WHERE id IN (SELECT ref_id FROM test_sub)
  AND created_at < (SELECT max_date FROM test_config);
-- Expected: Returns row with id=1
```

## Documentation

- **[INSTALLATION.md](INSTALLATION.md)** - Complete installation guide for all platforms
- **[DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md)** - Plugin development tutorial
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Original deployment notes (Windows-specific)

## Troubleshooting

**Plugin not loading?**
- Check plugin directory: `/usr/share/manticore/modules/manticore-buddy/plugins/`
- Verify autoloader is registered in Buddy's vendor/autoload.php
- Check Buddy logs: `docker logs YOUR_CONTAINER | grep BUDDY`

**Queries not being intercepted?**
- Verify plugin shows in logs: `grep "local: subquery-resolver"`
- Check debug logs: `docker exec YOUR_CONTAINER cat /tmp/subquery-plugin-debug.log`
- Ensure query uses IN clause with SELECT subquery

**Queries hanging?**
- Check handler logs: `docker exec YOUR_CONTAINER cat /tmp/subquery-handler-debug.log`
- Review Buddy error logs: `docker logs YOUR_CONTAINER 2>&1 | tail -100`

See [INSTALLATION.md](INSTALLATION.md#troubleshooting) for detailed troubleshooting guide.

## Requirements

- Manticore Search v17.5.0+
- Manticore Buddy v3.0+ (included with Manticore 17.5+)
- PHP 8.1+ (runs inside Buddy)
- Docker (recommended) or manual Manticore installation

## Performance Considerations

- **Simple queries**: Executes subquery first, then main query (2 queries total)
  - Works the same for both IN clauses and comparison operators
- **Multiple subqueries**: Executes each subquery + final query (N+1 queries for N subqueries)
  - Can mix IN and comparison operator subqueries
- **Nested subqueries**: Executes queries layer by layer (sum of all subqueries at each level + final query)
  - 2 levels: ~3-4 queries
  - 3 levels: ~4-6 queries
  - Each nesting level adds one iteration
- **Comparison operators**: Return single scalar value (first row only if multiple returned)
- **Result limits**: Each subquery is capped at 20,000 rows by default. If the limit is hit, an error is raised. Override with an explicit `LIMIT N` inside the subquery (e.g. `IN (SELECT ... LIMIT 100000)`).
- **Large result sets**: For IN clauses with many values, there may be a slight delay due to query string size. Keep subquery result sets reasonable in size.
- **Deep nesting**: Limited to 10 levels to prevent infinite loops
- Consider using JOINs or application-level logic for very large datasets or very deep nesting
- Monitor query performance with `SHOW META`

## Contributing

Contributions welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly with real queries
5. Submit a pull request

## License

GPL-2.0-or-later

Copyright (c) 2026, V-i-S-i

This plugin is designed for Manticore Search and uses the Buddy plugin architecture.
Manticore Search is Copyright (c) Manticore Software LTD (https://manticoresearch.com)

## Support

- **Issues:** [GitHub Issues](https://github.com/yourusername/buddy-plugin-subquery-resolver/issues)
- **Discussions:** [GitHub Discussions](https://github.com/yourusername/buddy-plugin-subquery-resolver/discussions)
- **Manticore Forum:** [https://forum.manticoresearch.com](https://forum.manticoresearch.com)

## Credits

Created for Manticore Search community by [Your Name]

Special thanks to the Manticore Software team for the Buddy plugin architecture.

---

**Ready to get started?** → See [INSTALLATION.md](INSTALLATION.md)

**Want to build your own plugin?** → See [DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md)
