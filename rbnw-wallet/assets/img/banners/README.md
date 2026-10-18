# Dashboard banners

The dashboard slider shows the banners listed in `$banners` in
`dashboard.php`. Each banner needs two sizes, both WebP:

- `<slug>-800.webp`  (800 px wide, for phones)
- `<slug>-1600.webp` (1600 px wide, for sharp high-DPI screens)

Keep the 8:3 shape of the current banners (e.g. 2000 x 750 source) so
nothing gets cropped. To add one: export the two sizes, drop them here,
and add a `[slug, alt text, link]` row to `$banners`.
