<?php

namespace Froiden\LaravelInstaller\Controllers;

use Illuminate\Routing\Controller;
use Froiden\LaravelInstaller\Helpers\InstalledFileManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class FinalController extends Controller
{
    /**
     * Update installed file and display finished view.
     *
     * @param InstalledFileManager $fileManager
     * @return \Illuminate\View\View
     */
    public function finish(InstalledFileManager $fileManager, Request $request)
    {
        $user = User::where('access_panel', 'admin')->first();
        $details = $request->only(['name', 'email', 'mobile', 'password']);

        try {
            Artisan::call('storage:link');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        // After HTML is sent: switch session driver without restarting serve before this response finishes.
        app()->terminating(function () {
            $envPath = base_path('.env');
            if (! is_file($envPath)) {
                return;
            }
            $envContent = file_get_contents($envPath);
            $envContent = preg_replace('/SESSION_DRIVER=.*/', 'SESSION_DRIVER=database', $envContent);
            file_put_contents($envPath, $envContent);
        });

        // Mark installed only after work above — creating storage/installed too early caused
        // parallel requests to /install/final to hit canInstall→404 and nginx "upstream closed".
        $fileManager->update();

        return view('vendor.installer.finished', compact('user', 'details'));
    }
}
