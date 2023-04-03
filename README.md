# mirabel-rabbitmq
## Library to facilitate the use of rabbitmq within php based on the php-amqplib library, bringing an abstraction of its use to make it simpler.

##
# Installing
```
composer require pablicio/mirabel-rabbitmq
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

class OrderReceivedWorker
{
  use RabbitMQWorkersConnection;

  const QUEUE = 'my-service.request-received';
  
  const ROUTING_KEYS = [
    'my-service.request-orders.received'
  ];

  function __construct()
  {
    $this->queue = self::QUEUE;
    $this->routingKeys = self::ROUTING_KEYS;
  }

  public function work($payload)
  {
    echo "Processed Orker Say: $payload", "\n";
  }
}
```

### How to call the subscriber

```php 
  (new OrderReceivedWorker)->consume();
```
