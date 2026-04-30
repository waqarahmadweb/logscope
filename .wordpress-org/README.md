# `.wordpress-org/` — wp.org plugin-directory assets

This directory holds the binary assets the WordPress.org plugin directory shows on the plugin page (banner, icon, screenshots). It is **export-ignored** from the distribution zip via `.gitattributes`; assets land in the wp.org SVN `assets/` directory at submission time, not inside the plugin zip itself.

This file is a **spec only** — the binaries are not yet generated. The maintainer produces them by hand before the v1.0.0 wp.org cut. CI does not build them.

---

## Asset checklist

| File                  | Dimensions     | Format            | Purpose                                                                          |
| --------------------- | -------------- | ----------------- | -------------------------------------------------------------------------------- |
| `banner-1544x500.png` | 1544 × 500 px  | PNG (sRGB, 8-bit) | Header banner on `wordpress.org/plugins/logscope/`                               |
| `icon-256x256.png`    | 256 × 256 px   | PNG (sRGB, 8-bit) | Plugin tile in search results, "Add New" grid, etc.                              |
| `screenshot-1.png`    | ≥ 1280 px wide | PNG               | Caption: "Log viewer with severity filters and regex search."                    |
| `screenshot-2.png`    | ≥ 1280 px wide | PNG               | Caption: "Grouped view with bulk actions (mute / export CSV)."                   |
| `screenshot-3.png`    | ≥ 1280 px wide | PNG               | Caption: "Stats dashboard — severity breakdown, sparkline grid, top signatures." |
| `screenshot-4.png`    | ≥ 1280 px wide | PNG               | Caption: "Alerts settings — email and webhook configuration with test send."     |
| `screenshot-5.png`    | ≥ 1280 px wide | PNG               | Caption: "Mute panel for unmuting silenced signatures."                          |
| `screenshot-6.png`    | ≥ 1280 px wide | PNG               | Caption: "Onboarding banner shown when `WP_DEBUG_LOG` is missing."               |

Captions above are sourced verbatim from the `== Screenshots ==` section of [`readme.txt`](../readme.txt). The wp.org renderer matches `screenshot-N.png` to the Nth caption in `readme.txt` by index, so the filenames must stay in lockstep with the order there.

Optional retina variants (`banner-3088x1000.png`, `icon-512x512.png`) can be added later — wp.org will serve them when present without any other change.

---

## Banner — `banner-1544x500.png`

-   **Dimensions:** 1544 × 500 px exactly. wp.org rejects off-spec banners.
-   **Format:** PNG, sRGB, 8-bit. JPEG is also accepted but PNG is preferred for the flat-color aesthetic this banner uses.
-   **Safe area:** Keep the wordmark and any tagline within the centred 1200 × 400 region. The outer 172 px on the left / right and the outer 50 px on the top / bottom may be cropped on narrow viewports.
-   **Content:** Wordmark "Logscope" + a one-line tagline ("View, filter, group, and alert on the WordPress debug log."). No screenshots in the banner — the screenshot grid below the header is the right surface for that.
-   **Style:** Match the plugin's dark-mode palette (background `#1d2327`, accent matching the focus ring), since the plugin's identity is "tool that lives inside wp-admin." Avoid stock-photo backgrounds.

## Icon — `icon-256x256.png`

-   **Dimensions:** 256 × 256 px exactly.
-   **Format:** PNG with transparency, sRGB, 8-bit.
-   **Content:** A single recognisable mark — magnifying glass over a log line, or a stylised "L" — that reads at 16 px (favicon size) and at 256 px without losing legibility. No literal text inside the icon; the wordmark lives in the banner.
-   **Padding:** Leave at least 16 px transparent padding on each side so the mark does not crowd the rounded mask wp.org applies.

## Screenshots

-   **Source:** Capture from a real WordPress install at the configured admin scheme. Show realistic data — a fresh install with three notices is not interesting; pre-seed the log with a handful of entries spanning severities.
-   **Crop:** Crop to the Logscope page only (the wp-admin sidebar is fine to include on screenshot 1 for orientation; later screenshots can crop tighter).
-   **Width:** ≥ 1280 px wide so the wp.org listing can show a 2× resolution version on retina displays without upscaling.
-   **Filename order:** `screenshot-1.png` through `screenshot-6.png`. wp.org's renderer pairs each one with the matching numbered caption in `readme.txt`'s `== Screenshots ==` block. Adding a screenshot means adding both the file and a caption line; reordering means renaming files **and** caption lines together or the captions de-sync.
-   **Annotations:** None. The captions in `readme.txt` carry the description; arrows / red boxes in the image make the listing feel like a tutorial rather than a product showcase.

---

## Submission flow (for the v1.0.0 cut)

1. Generate all eight binaries above into this directory.
2. The release workflow (`.github/workflows/release.yml`) does **not** include this directory in the distribution zip — `/.wordpress-org` is in `.gitattributes` `export-ignore`. That is intentional: wp.org pulls these from the SVN `assets/` directory, not from the plugin zip.
3. After the wp.org reviewer approves the initial submission, copy the contents of this directory into the SVN `assets/` directory of the plugin's wp.org repo (`https://plugins.svn.wordpress.org/logscope/assets/`).

Updates to assets after the initial submission go through SVN only; nothing in this repo's release workflow touches wp.org SVN.
