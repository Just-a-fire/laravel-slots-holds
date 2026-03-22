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
N=10  # Количество запросов по умолчанию
SAME_UUID=false

# Оптимизированный выбор генератора UUID (один раз при старте)
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

N_OVERRIDE=""
# Цикл обработки аргументов (--same-uuid и -n=15)
while [[ $# -gt 0 ]]; do
    case $1 in
        -n=*|--n=*)
            N_OVERRIDE="${1#*=}"
            shift
            ;;
        -n|--n)
            N_OVERRIDE="$2"
            shift 2
            ;;
        --same-uuid)
            SAME_UUID=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

# Проверка на некорректный ввод N
if [[ -n "$N_OVERRIDE" ]]; then
    if [[ ! "$N_OVERRIDE" =~ ^[0-9]+$ ]]; then
        echo -e "${RED}❌ Ошибка: Параметр -n должен быть целым числом (передано: '$N_OVERRIDE')${NC}"
        exit 1
    fi
    if [[ "$N_OVERRIDE" -lt 1 ]]; then
        echo -e "${RED}❌ Ошибка: Количество запросов должно быть больше 0${NC}"
        exit 1
    fi
    N=$N_OVERRIDE
    echo -e "🔢 Количество запросов задано вручную: ${YELLOW}$N${NC}"
fi

# Определяем ширину поля на основе максимального числа N
# Если N < 10, ширина будет 1, если 10-99 — 2, и т.д.
WIDTH=${#N}
STATIC_UUID=$(_generate_uuid)
# Если флаг активен, генерируем один UUID на весь тест
if [ "$SAME_UUID" = true ]; then
    echo -e "ℹ️  Используется один UUID: ${YELLOW}$STATIC_UUID${NC}"
fi

# Подготовка данных перед стартом
declare -a UUIDS
for ((i=1; i<=N; i++)); do
    UUIDS[$i]=$([ "$SAME_UUID" = true ] && echo "$STATIC_UUID" || _generate_uuid)
done

STATS_FILE=$(mktemp)

# фиксируем время старта
START_TIME=$(date +%s.%N 2>/dev/null || date +%s)

echo "🔥 Запуск стресс-теста на $URL ($N параллельных запросов)"

for ((i=1; i<=N; i++))
do
    CURRENT_UUID=${UUIDS[$i]}

    # Форматируем номер запроса с пробелами слева
    I_FORMATTED=$(printf "%${WIDTH}d" $i)
    
    (
        # Выполняем запрос и ловим HTTP-код
        RESPONSE_CODE=$(
            curl -s -o /dev/null -w "%{http_code}" -X POST "$URL" \
                -H "Idempotency-Key: $CURRENT_UUID" \
                -H "Accept: application/json"
        )

        # Сохраняем результат для статистики
        echo "$RESPONSE_CODE" >> "$STATS_FILE"

        # Подсветка: 200/201 - OK, остальное - FAIL
        if [[ "$RESPONSE_CODE" == "200" || "$RESPONSE_CODE" == "201" ]]; then
            echo -e "Request $I_FORMATTED [$CURRENT_UUID]: ${GREEN}$RESPONSE_CODE${NC}"
        else
            echo -e "Request $I_FORMATTED [$CURRENT_UUID]: ${RED}$RESPONSE_CODE${NC}"
        fi
    ) &
done

# Ждем завершения всех фоновых процессов
wait

# Конец замера времени
END_TIME=$(date +%s.%N 2>/dev/null || date +%s)
# РАСЧЕТ 
# Считается время выполнения именно самого цикла стрельбы, без подготовки (генерация UUID, загрузка .env)
# Выбор самый быстрый доступный калькулятор
if [[ "$START_TIME" == *"."* && "$END_TIME" == *"."* ]]; then
    # Если есть наносекунды (Linux/WSL)
    if command -v bc >/dev/null 2>&1; then
        DURATION=$(echo "$END_TIME - $START_TIME" | bc | awk '{printf "%.2f", $0}')
    else
        # Простой расчет через awk (обычно есть везде в паре с bash)
        DURATION=$(awk -v s="$START_TIME" -v e="$END_TIME" 'BEGIN {printf "%.2f", e - s}')
    fi
else
    # Если наносекунд нет (Git Bash / Windows)
    # Используем PowerShell один раз для вычитания (он точнее секунд)
    DURATION=$(powershell.exe -Command "[round]($END_TIME - $START_TIME, 2)" | tr -d '\r')
    
    # Если PowerShell вернул пустоту (вдруг), считаем просто секунды
    DURATION=${DURATION:-$((END_TIME - START_TIME))}
fi

# Итоговая статистика
SUCCESS=$(grep -cE '^(200|201)$' "$STATS_FILE" 2>/dev/null)
rm -f "$STATS_FILE"

echo -e "\n📊 Итог: ${GREEN}$SUCCESS${NC} из $N прошли успешно."
echo -e "⏱️  Время выполнения: ${YELLOW}${DURATION} сек.${NC}"

if [ "$SUCCESS" -gt 1 ] && [ "$SAME_UUID" = false ]; then
    echo -e "${RED}⚠️  ВНИМАНИЕ: Обнаружен овербукинг!${NC}"
elif [ "$SUCCESS" -gt 1 ] && [ "$SAME_UUID" = true ]; then
    echo -e "${YELLOW}ℹ️  Повторные успешные ответы ожидаемы (идемпотентность).${NC}"
fi
