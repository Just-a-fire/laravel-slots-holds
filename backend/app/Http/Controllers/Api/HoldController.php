<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlotService;

use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Http\Request;

class HoldController extends Controller
{
    public function __construct(
        protected SlotService $slotService
    ) {}

    /**
     * POST /slots/{id}/hold
     */
    public function hold(Request $request, int $id) {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'Заголовок Idempotency-Key обязателен'
            ], 400)->setEncodingOptions(JSON_UNESCAPED_UNICODE);
        }

        $hold = $this->slotService->createHold($id, $idempotencyKey);
        if (!$hold) {
            return response()->json(['error' => 'Слот заполнен или не существует'], 409)->setEncodingOptions(JSON_UNESCAPED_UNICODE);
        }

        return response()->json($hold, 201);
    }

    /**
     * POST /holds/{id}/confirm
     */
    public function confirm(int $id)
    {
        $result = $this->slotService->confirmHold($id);

        if (!$result) {
            return response()->json([
                'error'=> 'Не удалось подтвердить. Уже подтверждён или заполнен слот'
            ], 409)->setEncodingOptions(JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DELETE /holds/{id}
     */
    public function cancel(int $id)
    {
        $this->slotService->cancelHold($id);

        return response()->json(['message' => 'Холд отменен. Слот снова доступен'], 200)->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }
}
