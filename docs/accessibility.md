# Accessibility (M5-03)

Target: WCAG 2.2 AA for the admin app and the frontend layouts. **No manual review with a screen reader or on a touch device has been done yet**; the checklist below is for a human to run. Automated checks cannot replace it.

## Automated (E2E, `bin/test-e2e.sh`)

| What | Where |
| --- | --- |
| axe (wcag2a/aa, 2.1, 2.2 AA, best-practice) over every admin route (overview, galleries, editor, media, settings, status), the table views and the open dialogs (media details, add-to-gallery, destructive confirm), light and dark. Any finding fails; there are **no waivers**. Contrast inside Quasar portals is checked separately (axe cannot see the dialog card). | `e2e/tests/a11y.spec.ts` |
| axe on the three frontend layouts (grid, masonry, justified), light/dark | `e2e/tests/frontend-layouts.spec.ts` |
| Lightbox keyboard/touch behaviour, RTL, reduced motion on the frontend | `frontend-layouts.spec.ts`, `frontend.spec.ts` |
| Session expired state keeps the editor mounted; RTL root direction (emulated, see below); tile-size control by keyboard | `session.spec.ts`, `rtl.spec.ts`, `tile-size.spec.ts` |
| Unique ids, one `h1` (PHP page), landmark structure | `a11y.spec.ts`, `locale.spec.ts` |

## What the app guarantees

- **Keyboard:** every action has a keyboard path. Items grid: arrows/Home/End/Page keys move the active tile (`aria-activedescendant`), Space selects, Ctrl/Cmd+A selects all, Enter opens details, Delete removes, Ctrl/Cmd+arrows (and the toolbar buttons "earlier/later/first/last") reorder; drag and drop is only an extra for mouse users. Tile-size control: three buttons with `aria-pressed`.
- **Focus:** skip link to the main region; dialogs trap and restore focus; destructive confirms focus the safe button; visible focus ring (`--swg-focus`, 3 px) in both themes.
- **Landmarks:** WordPress' `#wpbody` is the `main`; the app uses labelled regions and a `nav`; no nested banner/complementary (editor header and side panel are `div` / `region`).
- **Live regions:** one polite `role="status"` for navigation, theme, save and tile-size announcements; errors use `role="alert"`; the session-expired banner is an alert.
- **Motion:** `prefers-reduced-motion` removes animations and transitions in the app.
- **Targets and contrast:** controls are at least 32 px (most 40 px+); colour tokens are checked by axe in both themes; status is never colour alone (icon + text).
- **RTL:** `dir` follows WordPress' `is_rtl()` (`window.swgAdmin.isRtl`), CSS uses logical properties, directional icons flip (`swg-flip-rtl`), Quasar's RTL flag is set without touching `<html dir>`.

## Known gaps

- FU-24: keyboard reorder has not been tried with a real screen reader. Touch drag and drop is untested (buttons/keys are the alternative).
- RTL is verified in E2E only by emulation (the stack has no RTL language pack): the page is served with `isRtl: true` and `<html dir="rtl">`. The wp-admin chrome is not mirrored there. Verify once on a real Arabic/Hebrew site.
- No automated check of announcements' wording or order; no high-contrast / forced-colors review; no zoom/reflow test at 400 %.

## Manual review checklist (human, with NVDA + Firefox/Chrome on Windows and VoiceOver + Safari on macOS/iOS)

Record date, tools and findings; fix or file each deviation.

1. **Navigation:** open Galleries. Tab through the rail. Expected: each link announced with its name and "current page" for the active one; skip link is the first stop and moves focus to the content region. After activating a link the new section name is announced.
2. **List:** the status filter and the view switch are announced as groups with the selected option; each gallery card/row has a unique accessible name; the actions menu button names the gallery ("Actions for gallery X").
3. **Editor, items grid:** Tab into the grid: name, position ("3 of 40") and selection state are announced. Arrow keys move; Space toggles selection (announced); Ctrl+Arrow moves the item and the new position is announced; Delete asks for confirmation (focus on the safe button); Escape returns focus to the grid.
4. **Editor, forms:** every field has a label, errors are linked and announced; Save reports "All changes saved"; leaving with unsaved changes announces the dialog and keeps focus inside.
5. **Dialogs and menus:** focus moves in, Tab stays inside, Escape closes, focus returns to the opener.
6. **Session expiry:** log out in another tab, press Save. Expected: the alert is announced, the login link opens a new tab, nothing typed is lost.
7. **Preview dialog:** opens as a dialog with a label; the iframe has a title; editor notes are readable text.
8. **Reduced motion:** enable "reduce motion" in the OS; no sliding/fading remains.
9. **Zoom/reflow:** 200 % and 400 % browser zoom (320 CSS px): no horizontal page scroll, nothing cut off, controls reachable.
10. **Touch (iOS Safari, Android Chrome):** all buttons are comfortably tappable; the items grid can be scrolled; select, move (toolbar buttons) and remove work without drag; the lightbox on the frontend swipes and closes with its button.
11. **Frontend galleries:** images have alt text read aloud; the lightbox traps focus, closes with Escape and returns focus; the "page N of M" pagination is a labelled navigation.
12. **RTL:** on a site in Arabic/Hebrew the rail is on the right, arrows/chevrons point the right way, text aligns to the right, and dialogs/menus mirror.
