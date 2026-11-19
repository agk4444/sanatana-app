# Sanatana Dharma — Ask the Book

Front end for the 1916 *Sanatana Dharma* text-book RAG, live at
https://sanatana.socialnews.xyz.

## What it does

Visitor asks a question → `ask.php` embeds it with the box gemma service
(`127.0.0.1:8797`, embeddinggemma-2) → retrieves the 5 closest passages
from `l9huvwhjjmwprag_embeddings_sanatana` (448 chunks, `ORDER BY
VEC_DISTANCE_COSINE`) → OpenRouter `openrouter/free` answers **only** from
those passages, citing PDF page numbers. Page references link to the exact
page in the DocumentCloud viewer.

## Files

- `index.html` — the page (no build step, no framework)
- `assets/style.css`, `assets/app.js` — look and behaviour
- `ask.php` — the JSON API (`POST {"question": "..."}`) — **not in this
  repo yet, see below**
- `clear-cache.php` — CLI tool to delete cached answers by question
- `config.example.php` — optional key file (copy to `config.php`)
- `data/.htaccess` — blocks web access to the rate-limit store
- `sanatana_cache_table.sql` — answer-cache table definition (kept for
  reference only — the cache was never installed on this site, see below)
- `favicon.ico`, `favicon-32x32.png`, `favicon-512.png`,
  `apple-touch-icon.png` — cream Om on maroon

## Deploy (Plesk box)

1. Clone this repo on the box, e.g. `/root/sanatana-app`.
2. Copy the files into the subdomain docroot
   `/var/www/vhosts/socialnews.xyz/sanatana.socialnews.xyz/` (no build
   step, no database changes).
3. `ModPagespeed off` is already set in Additional Apache directives for
   this subdomain (Nov 18 white-page fix — the page came back HTTP 200
   with an empty body until this was set).
4. OpenRouter key — first hit wins: environment variable
   `OPENROUTER_API_KEY`, a `config.php` in the docroot, or the WordPress
   option `wprag_openrouter_key`.
5. Test: ask the caste question — the answer should cite PDF pp. 122–128.

## ask.php is not in this repo (yet)

The live `ask.php` on the box is newer than the workspace copy (the
answer model was changed to `openrouter/free` on the box, and the box
copy has no answer-cache code). It is added from the box with:

    cp /var/www/vhosts/socialnews.xyz/sanatana.socialnews.xyz/ask.php /root/sanatana-app/ask.php
    cd /root/sanatana-app && git add ask.php && git commit -m "Add live ask.php from the box" && git push

## Notes

- Rate limit: 20 questions per IP per hour, file-based in `data/`,
  fails open if the directory is not writable.
- The answer cache was deliberately NOT installed on this site (the
  owner chose not to risk serving saved wrong answers). The cache table
  was therefore never created on the box; `sanatana_cache_table.sql`
  and `clear-cache.php` are kept for reference only.
- DB credentials come from `wp-config.php` at its absolute path in
  `ask.php`. Embeddings are free (local service).
