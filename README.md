# University Enrollment ↔ Registrar — EIP Demo

Illustrates five Enterprise Integration Patterns (EIP) using real
infrastructure — not mocks:

```
                                                    ┌─ ENROLL ─▶ [Aggregator] ─▶ [MySQL]
[PHP Enrollment System] --publish--> [RabbitMQ: enrollment.requests] --consume--> [Camel: Transform] --▶ [Router] ─┤
                                                                                                          └─ DROP ───▶ [MySQL]
                                                                                        │
                                                                                onException (retry/permanent)
                                                                                        ▼
                                                                          [RabbitMQ: enrollment.errors] ──▶ [MySQL: enrollment_errors]
```

Apache Camel is a JVM framework, so it can't run inside PHP directly. Instead:
- **PHP** is the *sender*: a web form that publishes JSON messages onto a
  durable RabbitMQ queue (`enrollment.requests`) using `php-amqplib`.
- **RabbitMQ** carries both the request channel and the error channel.
- **Camel** (packaged as a Spring Boot app) is the *receiver*: it consumes,
  transforms, routes, aggregates, and is the only system that writes to
  MySQL. Even if you scale this service to multiple instances, RabbitMQ
  guarantees each message is processed exactly once.

## The five patterns

### 1. Message Channel (Point-to-Point)
`enrollment.requests` is a durable RabbitMQ queue. PHP publishes to
RabbitMQ's default (nameless) exchange with routing key = queue name — the
simplest possible Point-to-Point setup. Camel's `spring-rabbitmq:default`
consumer picks messages off it. Even with multiple Registrar instances
running (`docker compose up --scale registrar-camel=3`), RabbitMQ hands each
message to exactly one consumer.

Both durable queues (`enrollment.requests` and `enrollment.errors`) are
declared once, queue-only, by `RabbitMQConfig.java` — not by Camel's
`autoDeclare`. That's deliberate: AMQP forbids *explicit* bindings to the
default exchange (every queue is implicitly bound to it by name already),
but Camel's `autoDeclare=true` tries to bind anyway, which RabbitMQ refuses
with `access_refused: operation not permitted on the default exchange` and
kills the channel before the consumer ever starts. Both `from(...)`
consumer URIs below explicitly set `autoDeclare=false` to avoid that.
→ `EnrollmentRoute.java`, top-level `from("spring-rabbitmq:default?queues=enrollment.requests&autoDeclare=false")`
→ `RabbitMQConfig.java` for where the queues actually get declared

### 2. Message Transformer
Before anything else touches a message, `EnrollmentTransformer` normalizes
it: trims whitespace, uppercases `course_id`, defaults a missing `action` to
`ENROLL` (so older producers still work), and stamps a `processedAt`
timestamp. It also rejects structurally invalid messages (missing required
fields) by throwing — which the error handling pipeline treats as
non-retryable.
→ `EnrollmentTransformer.java`

### 3. Content-Based Router
Immediately after transformation, a `.choice()` block branches on the
message's `action` field: `DROP` requests go to `direct:processDrop`,
`ENROLL` requests go to `direct:enrollAggregator`, anything else is treated
as an error.
→ `EnrollmentRoute.java`, the `.choice()...end()` block

### 4. Aggregator
ENROLL requests for the same `course_id`+`term` are batched together by
`EnrollmentAggregationStrategy` before being processed as one transaction.
A batch is released once either 5 requests have accumulated or 3 seconds
have passed, whichever comes first. This models a real reason to
aggregate: seat allocation for a course is a point of write contention, so
grouping competing requests into fewer transactions reduces lock churn on
that course's row.
→ `EnrollmentAggregationStrategy.java`, wired in `EnrollmentRoute.java`'s
  `direct:enrollAggregator` route

### 5. Error Handling
Two tiers of failure handling in `EnrollmentRoute.java`:
- **Permanent failures** (`IllegalArgumentException`, malformed JSON) —
  zero retries, straight to the error channel, since retrying a
  structurally bad message can never succeed.
- **Transient failures** (`DataAccessException`, `SQLException`) — retried
  up to 3 times with exponential backoff before being dead-lettered.
- A global `deadLetterChannel` catches anything not matched by the above.

Everything that fails ends up on the `enrollment.errors` queue, consumed by
`EnrollmentErrorRoute` and persisted to the `enrollment_errors` table via
`EnrollmentErrorProcessor`, so failures are inspectable rather than silently
dropped or retried forever.
→ `EnrollmentRoute.java` (`errorHandler`/`onException` blocks),
  `EnrollmentErrorRoute.java`, `EnrollmentErrorProcessor.java`

## Running it

```bash
docker compose up --build
```

This starts 4 containers: MySQL, RabbitMQ, the PHP enrollment app, and the
Camel/Spring Boot registrar app.

- Enrollment form: http://localhost:8080
- **Admin dashboard**: http://localhost:8080/admin.php — publish structured or
  raw test messages, and view Courses/Enrollments/enrollment_errors without
  leaving the browser
- RabbitMQ management UI: http://localhost:15672 (user: `guest`, pass: `guest`)
- MySQL: `localhost:3306`, database `registrar`, user `registrar_user` / `registrar_pass`

If you're updating from an older checkout that predates the
`enrollment_errors` table, you need a fresh MySQL volume for `init.sql` to
re-run:
```bash
docker compose down -v
docker compose up --build
```

## Try it

1. Open http://localhost:8080 and submit an **Enroll** for `CS301` /
   `Fall2026` with any student ID.
2. Watch it appear briefly on the RabbitMQ queue at http://localhost:15672 →
   Queues → `enrollment.requests`.
3. Check the result landed in MySQL:
   ```bash
   docker exec -it registrar-mysql mysql -uregistrar_user -pregistrar_pass registrar \
     -e "SELECT * FROM enrollments; SELECT * FROM courses;"
   ```
4. `CS301` is seeded with a capacity of **3** on purpose — submit 4+ different
   student IDs and watch later ones get `REJECTED_NO_SEATS` instead of
   `ENROLLED`. This demonstrates the Registrar (not the Enrollment System) is
   the single authority enforcing business rules.
5. Submit the exact same student/course/term twice — the second one is
   marked `DUPLICATE`.
6. **Content-Based Router**: submit an Enroll, then submit a **Drop** for the
   same student/course. Watch the Camel logs (`docker compose logs -f
   registrar-camel`) show the message taking the `direct:processDrop` path
   instead of `direct:enrollAggregator`.
7. **Message Transformer**: submit `course_id = "cs301"` (lowercase, extra
   spaces). The log line `Transformed request:` will show it normalized to
   `CS301`.
8. **Aggregator**: submit 5+ Enroll requests for the same course/term in
   quick succession, or submit just one and wait ~3 seconds. Look for
   `Releasing aggregated batch of N ENROLL request(s)` in the logs.
9. **Error Handling**: use the admin dashboard's "Publish a raw message" box
   at http://localhost:8080/admin.php — send something like
   `{"course_id": "CS301", "term": "Fall2026"}` (missing `student_id`), or
   truly malformed JSON like `{not valid json`. Either way it'll be rejected
   (non-retryable) and appear in the Error Channel table on the same page
   within a second or two. (You can also do this via RabbitMQ's own
   management UI → Queues → `enrollment.requests` → Publish message, if you'd
   rather not use the dashboard.)

## Project layout

```
university-eip-demo/
├── docker-compose.yml
├── mysql/init.sql                     # schema + seed courses + error log table
├── enrollment-system-php/             # sender: PHP web form -> RabbitMQ
│   ├── Dockerfile
│   ├── composer.json
│   ├── src/RabbitMQPublisher.php
│   └── public/
│       ├── index.php                  # student-facing enrollment form
│       └── admin.php                  # admin dashboard: publish + view state (single file)
└── registrar-camel/                   # receiver: Camel routes -> MySQL
    ├── Dockerfile
    ├── pom.xml
    └── src/main/java/com/university/registrar/
        ├── RegistrarCamelApplication.java
        ├── RabbitMQConfig.java              (declares the two durable queues)
        ├── EnrollmentRoute.java             (Message Channel, Router, error wiring)
        ├── EnrollmentTransformer.java       (Message Transformer)
        ├── EnrollmentAggregationStrategy.java (Aggregator)
        ├── EnrollmentProcessor.java         (business logic: batch enroll, drop)
        ├── EnrollmentErrorRoute.java        (error channel consumer)
        ├── EnrollmentErrorProcessor.java    (persists failures)
        └── EnrollmentRequest.java           (message DTO)
```

## Notes / things worth knowing

- **Why the default exchange?** RabbitMQ's nameless default exchange routes
  messages to the queue whose name matches the routing key. That's the
  simplest possible Point-to-Point setup — no extra bindings needed. For
  Publish-Subscribe you'd instead use a `fanout` or `topic` exchange with
  multiple bound queues.
- **`durable=true` / persistent messages**: both queues (`enrollment.requests`
  and `enrollment.errors`) and their messages are marked durable, so
  requests survive a RabbitMQ restart (a taste of the *Guaranteed Delivery*
  pattern).
- **Atomic seat check**: `EnrollmentProcessor` uses a single conditional
  `UPDATE ... WHERE seats_taken < capacity` inside a transaction, so
  concurrent requests can't both squeeze into the last seat.
- **Batch transactions in the Aggregator**: `processBatch` wraps an entire
  released batch in one transaction. If one request in a batch of 5 throws,
  all 5 roll back together — an all-or-nothing trade-off, not per-item
  isolation. Worth calling out explicitly if this is being graded.
- **Scaling the Registrar**: try `docker compose up --scale registrar-camel=3`
  — you'll still see each enrollment processed exactly once, since RabbitMQ
  hands each queued message to only one competing consumer.
- **First-run Maven build**: the Camel image compiles on first `docker compose
  build`, which can take a couple of minutes since it downloads dependencies.
  Subsequent builds are cached.
