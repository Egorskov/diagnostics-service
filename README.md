# diagnostics-service

Сервис Диагностики платформы менторинга: собирает события активности учеников,
считает метрики прогресса и публикует сигналы `diagnostics.*`.

- Спецификация: [`docs/SPEC.md`](docs/SPEC.md)
- Контракты событий: [`docs/asyncapi.yaml`](docs/asyncapi.yaml)
- Архитектурные решения: [`docs/adr/`](docs/adr/)

## Требования

- Docker с Compose v2
- [OpenSpec CLI](https://github.com/Fission-AI/OpenSpec) для ведения изменений: `npm i -g @fission-ai/openspec`
