# Symfony bundle config

Пакет використовує один кореневий вузол: `logging`.

```yaml
logging:
  processors:
    - message_normalizer
    - trace
  integrations:
    - otel_trace
  formatter:
    schema_version: '1.0.0'
    service_name: 'adheart'
    service_version: '%env(string:RELEASE_ID)%'
```

## Пояснення

- `processors` — список alias-ів або service id процесорів Monolog.
- `integrations` — список alias-ів інтеграцій; одна інтеграція може підключати кілька процесорів і trace providers.
- `formatter` — налаштування schema formatter v1.

## Розширення alias-ів

Можна реєструвати власні alias-и для сервісів поза пакетом:

```yaml
logging:
  aliases:
    processors:
      app_processor: '@app.logging.processor.custom'
    trace_providers:
      app_provider: '@app.trace.provider'
    integrations:
      app_trace:
        processors: ['trace']
        trace_providers: ['app_provider']
```

## Важливо

- Невідомий alias інтеграції викликає помилку конфігурації.
- Невідомий formatter/processor service id викликає fail-fast помилку компіляції контейнера.
- `enabled` та `apply_to_all_handlers` не використовуються.
