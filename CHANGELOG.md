# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [0.2.6] - 2026-07-13

### Added
- Row-hover highlight in the templates table so it's always clear which row an
  action button belongs to.
- Hovering a **Delete** button now tints the whole row red (with a red left
  bar), confirming exactly which template is about to be deleted.

## [0.2.5] - 2026-07-13

### Added
- **Edit in Etch** button on every database template row. Links directly to the
  Etch editor (`?etch=magic&post_id={id}`) in a new tab, bypassing the Site
  Editor round-trip. Works for imported/hidden templates that Etch's own
  Template Manager does not list.

### Changed
- Site Editor demoted to a secondary action button (Edit in Etch is now primary).

## [0.2.4] - 2026-07-13

### Fixed
- Corrected the post-ID tooltip: the displayed `#id` matches Etch's `post_id`
  URL parameter, not `original_post_id` (which is only a preview-context post).

## [0.2.3] - 2026-07-13

### Added
- **Delete** button on database template rows. Deletes by `wp_template` post ID
  (so duplicate templates sharing a slug base are removed unambiguously), with a
  confirmation dialog. Server-side guarded to only ever delete `wp_template`
  posts, via nonce + `manage_options` checks.

## [0.2.2] - 2026-07-13

### Added
- Each database template now shows its `wp_template` post ID as `#id` next to the
  slug, to disambiguate duplicate templates that share a slug base.

## [0.2.1] - 2026-07-13

### Changed
- Replaced the private WordPress function `_build_block_template_result_from_post()`
  with the public `get_block_template()` API for long-term compatibility. Added a
  re-entrancy guard to prevent recursion into the `get_block_templates` filter.
- Frontend database template lookup is now `publish`-only (dropped `auto-draft`),
  so unpublished templates are never served on the front end.
- Template "Last Modified" now uses `wp_date()` so it respects the site timezone.

### Fixed
- Corrected the plugin header `Plugin URI` to the real repository URL.

## [0.2.0] - 2026-03-25

### Added
- Initial release: taxonomy-based single-template routing for FSE/EtchWP via the
  `get_block_templates` filter (priority 20).
- Admin dashboard with All Templates, Condition Rules, Created in Etch, and
  Help & Setup tabs.
- Rule builder with cascading taxonomy/term dropdowns, AND/OR multi-conditions,
  drag-and-drop ordering, inline rename, and inline publish/draft toggling.
- Programmatic rules via the `etch_tc_rules` filter.

[0.2.6]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.6
[0.2.5]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.5
[0.2.4]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.4
[0.2.3]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.3
[0.2.2]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.2
[0.2.1]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.1
[0.2.0]: https://github.com/harry1079/etch-template-conditions/releases/tag/v0.2.0
