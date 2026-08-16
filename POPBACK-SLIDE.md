# POP Back '26 billboard slide

An animated, self-contained 1920x1080 slide promoting POP Back '26 for
`billboard.ncsu.edu`. One file, no PHP proxy, no network calls: fonts, photos
and the QR code are all embedded.

## Adding it to the rotation

Drop `popback.html` anywhere web-served (for example
`billboard.engr.it/news-slides/popback.html`) and add that URL through
billboard.ncsu.edu's **Add URL** dialog.

- Suggested dwell: **25 seconds**. That gives four or five attraction lines and
  one photo crossfade.
- Set the slide to reload on each show so the countdown is always current.

## What animates

| Element | Behavior |
| --- | --- |
| Photo stage | Two event photos, slow Ken Burns pan and zoom, crossfading every 12 seconds |
| Attraction line | One giant condensed line cycling six attractions (free food, live music, mechanical bull, dunk tank, rock wall, games and giveaways) every 5 seconds |
| Countdown | Live days, hours, minutes and seconds to 4 p.m. Aug. 27, each digit ticking as it changes |

Everything respects `prefers-reduced-motion`.

## Three automatic states

The slide reads the clock and switches itself. No one has to swap files.

1. **Countdown**, before 4 p.m. Aug. 27: live countdown plus date, time and place.
2. **Happening now**, 4 to 8 p.m. Aug. 27: pulsing "Happening now" flag and the
   time remaining, counting down to 8 p.m.
3. **After**, once the event ends: "See you next year" with the QR pointed at
   photos and highlights. Safe to leave in rotation for a few days.

The event window is written as fixed Eastern offsets
(`2026-08-27T16:00:00-04:00` to `20:00:00-04:00`), so the countdown is right
even if the kiosk clock is not set to Eastern time.

## Proofing parameters

| Parameter | Effect |
| --- | --- |
| `?preview=countdown` / `live` / `after` | Force a state |
| `?now=2026-08-27T17:30:00-04:00` | Simulate a clock time |
| `?rotate=3` | Seconds per attraction, default 5 |

Example: `popback.html?preview=live&now=2026-08-27T17:35:00-04:00`

## Brand notes

- Wolfpack Red `#CC0000` carries the full right panel; the countdown blocks use
  Reynolds Red `#990000`.
- Hosts line includes the College of Engineering, College of Education, College
  of Humanities and Social Sciences and Wilson College of Textiles.
- Roboto and Roboto Condensed, self-hosted as embedded WOFF2. No Google Fonts
  call from the kiosk.
- Every text and background pairing clears WCAG AA: white on Wolfpack Red
  5.89:1, Reynolds Red on white 8.92:1, gray on white 8.45:1.
- AP and NC State style throughout: "Thursday, Aug. 27," "4-8 p.m.," "NC State."
- The word "party" is not used anywhere.

## Assets used

- Photos cropped from the approved 2026 POP Socials billboard PNGs in the
  Marketing and Social POP 2026 Drive folder. Photos by Adam Jennings.
- QR code from `POP Back 2026.svg` in the same folder.
- Copy from *POP Back 2026 Social Media and Language*.

## One thing to verify

The Drive QR encodes `go.ncsu.edu/popback26%3Aqr`, meaning the GoLink source
suffix `:qr` is percent-encoded. Most servers decode `%3A` back to a colon, but
it is worth scanning the printed slide once on a phone before it goes live. If
you would rather track billboard scans separately, say the word and I will
regenerate the QR against `go.ncsu.edu/popback26:billboard`.
