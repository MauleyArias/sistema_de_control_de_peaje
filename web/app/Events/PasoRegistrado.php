<?php

namespace App\Events;

use App\Models\Paso;
use App\Services\PeajeService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PasoRegistrado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Paso $paso) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('panel')];
    }

    public function broadcastAs(): string
    {
        return 'paso.registrado';
    }

    public function broadcastWith(): array
    {
        return ['paso' => $this->paso->paraPanel(), 'resumen' => PeajeService::resumenDia()];
    }
}
