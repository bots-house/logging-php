# log schema v1

## Вступ

Формат описує **одну лог-подію** у вигляді **NDJSON** (1 запис = 1 JSON-рядок, UTF-8). Мета — однакова структура для всіх сервісів, зручний пошук і агрегування в Grafana/Loki, а також проста еволюція схеми.

---

## 1. Обовʼязкові поля (top-level)

### `timestamp` — час події

* **Тип:** `string`
* **Формат:** RFC 3339 (ISO-8601) з мілісекундами, **UTC**
  `YYYY-MM-DDThh:mm:ss.mmmZ` → напр. `2025-11-24T10:10:05.871Z`
* **Навіщо:** уніфікований час для сортування та коректних тайм-зон.

### `level` — рівень критичності

* **Тип:** `object`
* **Структура:**

  ```json
  { "level": 300, "severity": "WARNING" }
  ```

    * `level` — **число** (коди Monolog/PSR-3):
      `DEBUG=100`, `INFO=200`, `NOTICE=250`, `WARNING=300`, `ERROR=400`, `CRITICAL=500`, `ALERT=550`, `EMERGENCY=600`
    * `severity` — **рядок** UPPERCASE з набору:
      `DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY`
* **Навіщо:** число — стабільне сортування та пороги; текст — зручний ручний фільтр.

### `message` — опис події

* **Тип:** `string`
* **Вимоги:** стисле, людиночитне формулювання без вкладених JSON-структур.

### `service` — ідентичність застосунку

* **Тип:** `object`
* **Структура:**

  ```json
  { "name": "example-service", "version": "prod-1.0.133", "channel": "app" }
  ```

    * `name` — назва додатку/сервісу
    * `version` — версія/тег/short-commit релізу додатку
    * `channel` — джерело події: `app | request | security | worker | console`
* **Навіщо:** чітко розрізняємо, **хто** та **звідки** пише лог.

### `trace` — ідентифікатори трасування

* **Тип:** `object` (може бути порожній `{}`)
* **Рекомендовані ключі:**

  ```json
  {
    "trace_id": "16-byte hex",
    "span_id": "8-byte hex",
    "sampled": "01|00",
    "traceparent": "W3C traceparent",
    "request_id": "довільний ID запиту",
    "cf": { "ray_id": "Cloudflare Ray ID" }
  }
  ```
* **Навіщо:** кореляція між логами, трейсам, запитами, проксі.

### `context` — розріджені дані події

* **Тип:** `object` (може бути порожній `{}`)
* **Правило:** усе, що не є універсальним для кожного запису (користувач, реквест, виняток, таймінги, процес тощо), акуратно групуємо у підблоки. Деталі — у розділі 2.

### `deployment` — операційні метадані

* **Тип:** `object`
* **Хто заповнює:** **інфраструктура** на інджесті; **застосунки цей блок не пишуть**.
* **Приклад структури (довідково):**

  ```json
  {
    "environment": "prod",
    "host": "worker-hz-s3",
    "stack": "example-production",
    "service": "server",
    "release": "2025-11-24-01",
    "git": { "commit": "a1b2c3d", "tag": "v1.0.133" }
  }
  ```

### `version` — версія схеми логів

* **Тип:** `string`
* **Формат:** SemVer `x.x.x` (напр. `"1.0.0"`)
* **Навіщо:** контроль еволюції формату без зламу інтеграцій.

---

## 2. `context` — структура та правила

**Призначення:** акуратно складати неуніверсальні атрибути події в підблоки, які легко читати та фільтрувати.

**Базові підблоки (додаються лише за наявності даних):**

* `user` → мінімум `id`
  *Приклад:*

  ```json
  { "user": { "id": "0189553f-a049-1219-3ca1-ef311636a5bc" } }
  ```
* `request` → ключові атрибути HTTP-запиту
  *Приклад:*

  ```json
  {
    "request": {
      "host": "example.com",
      "method": "GET",
      "path": "/api/advertisements/search",
      "route": "api_advertisements_search",
      "controller": "App\\Http\\Controllers\\Api\\AdvertisementController::getAdvertisements",
      "query": { "limit": "40", "text_search_value": "27E" }
    }
  }
  ```
* `exception` → структурована помилка
  *Приклад:*

  ```json
  {
    "exception": {
      "class": "Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException",
      "message": "Access denied.",
      "code": 10,
      "file": "/var/www/.../SearchVoter.php:125"
    }
  }
  ```

**Нотатки:**

* Підблоки можуть бути будь-які: `session`, `timing`, `process`, `business` тощо — за потреби.
* Дані з персональними ідентифікаторами/секретами мають бути замасковані на боці застосунку.

---

## 3. Приклад одного лог-повідомлення

```json
{
  "timestamp": "2025-11-24T10:10:05.871Z",
  "level": { "level": 400, "severity": "ERROR" },
  "message": "API exception: Failed to denormalize attribute \"placement_date_startDate\" ...",

  "service": {
    "name": "example-service",
    "version": "prod-1.0.133",
    "channel": "app"
  },

  "trace": {
    "trace_id": "dff3d9dca1dd07c965cfd1b68d780c7a",
    "span_id": "0f6ea59d1286a584",
    "sampled": "01",
    "traceparent": "00-dff3d9dca1dd07c965cfd1b68d780c7a-0f6ea59d1286a584-01",
    "cf": { "ray_id": "9a381d518874de92-EWR" }
  },

  "context": {
    "user": { "id": "0187ec77-4e6e-4d5e-d4e5-f8f2bb1ce9a4" },
    "request": {
      "host": "example.com",
      "method": "GET",
      "path": "/api/advertisements/search",
      "route": "api_advertisements_search",
      "controller": "App\\Http\\Controllers\\Api\\AdvertisementController::getAdvertisements",
      "query": {
        "limit": "40",
        "link_search_value": "Leaply",
        "placement_date_startDate": ["2025-08-01","2025-09-01","2025-08-01"],
        "placement_date_endDate":   ["2025-08-31==","2025-09-30=","2025-08-31"]
      }
    },
    "exception": {
      "class": "Symfony\\Component\\Serializer\\Exception\\NotNormalizableValueException",
      "message": "Failed to denormalize attribute ...",
      "code": 0,
      "file": "/vendor/symfony/serializer/Exception/NotNormalizableValueException.php:31"
    }
  },

  "deployment": {},
  "version": "1.0.0"
}
```

## 4. Автодіскавері логів

Автоматичний збір логів налаштовується **через Docker labels**. Інфраструктура (Vector) читає лейбли контейнера й відповідно вмикає інджест та застосовує потрібну схему.

**Лейбли:**

* **`vector.log.collect`** — `"true"`/`"false"`
  Вмикає або вимикає збір логів із контейнера. Рекомендація: **явно ставити `"true"`** для сервісів, які мають логуватись. За відсутності або будь-якого іншого значення інджест не виконується.
* **`vector.log.schema`** — назва схеми подій
  Поточне значення: **`app`**. Закладено можливість розширення (наприклад, **`otel`**), якщо у майбутньому перейдемо на відповідну модель.

**Приклад (Swarm):**

```yaml
services:
  server:
    image: registry.example.com/example/server:prod-1.0.133
    labels:
      - "vector.log.collect=true"
      - "vector.log.schema=app"
```
