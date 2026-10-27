---
paths:
  - 'resources/js/**'
---

# Js

## Use semantic theme tokens, never raw palette colors
Style UI with the semantic tokens defined in resources/css/app.css (bg-background, text-foreground, bg-muted, text-muted-foreground, bg-accent, border-border, bg-sidebar, text-sidebar-foreground, ...). Do not use raw Tailwind palette colors (neutral-*, gray-*, zinc-*, text-black, text-white) or hand-written `dark:` color pairs — the tokens already flip with the .dark class, so a raw pair is a dark-mode bug waiting to happen.

`bg-sidebar` resolves to --color-sidebar -> --sidebar-background. There is no bare --sidebar variable; do not reintroduce one.

Exceptions: resources/js/pages/Welcome.vue and the AuthSplitLayout dark panel deliberately use a fixed palette.

## No raw palette colours anywhere in resources/js
The old exceptions are gone: Welcome.vue and AuthSplitLayout were rebuilt on semantic tokens during the 2026-08 redesign, so there is no longer any file allowed to use a fixed palette. Every app-level .vue is raw-colour free — keep it that way.

Use the semantic tokens from resources/css/app.css only (bg-background, text-foreground, bg-card, bg-muted, text-muted-foreground, bg-accent, text-accent-foreground, border-border, bg-primary, text-destructive, bg-sidebar...). No neutral-*/gray-*/blue-*/red-* etc., no text-black/text-white, no `[#hex]`, and no hand-written `dark:` colour pairs — tokens already flip with .dark, so a raw pair is a dark-mode bug.

This includes the less obvious utilities: decoration-*, divide-*, outline-*, ring-*, fill-*. The only survivors are the scrims inside components/ui/ (bg-black/50), which are vendored shadcn.

Added token sets beyond stock shadcn: success / success-foreground / success-muted / success-muted-foreground for availability and confirmation states, and the shadow-flat / shadow-raised / shadow-overlay elevation tiers. Use those three shadows rather than inventing shadow values.

The one legitimate raw colour is a user's own event-type colour, which arrives as data and is bound with :style="{ backgroundColor: ... }".

## bg-brand is the exception to "primary carries every action"
The no-raw-colours rule still holds. One token pair was added: brand / brand-foreground, fixed vibrant blue in both themes (see .ai/rules/css.md). Only AuthSplitLayout's marketing panel uses it, because bg-primary now inverts to a light blue under .dark and an "always brand" panel must not flip.

Two consequences of primary becoming a saturated blue rather than a dark navy:
- The full-colour logo mark (a brand-blue tile) does not read on a primary/brand surface. AppLogoIcon on a coloured panel needs a neutral chip behind it (bg-card), which is what AuthSplitLayout does. Everywhere else it already sits on bg-card or the page background.
- resources/js/app.ts sets the Inertia progress colour to 'var(--primary)' rather than a hex, so the loading bar follows the theme. Inertia injects it into a normal document <style>, so the var resolves off :root — keep it a var, do not go back to a literal.
