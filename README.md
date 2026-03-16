# Slot Booking API (Laravel 12 + Docker)

Минимальный сервис бронирования слотов с защитой от оверсела (Race Condition), поддержкой идемпотентности и "горячим" кешем в Redis.

## 🚀 Быстрый старт

1. **Клонируйте репозиторий** и перейдите в корень проекта.
2. **Запустите контейнеры**:
    ```bash
    docker-compose up -d --build
    ```
3. **Установите зависимости**:
    ```bash
    docker compose exec app composer install
    ```
4. **Скопируйте ./backend/.env.example в ./backend/.env**
5. **Запустите миграции и сидер**:
    ```bash
    docker compose exec app php artisan migrate --seed --seeder=SlotSeeder
    ```

## 🛠 API Эндпоинты и Тестирование

### 1. Получение доступных слотов (Кеш 15 сек)
```bash
curl -X GET http://localhost:8080/api/slots/availability
```
### 2. Создание временного холда (Идемпотентность)
```bash
curl -i -X POST http://localhost:8080/api/slots/3/hold \
    -H "Idempotency-Key: uuid-unique-123"
```
- **Повторный запрос** с тем же ключом вернет тот же объект (`200 OK`).
- **Запрос с другим ключом**, если место кончилось, вернет `409 Conflict`.
### 3. Подтверждение брони (Защита от оверсела)
```bash
curl -i -X POST http://localhost:8080/api/holds/1/confirm
```
- **Метод атомарно уменьшает** `remaining` в таблице `slots`.
- **Инвалидирует кеш доступности**.
### 4. Отмена холда
```bash
curl -i -X DELETE http://localhost:8080/api/holds/1
```
Освобождает место (если было подтверждено) и сбрасывает кеш.