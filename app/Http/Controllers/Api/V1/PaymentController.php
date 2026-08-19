<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Services\PaymentService;
use App\Domain\Auth\Permission;
use App\Domain\Organization\Organization;
use App\Domain\Payment\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RefundPaymentRequest;
use App\Http\Resources\PaymentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payments')]
class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PaymentService $payments) {}

    #[OA\Get(
        path: '/api/v1/payments',
        summary: 'List payments visible to the current user (own bookings\' payments + orgs they can read)',
        tags: ['Payments'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Payments list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Payment::query()->with(['booking', 'booking.organization']);

        if (! $user->is_platform_admin) {
            $readableOrgIds = $user->organizations
                ->filter(fn (Organization $org) => $user->hasPermissionTo(Permission::PaymentsRead, $org))
                ->pluck('id');

            $query->whereHas('booking', function ($q) use ($user, $readableOrgIds) {
                $q->where('customer_id', $user->id);

                if ($readableOrgIds->isNotEmpty()) {
                    $q->orWhereIn('organization_id', $readableOrgIds);
                }
            });
        }

        if ($request->filled('booking_id')) {
            $query->whereHas('booking', fn ($q) => $q->where('public_id', $request->query('booking_id')));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return PaymentResource::collection($query->orderByDesc('created_at')->cursorPaginate(20)->withQueryString());
    }

    #[OA\Get(
        path: '/api/v1/payments/{payment}',
        summary: 'Get a payment by its public id',
        tags: ['Payments'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Payment')],
    )]
    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment);
    }

    #[OA\Post(
        path: '/api/v1/payments/{payment}/refund',
        summary: 'Refund a paid payment, in full or in part (§30)',
        tags: ['Payments'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Payment refunded/partially refunded'),
            new OA\Response(response: 422, description: 'Payment cannot be refunded, or amount exceeds what is refundable'),
        ],
    )]
    public function refund(RefundPaymentRequest $request, Payment $payment): PaymentResource
    {
        $amount = $request->filled('amount') ? (string) $request->validated('amount') : null;

        return new PaymentResource($this->payments->refund($payment, $amount));
    }
}
