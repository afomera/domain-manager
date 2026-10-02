<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something in a user's portfolio changed. Every open tab (on any device) listening on the
 * user's private channel refreshes itself. Queued, so a stopped Reverb server never breaks a request.
 */
class PortfolioUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  ?string  $domain  the domain that changed, or null when it's the whole portfolio (a sync, clearing domains)
     * @param  bool  $removed  the domain (or, with no domain, every domain) was removed
     */
    public function __construct(
        public int $userId,
        public ?string $domain = null,
        public bool $removed = false,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'portfolio.updated';
    }

    /**
     * @return array{domain: ?string, removed: bool}
     */
    public function broadcastWith(): array
    {
        return ['domain' => $this->domain, 'removed' => $this->removed];
    }
}
