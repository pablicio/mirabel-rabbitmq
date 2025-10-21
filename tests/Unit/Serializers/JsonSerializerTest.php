<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use PHPUnit\Framework\TestCase;
use Pablicio\MirabelRabbitmq\Serializers\JsonSerializer;

class JsonSerializerTest extends TestCase
{
    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    public function testSerializeArray(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $result = $this->serializer->serialize($data);

        $this->assertIsString($result);
        $this->assertJson($result);
        $this->assertEquals(json_encode($data), $result);
    }

    public function testSerializeObject(): void
    {
        $data = (object) ['id' => 1, 'name' => 'Test'];
        $result = $this->serializer->serialize($data);

        $this->assertIsString($result);
        $this->assertJson($result);
    }

    public function testDeserialize(): void
    {
        $json = '{"id":1,"name":"Test"}';
        $result = $this->serializer->deserialize($json);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test', $result['name']);
    }

    public function testDeserializeInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->serializer->deserialize('invalid json');
    }

    public function testGetContentType(): void
    {
        $this->assertEquals('application/json', $this->serializer->getContentType());
    }

    public function testSerializeNull(): void
    {
        $result = $this->serializer->serialize(null);
        $this->assertEquals('null', $result);
    }

    public function testSerializeEmptyArray(): void
    {
        $result = $this->serializer->serialize([]);
        $this->assertEquals('[]', $result);
    }

    public function testSerializeNestedStructure(): void
    {
        $data = [
            'user' => [
                'id' => 1,
                'profile' => [
                    'name' => 'Test',
                    'email' => 'test@example.com',
                ],
            ],
        ];

        $result = $this->serializer->serialize($data);
        $deserialized = $this->serializer->deserialize($result);

        $this->assertEquals($data, $deserialized);
    }
}
