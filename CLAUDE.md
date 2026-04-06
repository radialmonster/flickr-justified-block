# Flickr Justified Block — Notes for Claude

## Releasing a new version

This plugin auto-updates WordPress installs from GitHub Releases via
`includes/class-updater.php` (uses the WP 5.8+ `update_plugins_github.com`
filter). To ship a release:

### 1. Bump the version in **two** places in `flickr-justified-block.php`

```php
 * Version: 1.2.2
```
```php
define('FLICKR_JUSTIFIED_VERSION', '1.2.2');
```

Both must match the git tag (without the `v` prefix).

### 2. Commit and push

```bash
git add flickr-justified-block.php <other changed files>
git commit -m "Bump to v1.2.2 — <summary>"
git push origin main
```

### 3. Build the release zip

The zip **must** contain a single top-level folder named `flickr-justified-block/`
matching the plugin slug. Exclude dev files.

```bash
rm -rf /tmp/fjb-build && mkdir -p /tmp/fjb-build
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.claude' \
  --exclude='.gitignore' \
  --exclude='CLAUDE.md' \
  --exclude='node_modules' \
  --exclude='src' \
  --exclude='webpack.config.js' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  /path/to/flickr-justified-block/ \
  /tmp/fjb-build/flickr-justified-block/
cd /tmp/fjb-build && zip -r flickr-justified-block.zip flickr-justified-block/
```

Verify structure:
```bash
unzip -l /tmp/fjb-build/flickr-justified-block.zip | head
```
The first entry should be `flickr-justified-block/`.

> Note: `build/` (compiled block assets) **must** be included. `src/` and the
> webpack/npm files should be excluded since end users don't need to rebuild.
> If you add `build/` to `.gitignore`, run `npm run build` before zipping and
> double-check it's in the zip.

### 4. Create the GitHub Release

```bash
gh release create v1.2.2 /tmp/fjb-build/flickr-justified-block.zip \
  --title "v1.2.2" \
  --notes "$(cat <<'EOF'
## Changes
- <bullet>
- <bullet>
EOF
)"
```

The tag **must** start with `v`. The updater strips it via `ltrim($tag, 'v')`
before `version_compare()`.

### 5. Verify

```bash
gh release view v1.2.2
curl -s https://api.github.com/repos/radialmonster/flickr-justified-block/releases/latest \
  | jq '{tag: .tag_name, assets: [.assets[].name]}'
```

Expected: `tag` = `v1.2.2`, assets contains `flickr-justified-block.zip`.

WordPress sites running the plugin will see the update within 12 hours
(transient TTL in `Flickr_Justified_Block_Updater::CACHE_TTL`), or immediately
if the admin clicks **Check for Updates** on the settings page.

## Updater architecture (quick reference)

- `includes/class-updater.php` — `Flickr_Justified_Block_Updater`
  - Hooks `update_plugins_github.com` to inject GitHub release info
  - Hooks `plugins_api` for the "View Details" modal
  - Hooks `upgrader_install_package_result` to rename the extracted folder
  - `admin_post_flickr_justified_block_check_updates` handles manual checks
- Cache: 12h transient `flickr_justified_block_github_release`
- Plugin header `Update URI: https://github.com/radialmonster/flickr-justified-block`
  is what triggers the filter — do not remove it.
