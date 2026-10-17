<?php

namespace App\Http\Requests;

use App\Services\Sales\RegisteredDevices;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_replace(RegisteredDevices::rules(), [
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
            'app_version' => ['sometimes', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'],
        ]);
    }
}
