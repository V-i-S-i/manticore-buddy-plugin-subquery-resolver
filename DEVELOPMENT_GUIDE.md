# Manticore Buddy Plugin Development Guide

**Official methodology based on [Manticore Buddy: pluggable design](https://manticoresearch.com/blog/manticoresearch-buddy-pluggable-design/)**

A comprehensive guide to developing Manticore Buddy plugins using the official development workflow and best practices.

## Table of Contents

1. [Overview](#overview)
2. [Development Approaches](#development-approaches)
3. [Official Development Setup](#official-development-setup)
4. [Plugin Architecture](#plugin-architecture)
5. [Creating Your Plugin](#creating-your-plugin)
6. [Core Components](#core-components)
7. [Critical Implementation Details](#critical-implementation-details)
8. [Testing & Debugging](#testing--debugging)
9. [Common Pitfalls](#common-pitfalls)
10. [Deployment Options](#deployment-options)

---

## Overview

Manticore Buddy uses a **pluggable architecture** that allows you to extend Manticore Search with custom SQL/JSON query handlers. Plugins can be developed locally, published to Packagist, and installed via the `CREATE PLUGIN` SQL command.

### Plugin Types

- **Core** - Included by default with Buddy
- **Local** - Development plugins in the `plugins/` directory (auto-detected)
- **External** - Published on Packagist and installed via `CREATE PLUGIN`

---

## Quick Start Guide

**Choose your path:**

### Path 1: Full Development Setup (Recommended for Active Development)

```bash
# 1. Clone Buddy
git clone https://github.com/manticoresoftware/manticoresearch-buddy.git
cd manticoresearch-buddy

# 2. Start test-kit
docker run -v $(pwd):/workdir -w /workdir \
  --name buddy-dev -it \
  ghcr.io/manticoresoftware/manticoresearch:test-kit-6.2.12

# 3. Install dependencies
docker exec buddy-dev composer install

# 4. Create plugin from template
# (Use GitHub template, clone to plugins/)

# 5. Test
docker exec buddy-dev searchd --nodetach
```

**Time:** 15-30 minutes setup, then fast iteration

### Path 2: Direct Plugin Development (Quick Testing)

```bash
# 1. Create plugin structure manually
mkdir -p buddy-plugin-your-name/src

# 2. Copy to container
docker cp buddy-plugin-your-name \
  YOUR_CONTAINER:/usr/share/manticore/modules/manticore-buddy/plugins/

# 3. Setup autoloader (see detailed instructions below)

# 4. Restart
docker restart YOUR_CONTAINER
```

**Time:** 5-10 minutes setup, restart needed for changes

**This guide covers both paths in detail.**

---

## Development Approaches

### Option 1: Official Development Environment (Recommended)

**Best for:** Active plugin development, contributing to Buddy

- Uses Buddy source code
- Manticore Test Kit Docker image
- Hot-reload during development
- Full Composer integration

### Option 2: Direct Deployment to Running Container

**Best for:** Quick testing on existing installations

- Deploy to production Buddy instance
- Manual autoloader setup
- No need for full dev environment

**This guide covers both approaches.**

---

## Official Development Setup

### Prerequisites

- Docker Desktop or Docker Engine
- Git
- Basic command line knowledge

### Step 1: Clone Buddy Source Code

```bash
# Clone the Buddy repository
git clone https://github.com/manticoresoftware/manticoresearch-buddy.git
cd manticoresearch-buddy

# Switch to the version you're targeting (v1.x or main for latest)
git checkout v1.x  # Or: git checkout main
```

### Step 2: Start Manticore Test Kit

The **Manticore Executor Kit** includes everything needed for Buddy development:

```bash
# Pull the test-kit image
docker pull ghcr.io/manticoresoftware/manticoresearch:test-kit-6.2.12

# Create development container with Buddy sources mounted
docker create --privileged --entrypoint bash \
  -v $(pwd):/workdir -w /workdir \
  --name manticore-buddy-dev \
  -it ghcr.io/manticoresoftware/manticoresearch:test-kit-6.2.12

# Start container
docker start manticore-buddy-dev
```

**Windows PowerShell:**
```powershell
docker create --privileged --entrypoint bash `
  -v ${PWD}:/workdir -w /workdir `
  --name manticore-buddy-dev `
  -it ghcr.io/manticoresoftware/manticoresearch:test-kit-6.2.12

docker start manticore-buddy-dev
```

### Step 3: Install Buddy Dependencies

```bash
# Enter container
docker exec -it manticore-buddy-dev bash

# Install Composer dependencies
composer install
```

### Step 4: Configure Manticore to Use Buddy Sources

Edit `/etc/manticoresearch/manticore.conf` inside the container:

```bash
docker exec -it manticore-buddy-dev bash

# Add to searchd section
cat >> /etc/manticoresearch/manticore.conf << 'EOF'

searchd {
    listen = 9306:mysql
    listen = 9308:http
    log = /var/log/manticore/searchd.log
    query_log = /var/log/manticore/query.log

    # Point to Buddy sources for development
    buddy_path = manticore-executor /workdir/src/main.php --debug
}
EOF
```

### Step 5: Test Buddy Development Environment

```bash
# Start searchd in foreground (in container)
searchd --nodetach
```

**Expected output:**
```
[BUDDY] Started Manticore Buddy ...
[BUDDY] Loaded plugins:
[BUDDY]   core: backup, insert, ...
```

Press `Ctrl+C` to stop.

**Test with a query** (open another terminal):
```bash
docker exec -it manticore-buddy-dev mysql -h0 -P9306

# In MySQL client:
SHOW QUERIES;
```

If you see output, Buddy is working! 🎉

---

## Plugin Architecture

Manticore Buddy plugins consist of two main classes:

1. **Payload Class** - Detects if a request should be handled by the plugin
2. **Handler Class** - Executes the plugin logic and returns results

### Plugin Lifecycle

```
Request arrives at Manticore
    ↓
Manticore can't handle it (syntax error) → Forwards to Buddy
    ↓
Buddy iterates through all plugins
    ↓
Each plugin's Payload::hasMatch() is called
    ↓
First matching plugin → Payload::fromRequest() creates payload object
    ↓
Handler::run() executes the plugin logic
    ↓
Returns TaskResult to Buddy → Buddy returns to client
```

---

## Creating Your Plugin

### Option A: From Official Template (Recommended)

**1. Use the GitHub template:**

Visit: https://github.com/manticoresoftware/buddy-plugin-template
- Click **"Use this template"** → **"Create a new repository"**
- Name it: `buddy-plugin-{your-plugin-name}` (e.g., `buddy-plugin-show-hostname`)
- Clone your new repo

**2. Clone into Buddy's plugins directory:**

```bash
# On your host machine
cd manticoresearch-buddy/plugins

# Clone your plugin repo
git clone https://github.com/yourusername/buddy-plugin-your-name.git
```

**Or inside the dev container:**
```bash
docker exec -it manticore-buddy-dev bash
cd /workdir/plugins
git clone https://github.com/yourusername/buddy-plugin-your-name.git
```

### Option B: Manual Plugin Structure

```
buddy-plugin-{name}/
├── composer.json              # Package definition
├── README.md                  # Plugin documentation
├── src/
│   ├── Payload.php           # Request matcher
│   └── Handler.php           # Execution logic
└── vendor/
    └── autoload.php          # Autoloader (generated by composer or custom)
```

### Configuring composer.json

**Update these fields in your plugin's composer.json:**

```json
{
  "name": "manticoresoftware/buddy-plugin-{your-name}",
  "description": "Brief description of what the plugin does",
  "type": "library",
  "license": "GPL-2.0-or-later",
  "autoload": {
    "psr-4": {
      "Manticoresearch\\Buddy\\Plugin\\{YourPluginName}\\": "src/"
    }
  },
  "require": {
    "php": ">=8.1",
    "manticoresoftware/buddy-core": "^3.0"
  },
  "authors": [
    {
      "name": "Your Name",
      "email": "you@example.com"
    }
  ]
}
```

**Critical Naming Convention:**
- Package name: `manticoresoftware/buddy-plugin-{your-name}` (lowercase with hyphens)
- Directory name: `buddy-plugin-{your-name}` (must match package name suffix)
- Namespace: `Manticoresearch\Buddy\Plugin\{YourPluginName}\` (CamelCase)

**Example:** For a "show hostname" plugin:
- Package: `manticoresoftware/buddy-plugin-show-hostname`
- Directory: `buddy-plugin-show-hostname`
- Namespace: `Manticoresearch\Buddy\Plugin\ShowHostname\`

### Update Class Namespaces

**In src/Payload.php and src/Handler.php**, update the namespace:

```diff
- namespace Manticoresearch\Buddy\Plugin\Template;
+ namespace Manticoresearch\Buddy\Plugin\YourPluginName;
```

### Install Dependencies

```bash
# Inside the dev container
docker exec -it manticore-buddy-dev bash
cd /workdir/plugins/buddy-plugin-your-name
composer install
```

---

## Core Components

### 1. Payload Class

**Purpose:** Determine if a request matches this plugin and parse request data.

**Required Methods:**

```php
<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Plugin\SubqueryResolver;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

final class Payload extends BasePayload
{
    public string $query; // Store parsed data as public properties

    /**
     * Return plugin description (shown in SHOW PLUGINS)
     * CRITICAL: Must return string, NOT array
     */
    public static function getInfo(): string
    {
        return 'Brief description of what this plugin does';
    }

    /**
     * Check if this plugin should handle the request
     * Called for EVERY request that Manticore forwards to Buddy
     */
    public static function hasMatch(Request $request): bool
    {
        $query = self::getQuery($request);

        // Your matching logic here
        // Return true if this plugin should handle it
        return preg_match('/your-pattern/i', $query) > 0;
    }

    /**
     * Create payload instance from request
     * Only called if hasMatch() returned true
     */
    public static function fromRequest(Request $request): static
    {
        $payload = new static();
        $payload->query = self::getQuery($request);
        // Parse and store other data as needed
        return $payload;
    }

    /**
     * Extract query from request
     */
    protected static function getQuery(Request $request): string
    {
        $payload = $request->payload;

        if (is_string($payload)) {
            return trim($payload);
        }

        if (is_array($payload) && isset($payload['query'])) {
            return trim($payload['query']);
        }

        return '';
    }
}
```

**Key Points:**
- `getInfo()` returns **string**, not array (common mistake!)
- `hasMatch()` is called for every forwarded request - keep it fast
- Use `self::getQuery($request)` to safely extract the query
- Store parsed data in public properties for Handler to access

---

### 2. Handler Class

**Purpose:** Execute the plugin logic and return results.

**Template:**

```php
<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Plugin\SubqueryResolver;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{
    /**
     * Initialize handler with payload
     */
    public function __construct(public Payload $payload)
    {
    }

    /**
     * Execute plugin logic
     * CRITICAL: Must return Task, and call ->run() on it!
     */
    public function run(): Task
    {
        // Define task function as STATIC
        $taskFn = static function (
            Payload $payload,
            HTTPClient $manticoreClient
        ): TaskResult {
            // Your plugin logic here

            // Execute queries using manticoreClient
            $response = $manticoreClient->sendRequest('SELECT ...');

            // Check for errors
            if ($response->hasError()) {
                throw new RuntimeException('Query failed: ' . $response->getError());
            }

            // Get data from response
            $data = $response->getData();

            // Return result
            return TaskResult::fromResponse($response);
        };

        // CRITICAL: Must pass arguments AND call ->run()
        return Task::create(
            $taskFn,
            [$this->payload, $this->manticoreClient]
        )->run(); // ← Don't forget ->run()!
    }
}
```

---

## Critical Implementation Details

### 1. Task Execution

**❌ WRONG** - Task never executes, query hangs:
```php
return Task::create($taskFn);
```

**✅ CORRECT** - Task executes immediately:
```php
return Task::create($taskFn, [$this->payload, $this->manticoreClient])->run();
```

### 2. Response vs Array

The `manticoreClient->sendRequest()` returns a **Response object**, not an array!

**❌ WRONG:**
```php
$result = $manticoreClient->sendRequest($query);
if (isset($result['error'])) { // Error: can't use object as array
```

**✅ CORRECT:**
```php
$response = $manticoreClient->sendRequest($query);
if ($response->hasError()) {
    $error = $response->getError();
}
$data = $response->getData(); // Returns array
```

**Response object methods:**
- `hasError(): bool` - Check if query failed
- `getError(): ?string` - Get error message
- `getData(): array` - Get result data as array
- `getColumns(): array` - Get column definitions
- `getTotal(): int` - Get total rows count

### 3. TaskResult Methods

**Available methods:**
```php
TaskResult::fromResponse($response)  // ← Use this for query results
TaskResult::raw($data)
TaskResult::withData($array)
TaskResult::withError($message)
TaskResult::none()
```

**❌ WRONG:**
```php
return TaskResult::withResponse($response); // Method doesn't exist!
```

**✅ CORRECT:**
```php
return TaskResult::fromResponse($response);
```

### 4. Handling Multi-Value Attributes (MVA)

Manticore returns MVA fields as comma-separated strings in a single row:

```json
[{"keyword_id": "123,456,789"}]
```

**Handle MVA data:**
```php
$firstValue = reset($row);

// Detect and split comma-separated MVA values
if (is_string($firstValue) && str_contains($firstValue, ',')) {
    $values = explode(',', $firstValue);
    foreach ($values as $val) {
        $val = trim($val);
        // Process each value
    }
}
```

### 5. Static Task Function

The task function **must be static** to avoid serialization issues:

```php
// CRITICAL: Use 'static function', not just 'function'
$taskFn = static function (
    Payload $payload,
    HTTPClient $manticoreClient
): TaskResult {
    // Access via parameters, not $this
    $query = $payload->query;
    // ...
};
```

---

## Common Pitfalls

### 1. Autoload Issues

**Problem:** Plugin loads but `hasMatch()` never called
- **Cause:** Plugin classes not registered in Buddy's autoloader
- **Solution:** Add plugin autoload to Buddy's vendor/autoload.php (see Deployment section)

### 2. Query Hangs Forever

**Problem:** Query accepted by plugin but never returns
- **Cause:** Forgot to call `->run()` on Task
- **Solution:** Always use `Task::create(...)->run()`

### 3. "Cannot use object as array" Error

**Problem:** Trying to access Response object like an array
- **Cause:** Using `$result['key']` instead of `$result->getMethod()`
- **Solution:** Use Response object methods

### 4. Plugin Not Matching Requests

**Problem:** Plugin exists but never intercepts queries
- **Cause:** `hasMatch()` returns false, or plugin not in correct directory
- **Solution:**
  - Add debug logging to `hasMatch()` to verify it's being called
  - Check plugin is in `/usr/share/manticore/modules/manticore-buddy/plugins/`
  - Verify namespace matches directory name convention

---

## Testing with Local Plugins (Official Method)

Once your plugin is in the `plugins/` directory, Buddy automatically detects it as a **local plugin**.

### Register Plugin in Buddy (Development)

```bash
# Inside the dev container, in Buddy root directory
docker exec -it manticore-buddy-dev bash
cd /workdir

# Add your plugin as a Composer dev dependency
composer require manticoresoftware/buddy-plugin-your-name:dev-main

# Restart searchd
pkill searchd
searchd --nodetach
```

**In another terminal, verify plugin loaded:**
```bash
docker logs manticore-buddy-dev 2>&1 | grep "local:"
# Should show: [BUDDY]   local: your-name
```

### Live Development

With this setup, you can:
1. Edit plugin code in `plugins/buddy-plugin-your-name/src/`
2. Restart searchd (`Ctrl+C` then `searchd --nodetach`)
3. Test immediately - no need to redeploy!

### Test Queries

```bash
# Connect to Manticore
docker exec -it manticore-buddy-dev mysql -h0 -P9306

# Run your test query
SELECT ...;
```

---

## Testing & Debugging

### Enable Buddy Debug Mode

For comprehensive debugging during plugin development, you can enable Buddy's verbose debug logging.

**Add to your searchd configuration** (e.g., `/etc/manticoresearch/manticore.conf`):

```ini
searchd {
    # ... other settings ...

    # Enable Buddy debug mode with verbose logging
    buddy_path = manticore-executor -n /usr/share/manticore/modules/manticore-buddy/src/main.php --log-level=debugvv
}
```

**Log levels available:**
- `--log-level=debug` - Standard debug output
- `--log-level=debugvv` - Very verbose debug output (recommended for plugin development)

**After changing configuration:**
```bash
# Restart Manticore to apply changes
docker restart YOUR_CONTAINER
```

**View debug output:**
```bash
# Watch Buddy logs in real-time
docker logs -f YOUR_CONTAINER 2>&1 | grep BUDDY

# Or view all recent logs
docker logs YOUR_CONTAINER 2>&1 | tail -100
```

**When to use debug mode:**
- Developing new plugins
- Troubleshooting plugin loading issues
- Debugging why queries aren't being intercepted
- Understanding Buddy's internal flow

**Note:** Debug mode produces significant log output. Disable it in production by removing the `--log-level` parameter.

### Add Debug Logging

In **Payload.php** `hasMatch()`:
```php
public static function hasMatch(Request $request): bool
{
    $logFile = '/tmp/my-plugin-debug.log';
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] hasMatch() called\n", FILE_APPEND);

    $query = self::getQuery($request);
    file_put_contents($logFile, "  Query: $query\n", FILE_APPEND);

    $matches = preg_match('/pattern/', $query);
    file_put_contents($logFile, "  Matches: " . ($matches ? 'YES' : 'NO') . "\n\n", FILE_APPEND);

    return $matches > 0;
}
```

In **Handler.php** `run()`:
```php
$taskFn = static function (...): TaskResult {
    $logFile = '/tmp/my-plugin-handler.log';
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Handler started\n", FILE_APPEND);

    // Log each step
    file_put_contents($logFile, "  Step 1: Executing query\n", FILE_APPEND);
    $response = $manticoreClient->sendRequest($query);

    file_put_contents($logFile, "  Step 2: Result: " . json_encode($response->getData()) . "\n", FILE_APPEND);

    // ...
};
```

### Check Debug Logs

```bash
# In container
docker exec manticore2026 cat /tmp/my-plugin-debug.log
docker exec manticore2026 cat /tmp/my-plugin-handler.log

# Buddy logs
docker logs manticore2026 | grep BUDDY
```

### Verify Plugin is Loaded

```sql
-- Should show your plugin in the 'local' section
```

Run in mysql client connected to port 9306, then check Buddy logs:
```bash
docker logs manticore2026 | grep "Loaded plugins"
```

---

## Deployment Options

### Option 1: Publish to Packagist (Official Method)

**Best for:** Sharing plugins with the community, production use

**Steps:**

1. **Prepare your plugin:**
   ```bash
   # Ensure composer.json is correct
   # Commit all changes
   git add .
   git commit -m "Release v1.0.0"
   git tag v1.0.0
   git push origin main --tags
   ```

2. **Publish to Packagist:**
   - Visit https://packagist.org/packages/submit
   - Enter your GitHub repository URL
   - Click "Check"
   - Follow instructions to set up auto-updates

3. **Install on any Manticore instance:**
   ```sql
   -- Connect to Manticore
   mysql -h127.0.0.1 -P9306

   -- Install your plugin
   CREATE PLUGIN manticoresoftware/buddy-plugin-your-name
   TYPE 'buddy'
   VERSION 'v1.0.0';
   ```

4. **Verify installation:**
   ```bash
   docker logs YOUR_CONTAINER | grep "extra:"
   # Should show: [BUDDY]   extra: your-name
   ```

**Benefits:**
- One-command installation
- Version management
- Automatic updates
- Official distribution method

---

### Option 2: Manual Deployment to Container (Development/Testing)

**Best for:** Testing unreleased plugins, private deployments

**1. Create autoload.php without Composer:**

Create `vendor/autoload.php` in your plugin directory:

```php
<?php
// Load Buddy's main autoloader for dependencies
require_once '/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php';

// Register plugin namespace
spl_autoload_register(function ($class) {
    $prefix = 'Manticoresearch\\Buddy\\Plugin\\YourPluginName\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
```

**2. Copy plugin to Buddy plugins directory:**

```bash
# Copy plugin to Buddy's local plugins directory
docker cp ./buddy-plugin-yourname manticore2026:/usr/share/manticore/modules/manticore-buddy/plugins/
```

**3. Register in Buddy's autoloader:**

Add this to `/usr/share/manticore/modules/manticore-buddy/vendor/autoload.php` (before the `return` statement):

```php
// Load yourname plugin autoloader
$pluginAutoload = __DIR__ . '/../plugins/buddy-plugin-yourname/vendor/autoload.php';
if (file_exists($pluginAutoload)) {
    require_once $pluginAutoload;
}
```

**4. Restart Manticore container:**

```bash
docker restart manticore2026
```

**5. Verify plugin loaded:**

```bash
docker logs manticore2026 | grep "local:"
# Should show: [BUDDY]   local: yourname
```

### Automated Deployment Script

See [deploy.ps1](deploy.ps1) and [deploy.bat](deploy.bat) for automated deployment scripts.

---

## What You Discovered vs. Official Docs

Your trial-and-error development uncovered many critical implementation details not clearly documented:

| Aspect | Your Discovery | Official Docs |
|--------|----------------|---------------|
| **Task execution** | Must call `->run()` on Task | ❌ Not mentioned |
| **Response handling** | Use Response object methods, not array access | ❌ Not documented |
| **TaskResult method** | `fromResponse()` not `withResponse()` | ❌ Not clear |
| **Static task function** | Must be `static function` | ❌ Not emphasized |
| **getInfo() return type** | Must return **string**, not array | ❌ Not specified |
| **MVA handling** | Comma-separated values in single row | ❌ Not documented |
| **Development workflow** | Official uses test-kit + Composer | ✅ Well documented |
| **Plugin types** | Core, local, external | ✅ Well documented |
| **Publishing** | Packagist + CREATE PLUGIN | ✅ Well documented |

**Your contribution:** The detailed implementation patterns in this guide fill critical gaps in the official documentation!

---

## Official Documentation References

**Primary source for this guide:**
- [Manticore Buddy: Pluggable Design](https://manticoresearch.com/blog/manticoresearch-buddy-pluggable-design/) (March 2023)
  - Development environment setup
  - Plugin template usage
  - Local plugin workflow
  - Publishing to Packagist

**Note:** The blog post is for Buddy v1.x. Check for updates if using newer versions.

**What this guide adds:**
- ✅ Critical implementation details (Task execution, Response handling, etc.)
- ✅ Common pitfalls and how to avoid them
- ✅ Real-world debugging techniques
- ✅ Both official and manual deployment workflows
- ✅ Production-ready patterns

---

## Next Steps

1. Review official Buddy plugin examples:
   - [buddy-plugin-show](https://github.com/manticoresoftware/buddy-plugin-show)
   - [buddy-plugin-insert-mva](https://github.com/manticoresoftware/buddy-plugin-insert-mva)

2. Read Buddy documentation:
   - [Buddy Pluggable Design](https://manticoresearch.com/blog/manticoresearch-buddy-pluggable-design/)
   - [Buddy Core Repository](https://github.com/manticoresoftware/buddy-core)

3. Test thoroughly with real data before production use

---

## Workflow Comparison

### Official Development → Publish Workflow

```
1. Clone Buddy sources
2. Start test-kit container
3. Create plugin from template
4. Develop in plugins/ directory
5. Test as local plugin (composer require :dev-main)
6. Commit to Git
7. Publish to Packagist
8. Install via CREATE PLUGIN
```

**Pros:**
- Proper Composer integration
- Hot-reload during development
- Easy to publish and share
- Version management

**Cons:**
- Requires full dev environment setup
- More complex initial setup

### Manual Deployment Workflow

```
1. Create plugin locally
2. Copy to container's plugins/ directory
3. Create custom autoloader
4. Register in Buddy's autoloader
5. Restart container
```

**Pros:**
- Quick deployment to existing instances
- No dev environment needed
- Works for private/unpublished plugins

**Cons:**
- Manual steps required
- No version management
- Must repeat for each update
- Not suitable for production distribution

**Recommendation:** Use official workflow for serious development, manual deployment for quick testing.

---

## Summary Checklist

### For Official Development Workflow

✅ **Environment Setup:**
- [ ] Cloned Buddy repository
- [ ] Started test-kit container
- [ ] Installed Composer dependencies
- [ ] Configured buddy_path in manticore.conf
- [ ] Tested Buddy with `searchd --nodetach`

✅ **Plugin Creation:**
- [ ] Created from official template or cloned template
- [ ] Updated `composer.json` with correct name and namespace
- [ ] Updated namespaces in Payload.php and Handler.php
- [ ] Ran `composer install` in plugin directory

✅ **Local Development:**
- [ ] Plugin in `/workdir/plugins/buddy-plugin-{name}/`
- [ ] Registered via `composer require :dev-main` in Buddy root
- [ ] Verified in logs: `local: plugin-name`

✅ **Publishing:**
- [ ] Committed to Git with version tag
- [ ] Submitted to Packagist
- [ ] Tested installation via CREATE PLUGIN

### For Manual Deployment

✅ **Plugin Structure:

✅ **Plugin Structure:**
- [ ] `composer.json` with correct namespace
- [ ] `Payload.php` extends `BasePayload`
- [ ] `Handler.php` extends `BaseHandlerWithClient`
- [ ] `vendor/autoload.php` for autoloading

✅ **Payload Class:**
- [ ] `getInfo()` returns **string** (not array!)
- [ ] `hasMatch()` efficiently detects matching requests
- [ ] `fromRequest()` parses and stores request data

✅ **Handler Class:**
- [ ] Task function is **static**
- [ ] Uses `Response` object methods (not array access)
- [ ] Returns `TaskResult::fromResponse($response)`
- [ ] **Calls `->run()` on Task**

✅ **Deployment:**
- [ ] Plugin in `/usr/share/manticore/modules/manticore-buddy/plugins/`
- [ ] Custom autoloader in `vendor/autoload.php`
- [ ] Registered in Buddy's main autoloader
- [ ] Container restarted
- [ ] Verified in logs: `local: plugin-name`

✅ **Testing:**
- [ ] Debug logging added to Payload and Handler
- [ ] Tested with real queries
- [ ] Checked Buddy logs for errors
- [ ] Verified results are correct

Good luck building your Manticore Buddy plugin! 🚀
