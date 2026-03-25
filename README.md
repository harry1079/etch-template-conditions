# Etch Template Conditions

Taxonomy-based template routing for WordPress Full Site Editing (FSE) and EtchWP.

WordPress FSE doesn't support serving different single-post templates based on taxonomy terms. This plugin adds that capability. Define rules that map a post type + taxonomy term to a specific template, then create and style that template in Etch as normal.

## How It Works

The plugin hooks into WordPress's `get_block_templates` filter to intercept FSE template resolution. When a visitor lands on a singular post that matches a rule, the plugin swaps in the correct template.

It uses standard WordPress APIs throughout — `WP_Query`, `has_term()`, `wp_options` for storage, standard AJAX with nonce and capability checks. No custom database tables, no monkey-patching, no overriding of Etch internals.

Because it hooks at priority 20 and Etch runs at priority 10, Etch always processes first. The plugin simply tells WordPress "for this particular post, use this template instead" — and that template is a normal FSE template you've built and styled in Etch like any other.

## Features

- Admin dashboard with template overview and rule builder
- Drag-and-drop rule ordering (first matching rule wins)
- Multiple conditions per rule with AND/OR logic
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

## License

GPL-2.0-or-later
