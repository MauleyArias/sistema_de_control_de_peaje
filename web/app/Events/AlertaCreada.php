<?php

namespace App\Events;

use App\Models\Alerta;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class AlertaCreada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Alerta $alerta) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('panel')];
    }

    public function broadcastAs(): string
    {
        return 'alerta.creada';
    }

    public function broadcastWith(): array
    {
        return ['alerta' => $this->alerta->paraPanel()];
    }
}
