# Production scheduler

Run Laravel's scheduler every minute on the production application host:

```text
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks share the application's cache for overlap prevention, single-server execution, and health heartbeats. In multi-server deployments, all scheduler nodes must use the same cache backend that supports atomic locks.
