<?php

namespace App\Http\Requests\SystemUpdate;

use Illuminate\Foundation\Http\FormRequest;

class StoreSystemUpdateRequest extends FormRequest
{
    /**
     * Authorization is enforced upstream by the admin guard + SETTING_SYSTEM_EDIT
     * check in the controller. Keep this true to let validation run.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'package' => [
                'required',
                'file',
                'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
                'max:102400', // 100 MB
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'package' => __('labels.update_zip_file'),
        ];
    }
}
