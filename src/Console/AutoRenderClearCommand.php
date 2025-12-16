<?php

namespace Connecttech\AutoRenderModels\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Config\Repository;

class AutoRenderClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auto-render:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all generated models';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files, Repository $config)
    {
        if (!$this->confirm('This will delete ALL files in your configured models directory. Are you sure?')) {
            return;
        }

        // Lấy đường dẫn model từ config. Lưu ý: config package thường là 'models.path' 
        // nhưng khi dùng qua Config repository của Laravel thì là 'models.path' (nếu file config tên models.php)
        // Tuy nhiên, trong ServiceProvider ta mergeConfigFrom(..., 'models').
        
        $modelPath = $config->get('models.path'); 
        
        // Nếu path là relative (mặc định 'app/Models'), chuyển sang absolute path
        if ($modelPath && !str_starts_with($modelPath, '/') && !str_starts_with($modelPath, '\\') && !preg_match('/^[a-zA-Z]:/', $modelPath)) {
            $modelPath = base_path($modelPath);
        }

        if ($files->exists($modelPath)) {
            $files->cleanDirectory($modelPath);
            $this->info("Models directory [$modelPath] cleared successfully.");
        } else {
            $this->error("Models directory [$modelPath] does not exist.");
        }
    }
}
