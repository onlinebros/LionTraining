# PlasmaGuard PRO sales video — production kit

Everything needed to turn the sales deck into a finished, professional video
without a film crew. The deck is the visual track, `voiceover.txt` is the audio
track, `scenes.csv` is the edit list, and `assets.md` says what footage exists
and what has to be asked for.

**Runtime 5:40 plus an 8-second end card.** A 60-second cutdown is scenes 1 and 4.

---

## 1. What I can and cannot do

I cannot render video. No model in this workspace outputs an MP4, and anything
that claimed to would be guessing at what the product looks like. What follows
is the pipeline that gets you a finished file, and every input it needs is
already written.

---

## 2. Pick a route

Three honest options. They differ mainly in whether a human face appears.

| | Route A — voiceover over slides | Route B — AI presenter | Route C — record it yourself |
|---|---|---|---|
| **Tools** | ElevenLabs + Descript or CapCut | HeyGen or Synthesia | The Recording Studio already in the back office |
| **Rough cost** | ~$5–35/mo | ~$24–90/mo | $0 |
| **Time to first cut** | An afternoon | An hour | An afternoon |
| **Who is on screen** | Nobody. Slides and product footage. | A synthetic presenter, or an avatar of the owner | The actual owner or partner |
| **Best for** | The company version, shared by everybody | Scaling to one video per partner, and other languages | The version that converts best |

**Recommended: Route A for the company video, Route C for partners.**

Route A because this is a technical, claim-heavy product sale, and the hero shot
is a sensor LCD with a real number on it — not a presenter's face. A clean
voiceover over well-built slides reads as more credible here than a synthetic
human, and it sidesteps the uncanny-valley problem entirely on a video whose
whole argument is *we are the ones who prove things*.

Route C because scene 9 and scene 12 are written to be spoken by the actual
partner whose page the buyer lands on, and a real person on a $6,000 commercial
sale outperforms a polished stranger. The studio, chunked uploads, trimming and
private playback all shipped already — see `backend/docs/screen-recordings.md`.

Route B is the right answer only if you want **one video per partner at scale**,
or Spanish and other languages without re-recording. HeyGen clones a voice and a
face from a short sample and re-lip-syncs; Synthesia is stronger at slide and
screen-recording overlay and at enterprise governance.

---

## 3. Route A, step by step

**1. Export the slides.** Open the sales deck, download as PDF (crisper) or
PPTX. That is the visual track — 13 stills, one per scene.

**2. Generate the voiceover.** Paste `voiceover.txt` into ElevenLabs, scene by
scene rather than all at once, so a bad read costs you one scene. Notes:

- The `---` scene headers and `[SILENT]` are directions. Do not paste them.
- Numbers are already spelled out the way they should be spoken ("ninety nine
  point nine nine percent", "zero point three microns"). Do not "fix" them back
  to digits — TTS mangles `99.99%` and `0.3 µm`.
- Pick one voice and keep it. Mid-pace, low warmth, no radio-announcer lift.
- Check the $5 Starter tier covers commercial use before publishing; the free
  tier does not.

**3. Assemble.** Drop the audio and the slide stills into Descript or CapCut.
`scenes.csv` gives you the in and out point of every scene. Cut the slide change
on the sentence, never mid-sentence.

**4. Drop in the b-roll** listed in `assets.md`, over the voiceover, at the
points `scenes.csv` marks. Scene 4 is the one that matters.

**5. Captions.** Burn them in. Most of this gets watched muted the first time.

**6. End card** holds 8 seconds, silent. Then export 1920×1080, H.264, and
upload it to the Recording Studio as an always-open showing.

---

## 4. Hard rules for whoever edits this

These are not style preferences. Breaking them creates liability.

1. **Never generate the product with AI video.** Sora, Veo, Runway, Pika and
   everything like them will invent a plausible-looking device that is not the
   PlasmaGuard PRO. Showing a hallucinated product in a sales video is a
   misrepresentation of goods. Generic b-roll of an office lobby is fine.
   Anything that is meant to be *this product* must be real footage.
2. **The asterisk footnote stays legible whenever a claim is on screen**, in
   every cut, every aspect ratio, every thumbnail. If a 9:16 crop loses it, the
   claim comes out of that crop too.
3. **No claim may be tightened in the edit.** No caption saying "99.99%
   effective", no title card saying "kills viruses", no thumbnail text that goes
   further than the slide. "up to 99.99% of *tested* viruses and bacteria" is the
   whole phrase.
4. **No health claims.** Not about illness, absence, productivity or anyone
   feeling better. Not in the video, the title, the description or the thumbnail.
5. **"Tested by the EPA" is the title of PlasmaGuard's own video.** It does not
   mean EPA approved, certified or endorsed, and nobody may say those words.
6. **The commission disclosure is spoken in scene 11**, not captioned, not moved
   after the CTA.
7. **No income claims anywhere.** This is the buyer video; it does not discuss
   what a partner earns at all.

---

## 5. Before it is published

| Blocker | Whose call |
|---|---|
| Written permission for PlasmaGuard's copy, product names, photography and footage | PlasmaGuard |
| Their refund / return / warranty policy in writing — they publish none | PlasmaGuard |
| Is it PlasmaGuard **Corporation** or **LLC**? Their site says Corporation; our config and q3.life say LLC | PlasmaGuard, then us |
| Energy Saver: included or separate, and at what price | PlasmaGuard |
| `PRESENTATIONS_OPEN_TO_MEMBERS=true` so partners can run the funnel | Owner |

None of them blocks **recording**. The first three block **publishing**.

---

## 6. Files here

| File | What it is | Where it goes |
|---|---|---|
| `voiceover.txt` | The full script, scene by scene, spoken-number formatting | ElevenLabs, or read aloud |
| `scenes.csv` | Edit list: timings, headlines, b-roll notes, first line of each scene | Descript / CapCut / HeyGen scene import |
| `assets.md` | What footage and imagery exists, what to request, and the claim rules attached to each | Whoever edits |

The slides live in the deck, not in this folder — export them from there so a
change to the deck cannot silently diverge from the video.
