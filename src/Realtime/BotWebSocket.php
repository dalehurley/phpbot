<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Realtime;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class BotWebSocket implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array{run_id?: string}> */
    private \SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn, []);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string) $msg, true);
        if (!is_array($data)) {
            return;
        }

        if (($data['type'] ?? '') === 'subscribe') {
            $runId = preg_replace('/[^a-f0-9\-]/i', '', (string) ($data['run_id'] ?? ''));
            if ($runId !== '') {
                $this->clients[$from] = ['run_id' => $runId];
                $from->send(json_encode([
                    'type' => 'subscribed',
                    'run_id' => $runId,
                ]));
            }
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    public function broadcast(string $runId, array $payload): void
    {
        $message = json_encode($payload);
        foreach ($this->clients as $client) {
            $meta = $this->clients[$client];
            $subscribed = $meta['run_id'] ?? null;
            if ($subscribed !== $runId) {
                continue;
            }
            $client->send($message);
        }
    }

    public function handleUdpMessage(string $message): void
    {
        $data = json_decode($message, true);
        if (!is_array($data)) {
            return;
        }
        $runId = preg_replace('/[^a-f0-9\-]/i', '', (string) ($data['run_id'] ?? ''));
        if ($runId === '') {
            return;
        }
        $this->broadcast($runId, $data);
    }
}
