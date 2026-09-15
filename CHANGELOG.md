# Changelog

All notable changes to this plugin are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

⚠ **This file starts at 0.4.0.** The three releases before it were never tagged
and never written down; their history is in `git log`, and reconstructing
version numbers for them after the fact would invent a precision that did not exist.

## [0.5.0] - 2026-09-15

### A reload no longer loses the modal, or the page under it

- **The address for an open modal is now the host page plus the post in the
  hash** — `/vstupenky/ucastnici/#workshop/vecny-zacatecnik/`. Writing the
  target's own path meant a refresh was a genuine request for that page, so the
  browser threw the reader onto it and everything underneath was gone: the list,
  the cart, the schedule scrolled halfway down.
- ⚠ **The other arrangement was built first and reverted.** Keeping the real URL
  and bouncing back to the host on reload does work — `navigation.type` tells a
  reload from a normal visit, and `sessionStorage` can hold the way back — but
  the browser has already fetched the post before any of that runs, so it costs
  a SECOND full load with the detail page visible in between. Worse than the
  problem. Don't rebuild it.
- ⚠ **Two segments, not "contains a slash", is what says a hash is a post.**
  Anchors must be left alone, and `#program` is a heading. A permalink path is
  always at least two non-empty segments; the looser test also matched
  `#section/` and sent a fetch after a post that does not exist. Verified that
  `#program`, `#sekce/` and `#/` all stay anchors.
- Closing clears the hash, so the next reload does not re-open what was closed.
- The history STATE still holds the real permalink: `popstate` loads from it,
  and a fetch wants a permalink rather than an address.
- Nothing is lost for sharing or search — the trigger is still an ordinary link
  to the real permalink, which still renders a real page on its own. It is also
  the shape the old site wrote (`wp_url_to_hash_url()` in astra-ski), so links
  already in e-mails and bookmarks keep working.

### The close button sits outside the frame, and stays there

- **It was inside the frame**, overlapping the content's top-right corner. The
  site this replaces put it in the gutter ABOVE the panel, right-aligned — which
  is also why it never had to fight the content underneath.
- **And it scrolled away.** `position: absolute` pinned it to the FRAME, and the
  frame was what scrolled inside the overlay. Measured on a 3072px-tall detail:
  900px down, the button was at -836px — gone, leaving the overlay click or the
  keyboard as the only way out.
- ⚠ **Sticky cannot fix both**, which is worth recording because it is the
  obvious first try. A sticky element is clamped to its CONTAINING BLOCK, and
  for a child of the frame that is the frame — so `top: 0` pins it back inside,
  however far a negative margin tries to lift it. Measured: 60px inside,
  exactly the margin meant to take it out.
- So the scroll moved instead. The frame is capped to the viewport and the BODY
  scrolls inside it, which is what the old popup did too (`max-height: 80vh;
  overflow-y: auto` on its columns).

## [0.4.0] - 2026-09-15

### The shell opens before the fetch, so waiting looks like waiting

- `open()` used to be called only inside the success handler, so for the whole
  round trip the modal was still `display:none` — and `aria-busy` was being set
  on an element nobody could see. **No stylesheet could express a loading
  state, because there was nothing on screen to style.** The reader clicked and
  got silence until the content appeared all at once.
- The shell now opens first, empties itself (stale content under a spinner
  reads as the new post having loaded wrong), and fills when the response
  lands. On failure it closes before falling back to navigation, rather than
  leaving an empty modal over the page.
- `modal.css` paints the busy state: a ring keyed on `aria-busy`, sized and
  coloured through custom properties like everything else here. Reduced motion
  gets a pulse instead of a spin — still an indicator, not an empty frame.

### Content that lands can now be seen by scripts already on the page

- **New `wpauc:loaded` event**, dispatched on `document` once injected content
  is in the DOM, carrying `{ target, url }`.
- ⚠ **This is not "run the content's scripts".** `applyAssets()` still refuses
  to execute anything the response carries; the security stance is unchanged.
  What the event does is tell code ALREADY RUNNING on the page that the DOM
  grew underneath it — the same thing an infinite scroll or a filtered loop has
  to say. Nothing new is executed; something already trusted is asked to look
  again.
- Without it, anything JS-driven inside a modal was simply dead: bound on
  `DOMContentLoaded`, over a DOM that did not yet contain the content.
- The event is generic on purpose. Translating it into whatever a theme calls
  its own re-init signal is an adapter's job.

### Kadence adapter: a bridge to the theme's own re-init

- The Kadence integration now maps `wpauc:loaded` onto `kadenceJSInitReload`.
  Six of Kadence's front-end scripts already listen for it so they can rescan
  after an infinite scroll — the theme's lightbox, and Blocks' splide,
  show-more, tippy and search — so all of them start working inside a modal.
- ⚠ The "the frontend is not here" warning in that file still stands and is not
  contradicted: what was wrong before was gating the GENERIC feature on
  `wpauc_is_kadence()`. This is the opposite — a bridge that is meaningless
  anywhere else, because `kadenceJSInitReload` is Kadence's own event name.
