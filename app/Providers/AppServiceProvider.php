<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set TCPDF constants BEFORE any TCPDF class is loaded
        if (!defined('K_PATH_MAIN')) {
            define('K_PATH_MAIN', base_path('vendor/tecnickcom/tcpdf').'/');
        }
        
        // Tell TCPDF where to read/write font files
        if (!defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', storage_path('tcpdf-fonts').'/');
        }
        
        // Set cache directory (TCPDF uses this during construction)
        if (!defined('K_PATH_CACHE')) {
            define('K_PATH_CACHE', storage_path('framework/cache/tcpdf').'/');
        }
        
        // Set URL (empty for file-based generation)
        if (!defined('K_PATH_URL')) {
            define('K_PATH_URL', '');
        }
        
        // Ensure directories exist
        File::ensureDirectoryExists(storage_path('tcpdf-fonts'));
        File::ensureDirectoryExists(storage_path('framework/cache/tcpdf'));

        // If OCRB isn't registered yet, add it from resources/fonts
        $fontPhp = storage_path('tcpdf-fonts/ocrb.php'); // what TCPDF will create
        $ttf = resource_path('fonts/OCRB.ttf');

        if (is_readable($ttf) && !file_exists($fontPhp)) {
            // 32 = compress zlib; you can use 0 if zlib not available
            \TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32);
        }
    }
}
