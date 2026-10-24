# Hypervel pages for each part of src/

Before changing a part of `src/`, read the sections of Hypervel 0.4's documentation listed for it
below, as page › heading. The pages are in `vendor/hypervel/components/src/docs/`; where the
hypervel-porting MCP server is set up, its `read_doc` reads a page, or one section of it.

| Part of src/ | Read |
|---|---|
| `InfluxDBServiceProvider.php` | providers.md › Service Providers and Long-Running Workers; packages.md › Default Package Configuration; packages.md › Migrations; packages.md › Commands; packages.md › "About" Artisan Command; artisan.md › Events |
| `InfluxDBManager.php`, `InfluxDBFactory.php`, `Facades/` | container.md › Resolution Lifecycles; container.md › Per-Call State on Shared Instances; coroutine-context.md › Reading Non-Coroutine Context; facades.md › How Facades Work |
| `InfluxQL/`, the package's own builder, grammars and connections | queries.md › Raw Expressions; queries.md › Ordering, Grouping, Limit and Offset |
| `InfluxQL/Driver/`, `Sql/`, `Support/DetectsLostTransport.php`: the database drivers | database.md › Extending Database Connections; database.md › Detecting Database Failures; database.md › Early Release for Custom Drivers; database.md › Connection Pooling; pools.md › Connection Pools; pagination.md › Cursor Pagination |
| `Eloquent/` | eloquent.md › Custom Eloquent Builders; eloquent.md › Database Connections; pagination.md › Cursor Pagination |
| `Write/BatchingWriter.php`, `Write/Failover.php`, `Write/Availability.php` | coroutines.md › Owning Child Startup; coroutines.md › Detached Background Work; lifecycle.md › Per-Worker vs. Per-Request |
| `Write/*FailureStore.php`, `Write/Concerns/` | database.md › Using Multiple Database Connections; redis.md › Using Multiple Redis Connections; filesystem.md › Obtaining Disk Instances; logging.md › Writing Log Messages; errors.md › Reporting Exceptions |
| `Jobs/`, `Write/QueueFailureStore.php` | queues.md › Class Structure; queues.md › Dispatching Jobs |
| `Console/` | artisan.md › Writing Commands |
| `Testing/` | packages.md › Test State Cleanup |
| What InfluxDB itself does: InfluxQL, SQL, line protocol and each server version's API | The claude.ai `InfluxDB` connector, InfluxData's documentation server |

## Every package

- packages.md › Package Discovery
- packages.md › Service Providers
- packages.md › Test State Cleanup
