<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'system_type' => ['required', 'string', 'max:255'],
            'brand_preference' => ['nullable', 'string', 'max:255'],
            'request_type' => ['required', Rule::in(['repair', 'maintenance', 'installation', 'emergency'])],
            'description' => ['required', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'emergency'])],
            'preferred_date' => ['required', 'date'],
            'preferred_time_slot' => ['required', Rule::in(['morning_8_12', 'afternoon_12_4', 'evening_4_8', 'anytime'])],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip_code' => ['nullable', 'string', 'max:30'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $priorityScore = $this->priorityScore($validated['priority'], $validated['request_type']);
        $wazeLink = $this->wazeLink($validated);

        $serviceRequest = DB::transaction(function () use ($request, $validated, $priorityScore, $wazeLink) {
            $serviceRequest = ServiceRequest::query()->create([
                ...$validated,
                'user_id' => $this->userFromBearerToken($request)?->id,
                'priority_score' => $priorityScore,
                'queue_reference' => $this->nextQueueReference(),
                'status' => 'new',
                'waze_link' => $wazeLink,
            ]);

            return $serviceRequest->refresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Service request queued successfully.',
            'data' => [
                'request_id' => $serviceRequest->id,
                'queue_reference' => $serviceRequest->queue_reference,
                'priority_score' => $serviceRequest->priority_score,
                'waze_link' => $serviceRequest->waze_link,
                'coordinates' => [
                    'lat' => $serviceRequest->latitude ? (float) $serviceRequest->latitude : null,
                    'lng' => $serviceRequest->longitude ? (float) $serviceRequest->longitude : null,
                ],
            ],
        ], 201);
    }

    public function pending(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ServiceRequest::query()
                ->whereIn('status', ['new', 'scheduled', 'in_progress'])
                ->latest()
                ->get()
                ->map(fn (ServiceRequest $request) => $this->serviceRequestSummary($request)),
        ]);
    }

    public function history(): JsonResponse
    {
        $requests = ServiceRequest::query()
            ->whereIn('status', ['completed', 'canceled'])
            ->latest('updated_at')
            ->get()
            ->map(fn (ServiceRequest $request) => [
                'id' => $request->queue_reference,
                'customer' => $request->customer_name,
                'date' => optional($request->completed_at ?? $request->updated_at)->format('M d, Y'),
                'type' => $request->system_type,
                'tech' => $request->assigned_technician ?? 'Unassigned',
                'status' => $request->status,
                'priority' => $request->priority_score,
            ]);

        return response()->json([
            'success' => true,
            'count' => $requests->count(),
            'data' => $requests,
        ]);
    }

    public function trackingQueue(): JsonResponse
    {
        $requests = ServiceRequest::query()->latest()->get();
        $statusCounts = [
            'new' => 0,
            'scheduled' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'canceled' => 0,
        ];

        foreach ($requests as $request) {
            $statusCounts[$request->status] = ($statusCounts[$request->status] ?? 0) + 1;
        }

        return response()->json([
            'success' => true,
            'count' => $requests->count(),
            'statusCounts' => $statusCounts,
            'data' => $requests->map(fn (ServiceRequest $request) => [
                'id' => $request->queue_reference,
                'customer' => $request->customer_name,
                'type' => $request->system_type,
                'tech' => $request->assigned_technician ?? 'Unassigned',
                'window' => sprintf(
                    '%s | %s',
                    optional($request->preferred_date)->format('M d, Y'),
                    $this->timeSlotLabel($request->preferred_time_slot),
                ),
                'status' => $request->status,
                'waze_navigation_url' => $request->waze_link,
            ]),
        ]);
    }

    public function priorityQueue(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'requests' => ServiceRequest::query()
                ->whereIn('status', ['new', 'scheduled', 'in_progress'])
                ->orderByDesc('priority_score')
                ->limit(25)
                ->get()
                ->map(fn (ServiceRequest $request) => $this->serviceRequestSummary($request)),
            'technicians' => [
                ['name' => 'Dispatch Pool', 'dist' => 'Waze assignment pending', 'status' => 'Available'],
            ],
        ]);
    }

    private function serviceRequestSummary(ServiceRequest $request): array
    {
        return [
            'id' => $request->queue_reference,
            'customer_id' => (string) ($request->user_id ?? ''),
            'type' => $request->request_type,
            'priority_score' => $request->priority_score,
            'status' => $request->status,
            'created_at' => optional($request->created_at)->toISOString(),
            'description' => $request->description,
            'city' => $request->city,
            'state' => $request->state,
            'waze_navigation_url' => $request->waze_link,
        ];
    }

    private function priorityScore(string $priority, string $requestType): int
    {
        $scores = [
            'low' => 25,
            'medium' => 50,
            'high' => 75,
            'emergency' => 95,
        ];

        $score = $scores[$priority] ?? 50;

        if ($requestType === 'emergency') {
            $score = max($score, 95);
        }

        return $score;
    }

    private function wazeLink(array $payload): string
    {
        if (! empty($payload['latitude']) && ! empty($payload['longitude'])) {
            return sprintf(
                'https://waze.com/ul?ll=%s,%s&navigate=yes',
                $payload['latitude'],
                $payload['longitude'],
            );
        }

        $address = collect([
            $payload['address'] ?? null,
            $payload['city'] ?? null,
            $payload['state'] ?? null,
            $payload['zip_code'] ?? null,
        ])->filter()->implode(', ');

        return 'https://waze.com/ul?q='.urlencode($address).'&navigate=yes';
    }

    private function nextQueueReference(): string
    {
        $nextId = (int) ServiceRequest::query()->max('id') + 1;

        return 'SR-'.Str::padLeft((string) $nextId, 6, '0');
    }

    private function timeSlotLabel(string $timeSlot): string
    {
        return [
            'morning_8_12' => 'Morning',
            'afternoon_12_4' => 'Afternoon',
            'evening_4_8' => 'Evening',
            'anytime' => 'Anytime',
        ][$timeSlot] ?? 'Anytime';
    }

    private function userFromBearerToken(Request $request): ?User
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return null;
        }

        $token = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', $plainTextToken))
            ->first();

        return $token ? User::query()->find($token->user_id) : null;
    }
}
