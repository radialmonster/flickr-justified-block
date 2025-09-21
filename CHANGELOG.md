# Changelog

All notable changes to the Flickr Justified Block plugin will be documented in this file.

## [Unreleased] - 2025-09-21

### Added
- New admin JavaScript file (`assets/js/admin.js`) for enhanced admin functionality
- New justified gallery initialization script (`assets/js/justified-init.js`) replacing the old Packery implementation
- ASCII-compatible character replacements throughout the codebase for better compatibility
- Enhanced admin settings with better organization and error handling
- Improved API key testing functionality with better user feedback
- Internal logging system that respects WP_DEBUG setting

### Changed
- **BREAKING**: Replaced Packery layout library with custom justified gallery implementation
- Updated editor JavaScript to use ASCII-compatible characters instead of emojis
- Improved admin settings interface with better visual feedback
- Enhanced code formatting and organization in admin settings
- Updated success/error indicators to use ASCII characters (`[OK]`, `[ERROR]`) instead of Unicode symbols
- Modernized JavaScript code structure and error handling

### Removed
- Removed Packery initialization script (`assets/js/packery-init.js`)
- Eliminated all Unicode/emoji characters from JavaScript and PHP files
- Removed dependency on external Packery library for layout

### Fixed
- Fixed single image alignment flash issues
- Improved DOM element handling to prevent flashing
- Better CSS targeting for single image alignment
- Enhanced viewport height constraint handling
- Eliminated infinite loops in DOM initialization
- Fixed hover effects that caused image bouncing
- Better handling of single image last row positioning

### Security
- Enhanced API key encryption and storage
- Improved input sanitization across all admin settings
- Better nonce verification for AJAX requests

### Technical Improvements
- Code reorganization for better maintainability
- Improved error handling and logging
- Better separation of concerns in JavaScript modules
- Enhanced compatibility with different server environments

---

## Previous Versions

For changes prior to this version, please refer to the git commit history.