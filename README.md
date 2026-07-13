# TimeTracker (MediaWiki extension)

A Start/Stop time tracker for MediaWiki: log work against customers, jobs,
and tasks; correct a day's time by hand; and review a filterable timesheet
with a weekly grid and CSV export. Entries are stored as Semantic MediaWiki
properties on per-user pages. This is the MediaWiki half of the TimeTracker
project; the deployable Canasta application lives in the
[TimeTrackerApp repository](https://github.com/amethyst-ck/TimeTrackerApp).

## Requirements

- MediaWiki >= 1.43
- Semantic MediaWiki, PageForms, DisplayTitle, ParserFunctions,
  UrlGetParameters, TitleIcon
- The custom `Customer`/`Job`/`Task` namespaces and the wiki content
  (forms, entity templates, property pages) the application supplies

## What it provides

- **Special:TimeTracker** — a Start/Stop timer that splits its span across
  midnight into per-day entries.
- **Special:EditTime** — set or correct a day's total by hand (zero removes
  the entry); a `timetracker-admin` may edit on behalf of another user.
- **Special:TimeReports** — a filterable timesheet (list + weekly grid, CSV
  export) scoped by customer / job / task / user and period.
- Parser functions for the dashboard, per-entity time tables, and a job
  progress bar against its estimate.
- Delete protection: a customer/job/task that still has child entities or
  logged time can't be deleted — archive it instead.
- Per-user time pages editable only by their owner or a `timetracker-admin`.

## Install

This extension is the MediaWiki half of the **TimeTracker application**. It
expects scaffolding around it: Semantic MediaWiki + PageForms (and the other
extensions above), the customer/job/task namespaces, and wiki content —
forms, entity templates that annotate the SMW properties, and property pages.

The application ships a complete set of that scaffolding plus operator
settings, and the turnkey path is deploying the whole thing onto a fresh
Canasta instance with [Wicker](https://github.com/amethyst-ck/Wicker); see
the [TimeTrackerApp README](https://github.com/amethyst-ck/TimeTrackerApp).
The extension is equally a foundation to build on: its special pages, store
services, and parser functions work against whatever forms, templates, and
namespaces you supply in place of the shipped ones.

To integrate into an existing MediaWiki by hand:

1. Copy this repository into the wiki's `extensions/` directory as
   `TimeTracker/`.
2. Define the `NS_CUSTOMER`/`NS_JOB`/`NS_TASK` namespaces and load the
   dependencies + this extension in `LocalSettings.php` (see TimeTrackerApp's
   `settings/Settings.php` for the exact block), then
   `wfLoadExtension( 'TimeTracker' );`.
3. `enableSemantics()` must be active; import the forms, templates, and
   property pages (TimeTrackerApp's `content/`) and run
   `php extensions/SemanticMediaWiki/maintenance/rebuildData.php`.

## Development

```sh
# From a MediaWiki checkout with this extension in extensions/TimeTracker:
vendor/bin/phpunit -c extensions/TimeTracker/tests/phpunit/phpunit.xml.dist --testsuite unit
```

## License

GPL-2.0-or-later.
