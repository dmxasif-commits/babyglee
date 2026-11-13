# Working rules for this repository (set by the owner)

1. **Always record changes in `README.md`.** Every change adds an entry at the top of the "Changelog" section: date, what changed, files or areas touched.
2. **Always make a backup before changing things.** Before a round of changes, save a zip of the current live site (`main`) to
   `backups/site-<YYYY-MM-DD>-<short-reason>.zip` (`git archive --format=zip -o backups/... origin/main`) and mention it in the changelog.
3. Pages are generated: edit `tools/build.py` and run `python3 tools/build.py`. Do not hand-edit the generated `.html` files.
4. The store and the ad landing pages (`lp/`) stay separate: no links between them.
5. Mobile first: most visitors are on phones in Bangladesh. Check every change at 360px and 390px width.
6. All site copy is in English; prices use the taka sign (৳). Do not mention where the shoes are manufactured.
7. Never commit `api/config.php`, database files, or anything with passwords or tokens.
