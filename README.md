
![WhatsApp Image 2023-04-03 at 15 09 14](https://user-images.githubusercontent.com/19760320/229592412-a12e1408-6edc-458f-bff3-5935400cb921.jpeg)

# mirabel-rabbitmq
## Library to facilitate the use of rabbitmq within php based on the php-amqplib library, bringing an abstraction of its use to make it simpler.

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
      'exchange_type' => env('MB_RABBITMQ_EXCHANGE_TYPE', 'topic'),
      'exchange_passive' => env('MB_RABBITMQ_EXCHANGE_PASSIVE', false),
      'exchange_durable' => env('MB_RABBITMQ_EXCHANGE_DURABLE', true),
      'exchange_auto_delete' => env('MB_RABBITMQ_EXCHANGE_DELETE', false),
      'exchange_nowait' => env('MB_RABBITMQ_EXCHANGE_NOWAIT', false),
      'exchange_arguments' => env('MB_RABBITMQ_EXCHANGE_ARGUMENTS', []),
      'exchange_ticket' => env('MB_RABBITMQ_EXCHANGE_TICKET', null)
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
(new App\Events\OrderReceivedEvent('ReceivedPayload'))->publish()
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
      'x-message-ttl' => 1000,
      'max-attempts' => 8
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
#### **options** params
| Param                       | Required | Type   |
| :----------------           | :------: | ----:  |
| exchange_type               |   No     | String |
| exchange_passive            |   No     | String |
| exchange_durable            |   No     | String |
| exchange_auto_delete        |   No     | String |
| exchange_internal           |   No     | String |
| exchange_no_wait            |   No     | String |
| exchange_arguments          |   No     | String |
| exchange_ticket             |   No     | String |
| queue_passive               |   No     | String |
| queue_durable               |   No     | String |
| queue_exclusive             |   No     | String |
| queue_auto_delete           |   No     | String |
| queue_nowait                |   No     | String |
| qos_prefetch_size           |   No     | String |
| qos_prefetch_count          |   No     | String |
| qos_a_global                |   No     | String |
| consume_consumer_tag        |   No     | String |
| consume_no_local            |   No     | String |
| consume_no_ack              |   No     | String |
| consume_exclusive           |   No     | String |
| consume_nowait              |   No     | String |
| consume_ticket              |   No     | String |
| x-dead-letter-exchange      |   No     | String |
| x-dead-letter-routing-key   |   No     | String |

###### The options array is required to declare. case [], we will assume the settings of .env

#### **retry_options** params

| Param                       | Required | Type   |
| :----------------           | :------: | ----:  |
| retry_exchange_type         |   No     | String |
| retry_exchange_passive      |   No     | String |
| retry_exchange_durable      |   No     | String |
| retry_exchange_auto_delete  |   No     | String |
| retry_exchange_internal     |   No     | String |
| retry_exchange_no_wait      |   No     | String |
| retry_exchange_arguments    |   No     | String |
| retry_exchange_ticket       |   No     | String |
| retry_queue_passive         |   No     | String |
| retry_queue_durable         |   No     | String |
| retry_queue_exclusive       |   No     | String |
| retry_queue_auto_delete     |   No     | String |
| retry_queue_nowait          |   No     | String |
| x-dead-letter-exchange      |   No     | String |
| x-dead-letter-routing-key   |   No     | String |
| x-message-ttl               |   No     | String |
| max-attempts                |   No     | String |

###### If you pass the options array empty, we assume the .env settings, if you don't want to use retry, just remove the retry_options array.

### How to call the subscriber
```php 
  (new App\Workers\OrderReceivedWorker)->subscribe();
```
## Todo
 - Become agnostic to other frameworks
 - Add unit tests
 - Improve the documentation
