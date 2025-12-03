<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public $dashboardData;

    /**
     * Create a new event instance.
     *
     * @param array $dashboardData
     * @return void
     */
    public function __construct($dashboardData)
    {
        $this->dashboardData = $dashboardData;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel
     */
    public function broadcastOn()
    {
        return new Channel('dashboard');  // Channel where the frontend listens
    }

    /**
     * Data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'dashboardData' => $this->dashboardData,
        ];
    }
}
