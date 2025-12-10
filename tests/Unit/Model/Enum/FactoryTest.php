<?php

namespace Connecttech\AutoRenderModels\Tests\Unit\Model\Enum;

use Connecttech\AutoRenderModels\Meta\Blueprint;
use Connecttech\AutoRenderModels\Meta\Schema;
use Connecttech\AutoRenderModels\Meta\SchemaManager;
use Connecttech\AutoRenderModels\Model\Config;
use Connecttech\AutoRenderModels\Model\Enum\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Fluent;
use Mockery;
use Orchestra\Testbench\TestCase;

class FactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_generates_enum_classes_from_schema()
    {
        // 1. Mock Dependencies
        $schemaManager = Mockery::mock(SchemaManager::class);
        $config = Mockery::mock(Config::class);
        $files = Mockery::mock(Filesystem::class);

        // 2. Setup Config
        $config->shouldReceive('get')->with(null, 'enums.enabled', false)->andReturn(true);
        $config->shouldReceive('get')->with(null, 'enums.namespace', 'App\Enums')->andReturn('App\Enums');
        $config->shouldReceive('get')->with(null, 'enums.path', Mockery::any())->andReturn('/app/Enums');

        // Config for 'except' and 'only' checks (return default behavior)
        // Lưu ý: Config::get(Blueprint, 'except', [])
        $config->shouldReceive('get')->with(Mockery::type(Blueprint::class), 'except', [])->andReturn([]);
        $config->shouldReceive('get')->with(Mockery::type(Blueprint::class), 'only', [])->andReturn([]);

        // 3. Setup Schema Mock
        $blueprint = Mockery::mock(Blueprint::class);
        $blueprint->shouldReceive('table')->andReturn('users');
        
        // Mock columns
        $column1 = new Fluent(['name' => 'status', 'type' => "enum('active','inactive','pending')"]);
        $column2 = new Fluent(['name' => 'role', 'type' => 'string']); // Cột thường, ko sinh enum
        
        $blueprint->shouldReceive('columns')->andReturn([$column1, $column2]);

        $schema = Mockery::mock(Schema::class);
        $schema->shouldReceive('tables')->andReturn([$blueprint]);
        
        $schemaManager->shouldReceive('make')->with('default')->andReturn($schema);

        // 4. Expect Filesystem operations
        $files->shouldReceive('exists')->with('/app/Enums/UserStatus.php')->andReturn(false);
        $files->shouldReceive('isDirectory')->with('/app/Enums')->andReturn(true);
        
        // Assert nội dung file được ghi
        $files->shouldReceive('put')->with('/app/Enums/UserStatus.php', Mockery::on(function ($content) {
            return str_contains($content, 'enum UserStatus: string') &&
                   str_contains($content, "case ACTIVE = 'active';") &&
                   str_contains($content, "case INACTIVE = 'inactive';") &&
                   str_contains($content, "case PENDING = 'pending';");
        }))->once();

        // 5. Run Factory
        $factory = new Factory($schemaManager, $config, $files);
        $factory->generateEnums('default');
        
        // Mockery assertion happen implicitly on teardown
        $this->assertTrue(true);
    }
}
