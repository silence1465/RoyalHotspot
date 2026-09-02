<?php

namespace App\Http\Requests\Customer;

use App\Models\InternetPackage;
use App\Models\RouterPackageProfile;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'abilities:customer' route middleware
    }

    public function rules(): array
    {
        return [
            'package_id' => ['required', 'integer', 'exists:internet_packages,id'],
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'payment_method' => ['required', 'in:paystack,momo'],
        ];
    }

    /**
     * Beyond basic existence, the package must actually be active AND have
     * a RouterOS profile mapped for the chosen router — otherwise the
     * customer pays successfully and the activation job fails
     * immediately with "no profile mapped", landing them in
     * pending_activation for no reason a customer could understand.
     *
     * NOTE: this used to also block Paystack payments on Manual-mode
     * routers, on the assumption Paystack always meant live provisioning.
     * That assumption no longer holds — payment method (Paystack/MoMo) and
     * fulfillment method (live/voucher) are independent, decided by the
     * router's connection_mode at confirmation time, not by which gateway
     * collected the money. A Paystack payment on a Manual router is
     * completely valid; it just fulfills with a voucher instead of a
     * live hotspot login. See PurchaseService::fulfill().
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('package_id')) {
                return;
            }

            $package = InternetPackage::find($this->input('package_id'));

            if ($package && $package->status !== 'active') {
                $validator->errors()->add('package_id', 'This package is no longer available.');
                return;
            }

            if ($package && $this->filled('router_id')) {
                $hasMapping = RouterPackageProfile::where('package_id', $package->id)
                    ->where('router_id', $this->input('router_id'))
                    ->exists();

                if (! $hasMapping) {
                    $validator->errors()->add('router_id', 'This package is not available at that location yet.');
                }
            }
        });
    }
}
