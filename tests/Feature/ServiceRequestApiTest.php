<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_request_can_be_created_with_waze_coordinate_link(): void
    {
        $response = $this->postJson('/api/service-requests', [
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '817-555-1212',
            'system_type' => 'AC Repair',
            'brand_preference' => null,
            'request_type' => 'repair',
            'description' => 'Outdoor unit is not starting.',
            'priority' => 'medium',
            'preferred_date' => '2026-06-01',
            'preferred_time_slot' => 'morning_8_12',
            'address' => '123 Main Street',
            'city' => 'Crowley',
            'state' => 'Texas',
            'zip_code' => '76036',
            'latitude' => 32.5793,
            'longitude' => -97.3625,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queue_reference', 'SR-000001')
            ->assertJsonPath('data.coordinates.lat', 32.5793)
            ->assertJsonPath('data.coordinates.lng', -97.3625);

        $this->assertStringStartsWith(
            'https://waze.com/ul?ll=32.5793,-97.3625',
            $response->json('data.waze_link'),
        );
    }

    public function test_tracking_queue_returns_status_counts_and_waze_links(): void
    {
        ServiceRequest::query()->create([
            'queue_reference' => 'SR-000010',
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '817-555-1212',
            'system_type' => 'AC Repair',
            'request_type' => 'repair',
            'priority' => 'high',
            'priority_score' => 75,
            'status' => 'new',
            'preferred_date' => '2026-06-01',
            'preferred_time_slot' => 'morning_8_12',
            'address' => '123 Main Street',
            'city' => 'Crowley',
            'state' => 'Texas',
            'zip_code' => '76036',
            'description' => 'Outdoor unit is not starting.',
            'waze_link' => 'https://waze.com/ul?q=123%20Main%20Street&navigate=yes',
        ]);

        $this->getJson('/api/dispatch/queue')
            ->assertOk()
            ->assertJsonPath('statusCounts.new', 1)
            ->assertJsonPath('data.0.id', 'SR-000010')
            ->assertJsonPath('data.0.waze_navigation_url', 'https://waze.com/ul?q=123%20Main%20Street&navigate=yes');
    }

    public function test_history_returns_completed_and_canceled_requests(): void
    {
        ServiceRequest::query()->create([
            'queue_reference' => 'SR-000011',
            'customer_name' => 'Jane Customer',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '817-555-1212',
            'system_type' => 'Maintenance',
            'request_type' => 'maintenance',
            'priority' => 'low',
            'priority_score' => 25,
            'status' => 'completed',
            'preferred_date' => '2026-06-01',
            'preferred_time_slot' => 'anytime',
            'address' => '123 Main Street',
            'city' => 'Crowley',
            'description' => 'Annual service.',
            'completed_at' => now(),
        ]);

        $this->getJson('/api/service-requests/history')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', 'SR-000011')
            ->assertJsonPath('data.0.status', 'completed');
    }
}
