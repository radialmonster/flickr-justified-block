# Flickr Size Mapping - Single Source of Truth

## Overview

All Flickr size mappings in this plugin are defined in **ONE place** and derive from a single source of truth.

## Location

**File**: `includes/cache.php`
**Method**: `FlickrJustifiedCache::get_size_definitions()` (lines 53-82)

## Structure

```php
[
    'size_name' => [
        'suffix' => 'x',              // Flickr URL suffix (for album API)
        'labels' => ['Label Name'],   // Flickr API labels (for photo API)
    ]
]
```

### Example

```php
'large1024' => [
    'suffix' => 'l',                                    // Used in: url_l from albums
    'labels' => ['Large 1024', 'Large 1600', 'Original'] // Fallback order
]
```

## Derived Methods (Auto-Generated)

All other size-related methods derive from `get_size_definitions()`:

### Private (internal cache class use)

- **`get_size_suffix_map()`** — `['l' => 'large1024', ...]` — Album response parsing
- **`get_size_label_map()`** — `['large1024' => ['Large 1024', ...], ...]` — Photo API parsing
- **`get_comprehensive_size_list()`** — `['large1024', 'original', ...]` — Cache key generation

### Public (used by render helpers, AJAX validation, JS config)

- **`get_size_names($include_thumbnails)`** — Ordered array of size name strings (replaces hardcoded lists in render-helpers.php and AJAX validation)
- **`get_suffix_to_name_map()`** — Public version of suffix map (delivered to frontend JS via Script Module data)
- **`get_name_to_suffix_map()`** — Inverse map for static URL construction (used by `build_static_url()`)

## Adding a New Size

To add a new Flickr size to the entire plugin:

1. Edit `includes/cache.php`
2. Add ONE line to `get_size_definitions()`:

```php
'mysize' => ['suffix' => 'x', 'labels' => ['My Size Label']],
```

That's it! The change propagates everywhere automatically.

## Available Sizes

| Size Name | URL Suffix | API Label | Notes |
|-----------|------------|-----------|-------|
| original | o | Original | Full resolution |
| large2048 | k | Large 2048 | 2048 on longest side |
| large1600 | h | Large 1600 | 1600 on longest side |
| large1024 | l | Large 1024 | 1024 on longest side |
| medium800 | c | Medium 800 | 800 on longest side |
| medium640 | z | Medium 640 | 640 on longest side |
| medium500 | m | Medium | 500 on longest side |
| small320 | n | Small 320 | 320 on longest side |
| small240 | s | Small | 240 on longest side |
| thumbnail100 | t | Thumbnail | 100 on longest side |
| thumbnail150s | q | Large Square 150 | 150x150 square |
| thumbnail75s | sq | Square 75 | 75x75 square |

## Consumers

All these locations now derive from `get_size_definitions()` instead of hardcoding:

- `includes/render-helpers.php` — `flickr_justified_get_available_flickr_sizes()` → `get_size_names()`
- `includes/render-helpers.php` — `flickr_justified_select_best_size()` size preference → `get_size_names()`
- `flickr-justified-block.php` — AJAX `$valid_sizes` validation → `get_size_names(true)`
- `includes/cache.php` — `build_static_url()` suffix map → `get_name_to_suffix_map()`
- `src/frontend/image-fallback.js` — JS sizeMap → read from Script Module data via `getSizeMap()`

## Philosophy

**Before**: Size mappings hardcoded in 4+ places throughout the codebase
**After**: Single definition in ONE method, derived everywhere else
**Benefit**: Change once, updates everywhere automatically
