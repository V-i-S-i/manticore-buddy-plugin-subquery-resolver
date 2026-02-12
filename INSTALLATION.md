# Installing Subquery Resolver Plugin

This guide explains how to install the Manticore Buddy Subquery Resolver plugin on Manticore Search.

## Prerequisites

- Docker Desktop or Docker Engine running
- Manticore Search v17.5.0+ running in a Docker container
- Manticore Buddy v3.0+ (included with Manticore 17.5+)
- Basic command line knowledge

---

## Installation Methods

### Method 1: From Packagist (Official - Recommended)

**When available:** Once the plugin is published to Packagist

**One-command installation:**

```sql
-- Connect to Manticore
mysql -h127.0.0.1 -P9306

-- Install the plugin
CREATE PLUGIN visi/buddy-plugin-subquery-resolver
TYPE 'buddy'
VERSION 'latest';
```

**Verify installation:**
```bash
docker logs YOUR_CONTAINER 2>&1 | grep "extra:"
# Expected: [BUDDY]   extra: subquery-resolver
```

**That's it!** The plugin is installed and ready to use.

**Benefits:**
- ✅ One SQL command
- ✅ Automatic version management
- ✅ Easy updates
- ✅ Official distribution

**Note:** This method requires the plugin to be published on Packagist. If not yet published, use Method 2 below.

---

### Method 2: Manual Installation from GitHub

**Use this when:** Plugin not yet published, testing unreleased versions, private deployments

## Quick Installation (Linux/Mac)

```bash
# 1. Clone the plugin repository
git clone https://github.com/yourusername/buddy-plugin-subquery-resolver.git
cd buddy-plugin-subquery-resolver

# 2. Copy plugin to container
docker cp . YOUR_CONTAINER_NAME:/usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver

# 3. Setup autoloader in container
docker exec YOUR_CONTAINER_NAME bash -c "cd /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver && mkdir -p vendor && cat > vendor/autoload.php << 'EOF'
<?php
require_once '/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php';
spl_autoload_register(function (\$class) {
    \$prefix = 'Manticoresearch\\\\Buddy\\\\Plugin\\\\SubqueryResolver\\\\';
    \$base_dir = __DIR__ . '/../src/';
    \$len = strlen(\$prefix);
    if (strncmp(\$prefix, \$class, \$len) !== 0) return;
    \$relative_class = substr(\$class, \$len);
    \$file = \$base_dir . str_replace('\\\\\\\\', '/', \$relative_class) . '.php';
    if (file_exists(\$file)) require \$file;
});
EOF
"

# 4. Register plugin in Buddy's autoloader
docker exec YOUR_CONTAINER_NAME bash -c "sed -i '/^return Composer/i\\
\$pluginAutoload = __DIR__ . '\''/../plugins/buddy-plugin-subquery-resolver/vendor/autoload.php'\'';\
if (file_exists(\$pluginAutoload)) {\
    require_once \$pluginAutoload;\
}\
' /usr/share/manticore/modules/manticore-buddy/vendor/autoload.php"

# 5. Restart container
docker restart YOUR_CONTAINER_NAME

# 6. Verify installation
sleep 5
docker logs YOUR_CONTAINER_NAME 2>&1 | grep "local: subquery-resolver"
```

**Expected output:**
```
[BUDDY]   local: subquery-resolver
```

---

## Quick Installation (Windows PowerShell)

```powershell
# 1. Clone the plugin repository
git clone https://github.com/yourusername/buddy-plugin-subquery-resolver.git
cd buddy-plugin-subquery-resolver

# 2. Copy plugin to container
docker cp . YOUR_CONTAINER_NAME:/usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver

# 3. Setup autoloader
$autoloadScript = @'
cd /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver
mkdir -p vendor
cat > vendor/autoload.php << 'AUTOLOAD_EOF'
<?php
require_once '/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php';
spl_autoload_register(function ($class) {
    $prefix = 'Manticoresearch\\Buddy\\Plugin\\SubqueryResolver\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});
AUTOLOAD_EOF
'@

docker exec YOUR_CONTAINER_NAME bash -c $autoloadScript

# 4. Register in Buddy's autoloader (manual step required - see below)

# 5. Restart container
docker restart YOUR_CONTAINER_NAME

# 6. Verify
Start-Sleep -Seconds 5
docker logs YOUR_CONTAINER_NAME 2>&1 | Select-String "local: subquery-resolver"
```

---

## Step-by-Step Installation Guide

### Step 1: Clone the Repository

```bash
git clone https://github.com/yourusername/buddy-plugin-subquery-resolver.git
cd buddy-plugin-subquery-resolver
```

### Step 2: Find Your Container Name

```bash
docker ps | grep manticore
```

Output example:
```
abc123def456   manticoresearch/manticore:latest   "manticore"   2 hours ago   Up 2 hours   manticore_production
```

Container name: `manticore_production`

### Step 3: Copy Plugin to Container

```bash
docker cp . manticore_production:/usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver
```

**Verify files copied:**
```bash
docker exec manticore_production ls -la /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver/
```

Expected output:
```
drwxr-xr-x  src/
-rw-r--r--  composer.json
-rw-r--r--  README.md
...
```

### Step 4: Create Autoloader

The plugin needs its own autoloader to load classes.

**Execute in container:**
```bash
docker exec manticore_production bash -c "cd /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver && mkdir -p vendor && cat > vendor/autoload.php << 'AUTOLOAD_EOF'
<?php
require_once '/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php';

spl_autoload_register(function (\$class) {
    \$prefix = 'Manticoresearch\\\\Buddy\\\\Plugin\\\\SubqueryResolver\\\\';
    \$base_dir = __DIR__ . '/../src/';
    \$len = strlen(\$prefix);
    if (strncmp(\$prefix, \$class, \$len) !== 0) return;
    \$relative_class = substr(\$class, \$len);
    \$file = \$base_dir . str_replace('\\\\\\\\', '/', \$relative_class) . '.php';
    if (file_exists(\$file)) require \$file;
});
AUTOLOAD_EOF
"
```

**Verify autoloader created:**
```bash
docker exec manticore_production cat /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver/vendor/autoload.php
```

### Step 5: Register Plugin in Buddy's Autoloader

**IMPORTANT:** This step tells Buddy to load your plugin.

**Option A: Automatic (Linux/Mac)**
```bash
docker exec manticore_production bash -c "sed -i '/^return Composer/i\\
\$pluginAutoload = __DIR__ . '\''/../plugins/buddy-plugin-subquery-resolver/vendor/autoload.php'\'';\
if (file_exists(\$pluginAutoload)) {\
    require_once \$pluginAutoload;\
}\
' /usr/share/manticore/modules/manticore-buddy/vendor/autoload.php"
```

**Option B: Manual**

1. Get current autoload.php content:
```bash
docker exec manticore_production cat /usr/share/manticore/modules/manticore-buddy/vendor/autoload.php > autoload_backup.php
```

2. Edit the file locally to add **before** the `return` statement:
```php
// Load subquery-resolver plugin autoloader
$pluginAutoload = __DIR__ . '/../plugins/buddy-plugin-subquery-resolver/vendor/autoload.php';
if (file_exists($pluginAutoload)) {
    require_once $pluginAutoload;
}

return ComposerAutoloaderInitf8b8f7446a01b57af60c5fcd61adb8c7::getLoader();
```

3. Copy modified file back:
```bash
docker cp autoload_backup.php manticore_production:/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php
```

### Step 6: Restart Container

```bash
docker restart manticore_production
```

Wait for container to be ready:
```bash
# Linux/Mac
timeout 30 bash -c 'until docker exec manticore_production bash -c "exit" 2>/dev/null; do sleep 1; done'

# Windows PowerShell
Start-Sleep -Seconds 10
```

### Step 7: Verify Installation

**Check Buddy logs:**
```bash
docker logs manticore_production 2>&1 | grep "Loaded plugins"
```

Expected output:
```
[BUDDY] Loaded plugins:
[BUDDY]   core: empty-string, backup, emulate-elastic, ...
[BUDDY]   local: subquery-resolver
[BUDDY]   extra:
```

**Test with a query:**

Connect to Manticore:
```bash
# Linux/Mac
mysql -h127.0.0.1 -P9306

# Or use docker exec
docker exec -it manticore_production mysql
```

Run test query:
```sql
-- Create test tables
CREATE TABLE test_main (id bigint, value bigint);
CREATE TABLE test_sub (ref_id bigint);

INSERT INTO test_main (id, value) VALUES (1, 100), (2, 200), (3, 300);
INSERT INTO test_sub (ref_id) VALUES (1), (3);

-- Test subquery (this should work with the plugin)
SELECT id, value FROM test_main WHERE id IN (SELECT ref_id FROM test_sub);
```

Expected result:
```
+----+-------+
| id | value |
+----+-------+
|  1 |   100 |
|  3 |   300 |
+----+-------+
2 rows in set (0.00 sec)
```

**Check plugin execution logs:**
```bash
docker exec manticore_production cat /tmp/subquery-handler-debug.log
```

---

## Troubleshooting

### Plugin Not Showing in Logs

**Problem:** `grep "local:"` returns empty

**Solution:**
1. Verify plugin directory exists:
```bash
docker exec manticore_production ls /usr/share/manticore/modules/manticore-buddy/plugins/
```

2. Check autoloader exists:
```bash
docker exec manticore_production ls /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver/vendor/autoload.php
```

3. Verify registration in main autoloader:
```bash
docker exec manticore_production grep "subquery-resolver" /usr/share/manticore/modules/manticore-buddy/vendor/autoload.php
```

### Plugin Shows but Doesn't Execute

**Problem:** Plugin listed in logs but queries aren't being intercepted

**Check if hasMatch() is being called:**
```bash
# Run a query with subquery
# Then check debug log:
docker exec manticore_production cat /tmp/subquery-plugin-debug.log
```

If log file doesn't exist, the plugin's classes aren't being loaded correctly.

**Solution:** Verify namespace and autoloader setup:
```bash
# Test class loading
docker exec manticore_production bash -c "cd /usr/share/manticore/modules/manticore-buddy && manticore-executor -r \"
require_once 'vendor/autoload.php';
echo class_exists('Manticoresearch\\\Buddy\\\Plugin\\\SubqueryResolver\\\Payload') ? 'OK' : 'FAIL';
\""
```

Expected: `OK`

### Queries Hang Forever

**Problem:** Query accepted by plugin but never returns

**Check handler logs:**
```bash
docker exec manticore_production cat /tmp/subquery-handler-debug.log
```

If handler log shows it started but didn't finish, check for errors in Buddy logs:
```bash
docker logs manticore_production 2>&1 | tail -100
```

### "Cannot use object as array" Error

**Problem:** Error in Buddy logs about Response object

This means the plugin code has a bug. Report it to the plugin repository with:
- The query you ran
- Full error message from `docker logs`
- Handler debug log content

---

## Uninstalling

To remove the plugin:

```bash
# 1. Remove from Buddy's autoloader
docker exec manticore_production bash -c "sed -i '/buddy-plugin-subquery-resolver/d' /usr/share/manticore/modules/manticore-buddy/vendor/autoload.php"

# 2. Remove plugin directory
docker exec manticore_production rm -rf /usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver

# 3. Restart container
docker restart manticore_production

# 4. Verify removal
docker logs manticore_production 2>&1 | grep "local:"
# Should NOT show subquery-resolver
```

---

## Updating the Plugin

### Method 1: Packagist Plugin Updates

```sql
-- Connect to Manticore
mysql -h127.0.0.1 -P9306

-- Drop the old version
DROP PLUGIN visi/buddy-plugin-subquery-resolver;

-- Install the new version
CREATE PLUGIN visi/buddy-plugin-subquery-resolver
TYPE 'buddy'
VERSION 'v1.1.0';  -- or 'latest'
```

**No container restart needed!**

### Method 2: Manual Plugin Updates

```bash
# 1. Pull latest changes
cd buddy-plugin-subquery-resolver
git pull origin main

# 2. Copy updated files to container
docker cp src manticore_production:/usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver/

# 3. Restart container
docker restart manticore_production

# 4. Verify update
docker logs manticore_production 2>&1 | grep "subquery-resolver"
```

---

## Method Comparison

| Feature | Packagist (Method 1) | Manual (Method 2) |
|---------|---------------------|-------------------|
| Installation | One SQL command | Multi-step process |
| Updates | SQL command, no restart | Copy files, restart container |
| Version management | Built-in | Manual tracking |
| Production ready | ✅ Yes | ⚠️ For testing only |
| Requires publishing | ✅ Yes | ❌ No |
| Works offline | After first install | ✅ Yes |

**Recommendation:**
- **Production:** Use Method 1 (Packagist) when available
- **Development/Testing:** Use Method 2 (Manual)
- **Private plugins:** Use Method 2 (Manual)

---

## Managing Plugins via SQL (Packagist Only)

Once plugins are published to Packagist, you can manage them entirely via SQL:

### View Installed Plugins

```sql
-- Note: There's no official SHOW PLUGINS command yet
-- Check via logs instead:
```

```bash
docker logs YOUR_CONTAINER 2>&1 | grep "Loaded plugins"
```

**Expected output:**
```
[BUDDY] Loaded plugins:
[BUDDY]   core: backup, insert, select, ...
[BUDDY]   local: (any local development plugins)
[BUDDY]   extra: subquery-resolver
```

### Install Plugin

```sql
CREATE PLUGIN manticoresoftware/buddy-plugin-subquery-resolver
TYPE 'buddy'
VERSION 'latest';  -- or specific version like 'v1.0.0'
```

**What happens:**
1. Buddy downloads the plugin from Packagist using Composer
2. Installs to `/usr/share/manticore/modules/manticore-buddy/plugins/`
3. Registers the plugin autoloader
4. Plugin becomes immediately available (no restart needed!)

### Uninstall Plugin

```sql
DROP PLUGIN manticoresoftware/buddy-plugin-subquery-resolver;
```

### Upgrade Plugin

```sql
-- Remove old version
DROP PLUGIN manticoresoftware/buddy-plugin-subquery-resolver;

-- Install new version
CREATE PLUGIN manticoresoftware/buddy-plugin-subquery-resolver
TYPE 'buddy'
VERSION 'latest';
```

**Note:** The `CREATE PLUGIN` command only works with plugins published to Packagist. For unpublished plugins, use the manual installation method.

---

## Docker Compose Configuration

### Method 1: For Packagist Plugins

**docker-compose.yml:**
```yaml
version: '3'
services:
  manticore:
    image: manticoresearch/manticore:latest
    volumes:
      - ./data:/var/lib/manticore
      - ./config:/etc/manticoresearch
    ports:
      - "9306:9306"
      - "9308:9308"
```

**After starting, install plugin via SQL:**
```sql
CREATE PLUGIN manticoresoftware/buddy-plugin-subquery-resolver TYPE 'buddy' VERSION 'latest';
```

### Method 2: For Manual Plugins

If using docker-compose, you can mount the plugin directory directly:

**docker-compose.yml:**
```yaml
version: '3'
services:
  manticore:
    image: manticoresearch/manticore:latest
    volumes:
      # Your data volumes
      - ./data:/var/lib/manticore
      - ./config:/etc/manticoresearch

      # Mount plugin directory
      - ./buddy-plugin-subquery-resolver:/usr/share/manticore/modules/manticore-buddy/plugins/buddy-plugin-subquery-resolver

      # Mount modified autoloader (create this file first)
      - ./autoload.php:/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php
    ports:
      - "9306:9306"
      - "9308:9308"
```

**Create autoload.php locally** with the plugin registration, then start:
```bash
docker-compose up -d
```

---

## Quick Decision Guide

**Choose your installation method:**

```
Is the plugin published on Packagist?
│
├─ Yes → Use CREATE PLUGIN (Method 1)
│         ✅ Easiest, official, one SQL command
│
└─ No → Is it ready for production?
    │
    ├─ Yes → Publish to Packagist first, then use CREATE PLUGIN
    │         ✅ Best for production use
    │
    └─ No → Use manual installation (Method 2)
              ✅ Good for testing/development
```

**Examples:**

- **"I want to use this plugin in production"**
  → Wait for Packagist publish, then use `CREATE PLUGIN`

- **"I'm testing an unreleased version"**
  → Use manual installation

- **"I'm developing my own plugin"**
  → See [DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md)

- **"I have a private plugin for internal use"**
  → Use manual installation or publish to private Packagist

---

## Learn More

**Official Resources:**
- [Manticore Buddy: Pluggable Design](https://manticoresearch.com/blog/manticoresearch-buddy-pluggable-design/) - Official plugin architecture guide
- [Buddy Plugin Template](https://github.com/manticoresoftware/buddy-plugin-template) - Official template repository
- [Buddy Core Repository](https://github.com/manticoresoftware/buddy-core) - Core library documentation

**This Plugin:**
- [README.md](README.md) - Plugin overview and usage
- [DEVELOPMENT_GUIDE.md](DEVELOPMENT_GUIDE.md) - Develop your own Buddy plugins

**Community:**
- [Manticore Forum](https://forum.manticoresearch.com)
- [Manticore Slack](https://manticoresearch.com/slack)
- [GitHub Discussions](https://github.com/manticoresoftware/manticoresearch/discussions)

---

## Production Deployment Checklist

Before deploying to production:

- [ ] Test plugin thoroughly with your actual data
- [ ] Review debug logs and remove debug logging (or set to errors only)
- [ ] Test with empty result sets
- [ ] Test with large result sets (1000+ values)
- [ ] Verify performance impact is acceptable
- [ ] Have rollback plan ready (uninstall steps documented)
- [ ] Monitor Buddy logs after deployment for errors
- [ ] Document the plugin usage for your team

---

## Support

If you encounter issues:

1. Check debug logs: `/tmp/subquery-plugin-debug.log` and `/tmp/subquery-handler-debug.log`
2. Check Buddy logs: `docker logs YOUR_CONTAINER`
3. Report issues on GitHub: [Repository Issues](https://github.com/yourusername/buddy-plugin-subquery-resolver/issues)

Include in your bug report:
- Manticore version (`SELECT @@version`)
- Full query that fails
- Debug log contents
- Relevant Buddy log lines

---

## License

GPL-2.0-or-later

Copyright (c) 2024-2026, Manticore Software LTD
