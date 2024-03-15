<?php

namespace Pablicio\MirabelRabbitmq;


use PHPUnit\Framework\TestCase;

class RabbitMQHelpersTest extends TestCase
{
  use RabbitMQHelpers;
  public function testAck()
  {
    $stub = $this->createStub($this::class);

    // Configure the stub.
    $stub->method('ack')
         ->willReturn('ack');


    $this->assertSame('ack', $stub->ack('message'));
  }

  public function testNack()
  {
    $stub = $this->createStub($this::class);

    // Configure the stub.
    $stub->method('nack')
         ->willReturn('nack');


    $this->assertSame('nack', $stub->nack('message'));
  }

  public function testReject()
  {
    $stub = $this->createStub($this::class);

    // Configure the stub.
    $stub->method('reject')
         ->willReturn('reject');


    $this->assertSame('reject', $stub->reject('message'));
  }
}