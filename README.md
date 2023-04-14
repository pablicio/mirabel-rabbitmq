
![WhatsApp Image 2023-04-03 at 15 09 14](https://user-images.githubusercontent.com/19760320/229592412-a12e1408-6edc-458f-bff3-5935400cb921.jpeg)

# mirabel-rabbitmq
## Library to facilitate the use of rabbitmq within Laravel based on the php-amqplib library, bringing an abstraction of its use to make it simpler.

##
# Installing

```
composer require pablicio/mirabel-rabbitmq
```

## How to configure in Laravel
#### Run the publisher and it will create the file in config/mirabel_rabbitmq.php
```
php artisan vendor:publish --provider="Pablicio\MirabelRabbitmq\MirabelRabbitmqServiceProvider"
```

Then just configure according to your environment.

```php

<?php

return [
  'connections' => [
    'rabbitmq-php' => [
      'host' => env('MB_RABBITMQ_HOST', 'localhost'),
      'port' => env('MB_RABBITMQ_PORT', 5672),
      'user' => env('MB_RABBITMQ_USER', 'guest'),
      'password' => env('MB_RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('MB_RABBITMQ_EXCHANGE', 'my-exchange'),
      'exchange_type' => env('MB_RABBITMQ_EXCHANGE_TYPE', 'direct')
    ],
  ]
];
```

## Usage examples

### Creating a publisher class
```php

<?php

namespace App\Events;

use Pablicio\MirabelRabbitmq\RabbitMQEventsConnection;

class OrderReceivedEvent
{
  use RabbitMQEventsConnection;

  const ROUTING_KEY = 'my-service.request-orders.received';

  function __construct($payload)
  {
    $this->routingKey = self::ROUTING_KEY;
    $this->payload = $payload;
  }
}

```

### How to call the publisher

```php 
(new App\Events\OrderReceivedEvent('Received'))->publish()
```

### Creating a listener class
```php

<?php

namespace App\Workers;

use Pablicio\MirabelRabbitmq\RabbitMQWorkersConnection;

class OrderTestWorker
{
  use RabbitMQWorkersConnection;

  const QUEUE = 'my-service.request-test',
    routing_keys = [
      'my-service.request-orders.received'
    ],
    options = [
      'type' => 'topic'
    ],
    retry_options = [
      'active' => true,
      'x-message-ttl' => 1000,
      'max_attempts' => 8
    ];

  public function work($msg)
  {
    try {
      print_r('OrderTest Say: ' . $msg->body . "\n");

      return $this->ack($msg);
    } catch (\Exception $e) {
      print_r($e->getMessage() . "\n");

      return $this->nack($msg);
    }
  }
}

```


#### if you pass the options array empty, we assume the .env settings, if you don't want to use retry, just remove the retry_options array.

### How to call the subscriber

```php 
  (new App\Workers\OrderReceivedWorker)->subscribe();
```
