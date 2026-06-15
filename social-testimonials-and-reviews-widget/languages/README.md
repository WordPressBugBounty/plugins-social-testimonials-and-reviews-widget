# Translations

This plugin's text domain is `social-testimonials-and-reviews-widget`. All user-visible PHP, template, and JS strings flow through it, so a single `.po`/`.mo` pair per locale translates the entire plugin.

## Languages shipped in the box

| Locale  | Language               |
|---------|------------------------|
| `en_US` | English (source)       |
| `fr_FR` | French                 |
| `es_ES` | Spanish                |
| `pt_PT` | Portuguese             |
| `de_DE` | German                 |
| `it_IT` | Italian                |

WordPress auto-loads the matching `.mo` file based on **Settings → General → Site Language**. Users on locales we don't ship (e.g. `nl_NL`, `ja`) silently fall back to English, with no broken rendering.

The shipped locales match Repuso's web dashboard so users get a consistent experience across the plugin and the hosted app.

## How strings flow

- **PHP / templates**: every user-visible string is wrapped in `__()`, `_e()`, `esc_html_e()`, `esc_attr_e()`, `printf( __( ... ) )`, etc. with the text domain.
- **JavaScript**: strings are sourced from `window.rwI18n`, populated server-side via the `js_strings()` method in the main plugin file. Each value is run through `__()` so it shows up in PoT extraction.
- **JS call site**: `rwT('key', 'English fallback')` looks up the localized value with a graceful fallback if the key is missing.

## Regenerating the template

With WP-CLI:

```sh
wp i18n make-pot . languages/social-testimonials-and-reviews-widget.pot \
  --slug=social-testimonials-and-reviews-widget
```

Or the bundled Python build script (see history) that uses a fixed translation dictionary - the path of least friction when shipping a curated translation set with the plugin.

## Adding a new language

1. Copy the `.pot` to a locale-specific `.po`:
   ```sh
   cp languages/social-testimonials-and-reviews-widget.pot \
      languages/social-testimonials-and-reviews-widget-nl_NL.po
   ```
2. Open the `.po` in Poedit and translate.
3. Save - Poedit produces the matching `.mo`.
4. Commit both files.

## Adding a new JS string

1. Add an entry to `js_strings()` in `social-testimonials-and-reviews-widget.php`.
2. Call it from JS via `rwT('your_key', 'English fallback')`.
3. Regenerate the `.pot` and update each `.po`.
