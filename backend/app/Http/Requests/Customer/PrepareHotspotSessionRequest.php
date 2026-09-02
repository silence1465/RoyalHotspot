<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class PrepareHotspotSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'login_url' => ['required', 'url:http,https', 'max:2048'],
            'mac_address' => ['required', 'string', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'ip_address' => ['required', 'ip'],
        ];
    }
}
