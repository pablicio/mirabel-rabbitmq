<?php

use Pablicio\MirabelRabbitmq\RabbitMQHelpers;

class RabbitMQHelpersTest
{
  use RabbitMQHelpers;
}

it('stores a user book', function () {
  $stub = $this->createStub(RabbitMQHelpersTest::class);

  $stub->method('reject')
      ->willReturn('xpto-message');

  expect($stub->reject('message'))->toEqual('xpto-message');
});
