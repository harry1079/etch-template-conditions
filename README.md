# Etch Template Conditions

Taxonomy-based template routing for WordPress Full Site Editing (FSE) and EtchWP.

WordPress FSE doesn't support serving different single-post templates based on taxonomy terms. This plugin adds that capability. Define rules that map a post type + taxonomy term to a specific template, then create and style that template in Etch as normal.

## How It Works

The plugin hooks into WordPress's `get_block_templates` filter to intercept FSE template resolution. When a visitor lands on a singular post that matches a rule, the plugin swaps in the correct template.

It uses standard WordPress APIs throughout — `get_block_template()`, `WP_Query`, `has_term()`, `wp_options` for storage, standard AJAX with nonce and capability checks. No private/underscore-prefixed core functions, no custom database tables, no monkey-patching, and no overriding of Etch internals — so it stays compatible across WordPress updates and won't clash if Etch later ships its own template-conditions feature (just deactivate this plugin at that point).

Because it hooks at priority 20 and Etch runs at priority 10, Etch always processes first. The plugin simply tells WordPress "for this particular post, use this template instead" — and that template is a normal FSE template you've built and styled in Etch like any other.

## Features

- Admin dashboard with template overview and rule builder
- Drag-and-drop rule ordering (first matching rule wins)
- Multiple conditions per rule with AND/OR logic
- **Edit in Etch** — open any template directly in the Etch editor from the dashboard, no Site Editor round-trip (works even for templates Etch's Template Manager doesn't list)
- **Delete** templates from the dashboard — deletes by post ID, so duplicates that share a slug base (e.g. builder imports) are removed unambiguously
- `wp_template` post IDs shown next to each slug to disambiguate same-named templates
- Inline template renaming for visual reference
- Inline status toggling (publish/draft) to quickly enable/disable templates
- "Created in Etch" tab to separate Etch-native templates from condition-based ones
- Programmatic rule support via the `etch_tc_rules` filter
- Templates are created and styled entirely within Etch's normal workflow

## Requirements

- WordPress 6.4+
- PHP 7.4+
- An FSE/block theme (designed for use with EtchWP)

## Installation

1. Download the latest release zip from the [Releases](../../releases) page
2. In WordPress, go to **Plugins > Add New > Upload Plugin**
3. Upload the zip and activate

Or clone this repo and build the zip manually:

```bash
git clone https://github.com/harry1079/etch-template-conditions.git
cd etch-template-conditions
mkdir -p dist/etch-template-conditions
cp etch-template-conditions.php dist/etch-template-conditions/
cd dist && zip -r ../etch-template-conditions.zip etch-template-conditions/
rm -rf dist
```

## Quick Start

1. Go to **Templates > Condition Rules** in the WordPress admin
2. Click **+ Add Rule**
3. Select a post type, choose a taxonomy and term to match
4. Save the rule — a template slug is auto-generated
5. Go to **All Templates**, find the new rule row (status will show "Missing")
6. Click **Create in Site Editor** — the slug is copied to your clipboard
7. Create a new custom template in the Site Editor, paste the slug as the name
8. Design it in Etch and save

## Managing Templates

The **All Templates** and **Created in Etch** tabs list every template in the
database, including ones that don't appear in Etch's Template Manager. For each
database template you get:

- **Edit in Etch** — opens the template straight in the Etch editor in a new tab.
- **Site Editor** — opens it in the WordPress Site Editor instead.
- **Delete** — permanently removes the template (by post ID, with a confirmation).
  Hovering the button tints the whole row red so you can see exactly what you're
  deleting. This is handy for cleaning up duplicate templates left behind by a
  builder migration (e.g. Bricks → Etch), where several may share a slug base.

Each template's `wp_template` post ID is shown next to its slug (`#id`). It matches
the `post_id` in Etch's editor URL, so you can tell same-named templates apart.

## Template Slug Convention

Rule templates follow the format `single-{post-type}-rule-{N}`, e.g. `single-uk-venues-rule-1`. The slug is auto-generated and locked after creation because it's coupled to the WordPress template.

## Adding Rules via Code

Rules can also be added programmatically via the `etch_tc_rules` filter:

```php
add_filter( 'etch_tc_rules', function( $rules ) {
    $rules[] = array(
        'post_type' => 'property',
        'taxonomy'  => 'property-type',
        'term'      => 'villa',
        'template'  => 'single-property-villa',
    );
    return $rules;
} );
```

Programmatic rules appear in the Condition Rules tab as read-only entries. Database rules (added via the UI) are always checked first.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

GPL-2.0-or-later
