<?php

namespace App\Http\Requests\FeaturedSection;

use Illuminate\Foundation\Http\FormRequest;

class ValidateProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'sort' => 'string|nullable',
            'latitude' => 'sometimes|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'sometimes|required_with:latitude|numeric|between:-180,180',
            'categories' => 'sometimes|string|nullable',
            'brands' => 'sometimes|string|nullable',
            'attribute_values' => 'sometimes|string|nullable',
        ];
    }
}
