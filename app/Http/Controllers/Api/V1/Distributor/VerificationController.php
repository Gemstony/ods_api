<?php

namespace App\Http\Controllers\Api\V1\Distributor;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\VerificationCode;
use App\Services\Verification\VerificationCodeService;
use App\Services\Verification\VerificationException;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Distributor-issued verification codes (Phase 12.2).
 *
 * Endpoint:
 *   POST /distributor/deliveries/{delivery}/verification/issue
 *
 * Creates (or re-creates) the DISTRIBUTOR_CODE and RECIPIENT_CODE for a
 * delivery and returns the raw values ONCE. The distributor is
 * responsible for sharing them with the appropriate parties
 * (SMS delivery lands in Phase 13; manual sharing is the interim path).
 *
 * Rate-limited to prevent abuse.
 */
class VerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly VerificationCodeService $verification,
    ) {}

    public function issue(Request $request, int $delivery): JsonResponse
    {
        $model = Delivery::where('id', $delivery)
            ->where('distributor_id', $request->user()->id)
            ->first();

        if ($model === null) {
            return $this->error('Delivery not found.', null, 404);
        }

        try {
            [$distCode, $distRaw] = $this->verification->issueForDelivery(
                $model,
                VerificationCode::TYPE_DISTRIBUTOR,
                $request->user(),
            );
            [$recipCode, $recipRaw] = $this->verification->issueForDelivery(
                $model,
                VerificationCode::TYPE_RECIPIENT,
                $request->user(),
            );
        } catch (VerificationException $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success(
            data: [
                'delivery_id' => $model->id,
                'tracking_id' => $model->tracking_id,
                'codes' => [
                    'distributor' => [
                        'code'       => $distRaw,
                        'expires_at' => $distCode->expires_at?->toIso8601String(),
                    ],
                    'recipient' => [
                        'code'       => $recipRaw,
                        'expires_at' => $recipCode->expires_at?->toIso8601String(),
                    ],
                ],
                'warning' => 'The raw codes are returned once and never recoverable. '
                    . 'Share the DISTRIBUTOR_CODE with the Receiver or Rider at pickup, '
                    . 'and the RECIPIENT_CODE with the recipient before delivery.',
            ],
            message: 'Verification codes issued. Save them — they will not be shown again.',
        );
    }
}