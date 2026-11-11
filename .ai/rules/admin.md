---
paths:
  - 'resources/js/components/admin/**'
---

# Admin

## Don't use shadcn componentToString for Unovis tooltips
Unovis 1.7 binds each stacked-bar element to a wrapper `{ datum, index, stacked, stackIndex }`, so the shadcn chart helper componentToString (which reads keys off the top level) renders an empty tooltip. It also calls useId() on the client only, which shifts every later chart's data-chart id after SSR hydration and leaves bars without --color-value. Write a plain template function that unwraps `record.datum` and escapes text instead (see ColumnChart.vue).
