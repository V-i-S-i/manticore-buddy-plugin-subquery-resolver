# Manticore Buddy Subquery Resolver Plugin

A Manticore Buddy plugin that enables SQL subquery support for IN/NOT IN clauses in Manticore Search.

## Overview

Manticore Search does not natively support subqueries in IN clauses. This plugin intercepts such queries, executes the subquery separately, and injects the results into the main query - completely transparently to the client.

**Note:** Manticore already supports subqueries in FROM clauses (derived tables). This plugin only handles IN/NOT IN clause subqueries.

## Features

✅ Automatic detection and handling of IN/NOT IN clause subqueries
✅ Transparent subquery execution and result injection
✅ Handles empty result sets gracefully (replaces with NULL)
✅ Supports Manticore MVA (multi-value attributes)
✅ No client-side changes required
✅ Production-ready with comprehensive error handling

## Quick Example

**Before (fails in Manticore):**
```sql
SELECT id
FROM rt_today_lt
WHERE ANY(keyword_id) IN (
  SELECT id FROM rt_keywords_customers WHERE customers = 3408
);
-- ERROR: P01: syntax error, unexpected SELECT...
```

**After (works with plugin):**
```sql
SELECT id
FROM rt_today_lt
WHERE ANY(keyword_id) IN (
  SELECT id FROM rt_keywords_customers WHERE customers = 3408
);
-- ✅ Returns results successfully
```

## How It Works

1. Plugin detects the subquery pattern
2. Extracts and executes: `SELECT id FROM rt_keywords_customers WHERE customers = 3408`
3. Gets results, e.g., `[1, 5, 9, 12, ...]`
4. Rewrites query: `SELECT id FROM rt_today_lt WHERE ANY(keyword_id) IN (1, 5, 9, 12, ...)`
5. Executes final query and returns results

## Installation

**For Users (Installing from GitHub):**
- See **[INSTALLATION.md](INSTALLATION.md)** for complete installation instructions
- Supports Docker, docker-compose, and manual installations
- Includes troubleshooting guide

**For Developers (Building Your Own Plugin):**
- See **[DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md)** for plugin development tutorial
- Learn Buddy plugin architecture and best practices
- Includes common pitfalls and debugging techniques

## Quick Install (Docker)

```bash
# 1. Clone repository
git clone https://github.com/yourusername/buddy-plugin-subquery-resolver.git

# 2. Copy to container
docker cp buddy-plugin-subquery-resolver YOUR_CONTAINER:/usr/share/manticore/modules/manticore-buddy/plugins/

# 3. Setup autoloader (see INSTALLATION.md for details)
# 4. Restart container
docker restart YOUR_CONTAINER

# 5. Verify
docker logs YOUR_CONTAINER 2>&1 | grep "local: subquery-resolver"
```

**See [INSTALLATION.md](INSTALLATION.md) for complete step-by-step instructions.**

## Usage Examples

### Basic Subquery
```sql
SELECT * FROM products
WHERE id IN (SELECT product_id FROM orders WHERE customer_id = 123);
```

### NOT IN Subquery
```sql
SELECT * FROM users
WHERE id NOT IN (SELECT user_id FROM banned_users);
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

## Supported Features

✅ **Supported:**
- Single-level IN/NOT IN subqueries in WHERE clause
- Simple SELECT subqueries returning a single column
- MVA (multi-value attribute) fields
- Empty result sets
- Numeric and string values

❌ **Not Supported (yet):**
- Nested subqueries (subqueries within subqueries)
- Multiple subqueries in one query
- Subqueries in HAVING, ORDER BY, etc.
- Correlated subqueries

⚠️ **Not Needed (Manticore already supports):**
- FROM clause subqueries (derived tables): `SELECT * FROM (SELECT ...) AS t`

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

### Run Test Query

```sql
-- Create test tables
CREATE TABLE test_main (id bigint, value text);
CREATE TABLE test_sub (ref_id bigint);

-- Insert data
INSERT INTO test_main VALUES (1, 'a'), (2, 'b'), (3, 'c');
INSERT INTO test_sub VALUES (1), (3);

-- Test subquery
SELECT * FROM test_main WHERE id IN (SELECT ref_id FROM test_sub);
-- Expected: Returns rows with id=1 and id=3
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

- Plugin executes subquery first, then main query (2 queries total)
- For large result sets (1000+ values), there may be a slight delay
- Consider using JOINs or application-level logic for very large datasets
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
