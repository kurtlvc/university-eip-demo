<?php

namespace App;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sender-side endpoint for the EnrollmentRequestChannel (Point-to-Point Channel).
 *
 * Publishes directly to a named, durable queue using RabbitMQ's default
 * exchange. Whichever Registrar consumer instance is listening will pick up
 * the message - and only one instance will ever process a given request.
 */
class RabbitMQPublisher
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $queue = 'enrollment.requests';

    public function __construct()
    {
        $this->host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
        $this->port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $this->user = getenv('RABBITMQ_USER') ?: 'guest';
        $this->pass = getenv('RABBITMQ_PASS') ?: 'guest';
    }

    public function publishEnrollmentRequest(array $payload): void
    {
        $connection = new AMQPStreamConnection($this->host, $this->port, $this->user, $this->pass);
        $channel = $connection->channel();

        // Declare the queue (idempotent - safe to call on every publish).
        // durable=true means the queue survives a broker restart.
        $channel->queue_declare($this->queue, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            [
                'content_type'  => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]
        );

        // Publish to the default exchange ('') with routing key = queue name.
        // This is the simplest form of a Point-to-Point Channel in RabbitMQ.
        $channel->basic_publish($message, '', $this->queue);

        $channel->close();
        $connection->close();
    }
}
