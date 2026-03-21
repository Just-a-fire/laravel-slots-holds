#!/bin/bash

# Скрипт для проверки овербукинга (отправка 10 запросов на слот с 1 местом)

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Загружаем переменные из .env, который лежит уровнем выше
if [ -f .env ]; then
    # удаляем Windows-окончания строк через sed 's/\r$//' (важно для WSL/Linux)
    export $(grep -v '^#' .env | sed 's/\r$//' | xargs)
else
    echo -e "${RED}❌ Файл .env не найден!${NC}"
    exit 1
fi

PORT=${NGINX_PORT:-8080}
# Тестируем слот №3 (в сидере там обычно 1 место)
URL="http://localhost:${PORT}/api/slots/3/hold"
N=10  # Количество запросов
# Определяем ширину поля на основе максимального числа N
# Если N < 10, ширина будет 1, если 10-99 — 2, и т.д.
WIDTH=${#N}
SAME_UUID=false

# 2. Оптимизированный выбор генератора UUID (один раз при старте)
if [ -r /proc/sys/kernel/random/uuid ]; then
    # Самый быстрый способ (Linux/WSL)
    _generate_uuid() { cat /proc/sys/kernel/random/uuid; }
elif command -v uuidgen >/dev/null 2>&1; then
    # Стандарт для macOS и некоторых дистрибутивов Linux
    _generate_uuid() { uuidgen | tr '[:upper:]' '[:lower:]'; }
elif command -v powershell.exe >/dev/null 2>&1; then
    # Медленный способ для Windows (Git Bash без установленного uuidgen)
    _generate_uuid() { powershell.exe -Command "[guid]::NewGuid().ToString()" | tr -d '\r' | tr '[:upper:]' '[:lower:]'; }
else
    echo "❌ Ошибка: Генератор UUID не найден." >&2
    exit 1
fi

# Генерация UUID через PHP в контейнере (универсально для всех ОС)
# get_uuid() {
#     docker compose exec -T app php -r "echo \Illuminate\Support\Str::uuid();"
# }


# Проверка флага --same-uuid
for arg in "$@"; do
    if [ "$arg" == "--same-uuid" ]; then
        SAME_UUID=true
    fi
done

# Если флаг активен, генерируем один UUID на весь тест
if [ "$SAME_UUID" = true ]; then
    STATIC_UUID=$(_generate_uuid)
    echo -e "ℹ️  Используется один UUID: ${YELLOW}$STATIC_UUID${NC}"
fi


echo "🔥 Запуск стресс-теста на $URL ($N параллельных запросов)"

for ((i=1; i<=N; i++))
do
    if [ "$SAME_UUID" = true ]; then
        CURRENT_UUID=$STATIC_UUID
    else
        CURRENT_UUID=$(_generate_uuid)
    fi

    # Форматируем номер запроса с пробелами слева
    I_FORMATTED=$(printf "%${WIDTH}d" $i)

    # Выполняем запрос и ловим HTTP-код
    (
        RESPONSE_CODE=$(
            curl -s -o /dev/null -w "%{http_code}" -X POST "$URL" \
                -H "Idempotency-Key: $CURRENT_UUID" \
                -H "Accept: application/json"
        )

        # Подсветка: 200/201 - OK, остальное - FAIL
        if [[ "$RESPONSE_CODE" == "200" || "$RESPONSE_CODE" == "201" ]]; then
            echo -e "Request $I_FORMATTED [$CURRENT_UUID]: ${GREEN}$RESPONSE_CODE${NC}"
        else
            echo -e "Request $I_FORMATTED [$CURRENT_UUID]: ${RED}$RESPONSE_CODE${NC}"
        fi
    ) &
done

wait
echo "✅ Тест завершен."
