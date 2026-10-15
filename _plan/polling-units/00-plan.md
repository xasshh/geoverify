# Polling unit checks

Started 2026-10-15. Before an election, officers visit each polling unit on a
list, say whether it is where the list puts it (and where it really is), and
record whether voters can use it. Sold to observer groups, civil society and
development partners as a commissioned campaign; never to a party.

## Built (M1)

- `polling_units` (the list, as given; boundary ids from points in PostGIS,
  never from the listed names) and `polling_unit_checks` (one per visit,
  written once, one open per unit). Capture mode `polling_units` on campaigns.
- `ImportPollingUnits`: a CSV with loose headings (code and name required;
  state, LGA, ward or registration area, latitude, longitude, landmark,
  voters). Updates what the list says, never what an officer found.
- `ManagePollingUnitChecks`: assign (a ward at a time), arrive, photograph
  (`pu_site` required, `pu_entrance`, `pu_other`), report (ten questions in
  `PollingUnitCheck::CHECKLIST`; not found needs a note), review (accept
  confirms the unit at the officer's position; return opens the next check).
- Console `/console/polling-units`; field `/field/polling-units/{check}`
  through the job outbox (the sync contract is untouched); client
  `/client/campaigns/{id}/polling-units` with a map, findings by LGA and
  CSV/GeoJSON exports. No officer is named on any client surface.

## Lines held

- Neutral: no work for a party or candidate.
- No voter personal data; findings are about places.
- People kept out of photographs; the client sees the site and the way in.

## Next, when wanted

- A printed report (the sixth document path, `LoopbackPrint`).
- Re-checks close to election day (a new check on a settled unit).
- Election day observation, only for accredited observers, and nothing
  published before INEC announces results.
