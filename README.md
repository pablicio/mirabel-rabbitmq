
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

class OrderReceivedWorker
{
  use RabbitMQWorkersConnection;

  const QUEUE = 'my-service.request-test',
    routing_keys = [
      'my-service.request-orders.received'
    ],
    options = [
      'exchange_type' => 'topic'
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

### How to call the subscriber
```php 
  (new App\Workers\OrderReceivedWorker)->subscribe();
```

#### **Functions**
| Worker Functions   | Description  | Return   |
| :----------------  | :------:     | -------- |
| work($msg)         |   Function performed by the callback to process the messages | null              |
| ack($msg)          |   Accept message and remove from queue  | 'ack'    |
| nack($msg)         |   When there is an error, it sends the message to the retry queue, when the attempts are over, it sends it to the error queue | 'nack'   |
| reject($msg)       |   Reject the message | 'reject' |

#### **options** params
| Param                       | Required | Default       | Type    |
| :----------------           | :------: | :----:        | ----:   |
| exchange_type               |   No     | 'topic'       | String  |
| exchange_passive            |   No     | false         | Boolean |
| exchange_durable            |   No     | true          | Boolean |
| exchange_auto_delete        |   No     | false         | Boolean |
| exchange_internal           |   No     | false         | Boolean |
| exchange_no_wait            |   No     | false         | Boolean |
| exchange_arguments          |   No     | []            | Array   |
| exchange_ticket             |   No     | null          | Object  |
| queue_passive               |   No     | false         | Boolean |
| queue_durable               |   No     | true          | Boolean |
| queue_exclusive             |   No     | false         | Boolean |
| queue_auto_delete           |   No     | false         | Boolean |
| queue_nowait                |   No     | false         | Boolean |
| qos_prefetch_size           |   No     | 0             | Integer |
| qos_prefetch_count          |   No     | 1             | Integer |
| qos_a_global                |   No     | null          | Boolean |
| consume_consumer_tag        |   No     | ''            | String  |
| consume_no_local            |   No     | false         | Boolean |
| consume_no_ack              |   No     | false         | Boolean |
| consume_exclusive           |   No     | false         | Boolean |
| consume_nowait              |   No     | false         | Boolean |
| consume_ticket              |   No     | false         | Object  |
| x-dead-letter-exchange      |   No     | ''            | String  |
| x-dead-letter-routing-key   |   No     | $retryQueue   | String  |

###### The options array is required to declare. case [], we will assume the settings of .env

#### **retry_options** params

| Param                       | Required | Default | Type    |
| :----------------           | :------: | :----:  | ----:   |
| retry_exchange_type         |   No     | 'topic' | String  |
| retry_exchange_passive      |   No     | false   | Boolean |
| retry_exchange_durable      |   No     | true    | Boolean |
| retry_exchange_auto_delete  |   No     | false   | Boolean |
| retry_exchange_internal     |   No     | false   | Boolean |
| retry_exchange_no_wait      |   No     | false   | Boolean |
| retry_exchange_arguments    |   No     | []      | Array   |
| retry_exchange_ticket       |   No     | null    | Object  |
| retry_queue_passive         |   No     | false   | Boolean |
| retry_queue_durable         |   No     | true    | Boolean |
| retry_queue_exclusive       |   No     | false   | Boolean |
| retry_queue_auto_delete     |   No     | false   | Boolean |
| retry_queue_nowait          |   No     | false   | Boolean |
| x-dead-letter-exchange      |   No     | ''      | String  |
| x-dead-letter-routing-key   |   No     | $queue  | String  |
| x-message-ttl               |   No     | 0       | Integer |
| max-attempts                |   No     | 1       | Integer |

###### If you pass the options array empty, we assume the .env settings, if you don't want to use retry, just remove the retry_options array.

## Attention!
This library is still under development, I will come back to it very soon to evolve, but I need the community's help for this, if you want to make PRs feel like owner, my contact email is pabliciotjg@gmail.com

## Todo
  - Add observability attributes as parameters in the publish() method such as traker_id, etc.
  - Become agnostic to other frameworks
  - Add unit tests
  - Improve the documentation
