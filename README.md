
![WhatsApp Image 2023-04-03 at 15 09 14](https://user-images.githubusercontent.com/19760320/229592412-a12e1408-6edc-458f-bff3-5935400cb921.jpeg)

# mirabel-rabbitmq
## Library to facilitate the use of rabbitmq within php based on the php-amqplib library, bringing an abstraction of its use to make it simpler.

##
# Installing

```
composer require pablicio/mirabel-rabbitmq
```

## How to configure as a Laravel user
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
      'host' => env('RABBITMQ_HOST', '192.168.33.12'),
      'port' => env('RABBITMQ_PORT', 5672),
      'user' => env('RABBITMQ_USER', 'guest'),
      'password' => env('RABBITMQ_PASSWORD', 'guest'),
      'exchange' => env('RABBITMQ_EXCHANGE', 'my-exchange'),
      'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'direct')
    ],
  ]
];
```

#### Users of other frameworks will have to create the config/mirabel_rabbitmq.php folder and files manually in the root of their projects and then follow the configuration mentioned above.
* The env() helper may vary in other frameworks

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

  const QUEUE = 'my-service.request-orders',
    routing_keys = [
      'my-service.request-orders.received'
    ],
    arguments = [
      'ttl' => 2000, // in milisseconds
      'max_attempts' => 13
    ];

  public function work($msg)
  {
    try {
      print_r($msg->body);

      return $this->ack($msg);
    } catch (\Exception $e) {

      return $this->nack($msg);
    }
  }
}

```

### How to call the subscriber

```php 
  (new App\Workers\OrderReceivedWorker)->consume();
```
