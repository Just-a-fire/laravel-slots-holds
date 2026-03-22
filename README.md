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
    ```bash
    cp ./backend/.env.example ./backend/.env
    ```
    для тестов **скопируйте ./backend/.env.example.testing**
    ```bash
    cp ./backend/.env.example.testing ./backend/.env.testing
    ```
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


## Запуск тестов

### 1. Feature-тесты
Чтобы запустить тесты в Docker, используйте:
```bash
docker compose exec app php artisan test
```

Один конкретный тест:
```bash
docker compose exec app php artisan test ./tests/Feature/SlotBookingTest.php
```

### 2. Стресс-тесты
Для проверки **Race Condition**, запускается `bash`-скрипт, отправляющий 10 конкурентных запросов
```bash
chmod +x scripts/stress_test.sh
./scripts/stress_test.sh
```
Количество запросов можно указать, задав параметр `n`
```bash
./scripts/stress_test.sh -n=20
```
#### Ожидаемый результат
Если в базе у слота №3 **остаток 1**, а мы отправили 10 запросов с **разными** UUID:
- **1 запрос:** Вернёт `201 Created` (успешный холд).
- **9 запросов:** Вернут `409 Conflict` (ошибка овербукинга)

### 3. Проверка идемпотентности (Дополнение)
Чтобы отправить 10 запросов с **одним и тем же** UUID, нужно добавить флаг `--same-uuid`:
```bash
./scripts/stress_test.sh --same-uuid
```
#### Ожидаемый результат
- **Все 10 запросов** должны вернуть успех (`201`), но в базе должна появиться только **одна** запись.

Для многократного запуска удобно использовать команду очистки **всех** неподтверждённых холдов (чтобы не ждать 5 минут и не очищать их вручную)
```bash
docker compose exec app php artisan holds:clear-unconfirmed && ./scripts/stress_test.sh --same-uuid
```


## Упрощения

### 1. Для _облегчения_ тестирования **ключ идемпотентности** проверяется только на наличие, но не на соответствие формату `uuid`. 
    
А в `PostgreSQL` это и не удалось бы сделать, потому что инструкция миграции
```php
$table->uuid('idempotency_key')->unique();
```
создаст поле с нативным типом `uuid`, который не позволит записать в него значение вида _uuid-unique-123_.

### 2. Сейчас кеш хранится в одном ключе. 
В реальном проекте лучше использовать связку **упорядоченного множества (ZSET)** и **хеш-таблицы (HASH)**. В `ZSET` хранить `slotId` и `remaining`, в `HASH` - полную информацию об объекте.

Это позволит **атомарно** обновлять доступность для каждого слота, не понадобится полная перегенерация кеша при изменении доступности одного слота.