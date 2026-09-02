<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VoucherPdfUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:admin' route middleware
    }

    public function rules(): array
    {
        return [
            // 'mimes:pdf' checks the actual file content/extension, not
            // just the client-supplied Content-Type header (which a
            // malicious upload could lie about) — Laravel's validator
            // inspects the real MIME type server-side.
            'pdf' => [
                'required',
                'file',
                'mimes:pdf',
                'max:' . config('voucherimport.max_file_size_kb'),
            ],
            // Which router these codes were generated on — optional (a
            // batch can still be imported without it), but required for
            // the MikroTik status-sync feature to ever work on vouchers
            // from this batch. See migration 2024_02_01_000010.
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
        ];
    }
}
