# WP AJAX URL Content

Serves the rendered content of any URL on this site **without page chrome**,
plus the assets that content needs.

Built for modals: click a workshop, a speaker, a venue or an FAQ and its detail
view opens in place instead of navigating. But the question it answers —
*give me the content at this URL, minus the header and footer* — has nothing to
do with Elementor, Kadence, Astra or any modal library, which is why it is its
own plugin.

## How it gets the content

Not over HTTP. It re-runs WordPress against the target URL **in-process**:
rewrite `REQUEST_URI`, `$wp->main()`, render into a buffer. Template fidelity is
the point — whatever the site would show at that URL is what comes back.

Chrome is **reconstructed, not stripped**. A theme adapter says how to render
the content region, so the header and footer never render at all. For Kadence
that is the two lines between `get_header()` and `get_footer()`. Stripping
landmarks out of finished HTML is the fallback for unknown themes, not the
design.

## Three adapter surfaces

They are independent — a site can run Astra with Kadence Blocks, or Kadence with
Elementor.

| surface | question | filter |
|---|---|---|
| theme | how to render the content region | `wpauc/render` |
| assets | what the content needs | `wpauc/assets` |
| frontend | which modal to drive | enqueue your own loader |

## Security

The endpoint is public, because the content is — and it serves nothing else.
Three gates in `WPAUC_Endpoint::resolve()`:

1. same origin;
2. the URL resolves to a real post (`url_to_postid()`);
3. that post is **publicly viewable** — status, post-type visibility and
   password protection in one call.

Narrow it further with `wpauc/allow_post`, which most sites will want: a modal
is for detail views, not for every page.

## Assets

The collector diffs the enqueue registry around the render: whatever appeared is
what the content asked for. No hardcoded handles, no builder knowledge.

⚠ **The baseline is load-bearing.** A REST request never fires
`wp_enqueue_scripts`, so without establishing it first the diff returns
*everything* — measured at 45 stylesheets and 61 scripts for one FAQ answer,
almost all of it the site's global CSS that the hosting page already has.

## API

```
GET /wp-json/wpauc/v1/content?url=<same-origin URL>

{ "id": 6429, "title": "…", "url": "…", "html": "…",
  "assets": { "styles": [...], "scripts": [...] } }
```

## Status

**0.1.0 — working end to end on Kadence.** Core, Kadence theme + frontend
adapter, fallback modal shell, vanilla-JS loader with deep links and
back-button support. Astra and Elementor adapters are not written yet; the
Astra one can be ported from the ancestor implementation in Pragostav, which
already reconstructs Astra's content region in 82 lines.
