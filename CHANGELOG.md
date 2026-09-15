# Changelog

All notable changes to this plugin are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

⚠ **This file starts at 0.4.0.** The three releases before it were never tagged
and never written down; their history is in `git log`, and reconstructing
version numbers for them after the fact would invent a precision that did not exist.

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
