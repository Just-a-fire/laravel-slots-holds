<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SlotService;

use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(
        protected SlotService $slotService
    ) {}

    /**
     * GET /slots/availability
     */
    public function __invoke(): JsonResponse
    {
        $slots = $this->slotService->getAvailableSlots();

        return response()->json($slots);
    }
}
