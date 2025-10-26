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

### 1. `get_size_suffix_map()`
**Returns**: `['l' => 'large1024', 'o' => 'original', ...]`
**Used by**: Album response parsing
**Generated from**: `suffix` property

### 2. `get_size_label_map()`
**Returns**: `['large1024' => ['Large 1024', 'Large 1600', 'Original'], ...]`
**Used by**: Individual photo API parsing
**Generated from**: `labels` property

### 3. `get_comprehensive_size_list()`
**Returns**: `['large1024', 'original', 'medium500', ...]`
**Used by**: Cache key generation
**Generated from**: All sizes with `suffix` defined

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

## Philosophy

**Before**: Size mappings hardcoded in 4+ places throughout the codebase
**After**: Single definition in ONE method, derived everywhere else
**Benefit**: Change once, updates everywhere automatically
