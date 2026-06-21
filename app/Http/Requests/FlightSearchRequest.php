<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlightSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'       => ['required', 'string', 'size:3'],
            'to'         => ['required', 'string', 'size:3'],
            'date'       => ['required', 'date_format:Y-m-d'],
            'passengers' => ['sometimes', 'integer', 'min:1'],

            'sort'  => ['sometimes', 'string', 'in:price,duration,departure'],
            'order' => ['sometimes', 'string', 'in:asc,desc'],

            'filter.max_price' => ['sometimes', 'numeric', 'min:0'],
            'filter.stops'     => ['sometimes', 'integer', 'min:0'],
            'filter.airline'   => ['sometimes', 'string', 'size:2'],
        ];
    }
}
